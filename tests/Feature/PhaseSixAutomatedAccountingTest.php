<?php

namespace Tests\Feature;

use App\Models\AccountingFramework;
use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBatch;
use App\Models\AccountingPostingRule;
use App\Models\AccountingSourcePosting;
use App\Models\Budget;
use App\Models\ChargeCategory;
use App\Models\Contact;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Residence;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingConfigurationService;
use App\Services\AccountingPostingConfigurationService;
use App\Services\AccountingReportService;
use App\Services\AutomatedAccountingPostingService;
use App\Services\BudgetService;
use App\Services\CreditNoteWorkflow;
use App\Services\FundCallWorkflow;
use App\Services\OpeningBalanceService;
use App\Services\PaymentWorkflow;
use App\Services\SupplierInvoiceWorkflow;
use App\Services\SupplierSettlementWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PhaseSixAutomatedAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_is_explicit_prospective_and_requires_reviewed_rules_and_mappings(): void
    {
        $c = $this->context();
        $this->rule($c, 'PAYMENT-RECEIPT', 'payment', 'received', 'financial_account', 'payment_split', 'BQ');

        $notReady = app(AccountingAutomationService::class)->readiness($c['book'], '2026-03-01');
        $this->assertFalse($notReady['ready']);
        $this->assertContains('book_professional_review_missing', $notReady['issues']);

        $this->approveBookAndMapPayment($c);
        $ready = app(AccountingAutomationService::class)->readiness($c['book'], '2026-03-01');
        $this->assertTrue($ready['ready']);
        $activation = app(AccountingAutomationService::class)->activate($c['book'], '2026-03-01', $c['user']);
        $this->assertSame('active', $activation->status);

        $historical = $this->payment($c, 1000, '2026-02-20');
        $historical->update(['status' => 'validated', 'number' => 'PAY-HIST']);
        $this->assertNull(app(AutomatedAccountingPostingService::class)->postPayment($historical, $c['user']));
        $this->assertDatabaseCount('accounting_source_postings', 0);
    }

    public function test_activation_rejects_a_concurrently_finalized_source_that_would_escape_posting(): void
    {
        $c = $this->context();
        $this->rule($c, 'PAYMENT-RECEIPT', 'payment', 'received', 'financial_account', 'payment_split', 'BQ');
        $this->approveBookAndMapPayment($c);
        $payment = $this->payment($c, 1500, '2026-03-10');
        $payment->update(['status' => 'validated', 'validated_at' => now(), 'validated_by' => $c['user']->id]);

        try {
            app(AccountingAutomationService::class)->activate($c['book'], '2026-03-01', $c['user']);
            $this->fail('Activation must fail closed when an in-scope finalized source has no posting.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'finalized_source_without_posting:payment',
                $exception->errors()['readiness'] ?? [],
            );
        }
        $this->assertDatabaseMissing('accounting_automations', [
            'accounting_book_id' => $c['book']->id,
            'status' => 'active',
        ]);
    }

    public function test_payment_receipt_posts_once_and_splits_unallocated_credit_without_reposting_cash(): void
    {
        Storage::fake('local');
        $c = $this->activatedPaymentContext();
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 5050), $c['user']);
        app(PaymentWorkflow::class)->validate($payment, $c['user']);

        $registry = AccountingSourcePosting::firstOrFail();
        $entry = $registry->entry()->with('lines')->firstOrFail();
        $this->assertSame('posted', $registry->status);
        $this->assertSame(5050, $entry->lines->sum('debit_minor'));
        $this->assertSame(5050, $entry->lines->sum('credit_minor'));
        $this->assertSame(1, AccountingSourcePosting::count());
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'payment')->count());
    }

    public function test_activated_source_fails_closed_when_a_required_mapping_becomes_invalid(): void
    {
        Storage::fake('local');
        $c = $this->activatedPaymentContext();
        $bankLedger = $c['book']->accounts()->where('financial_account_id', $c['financialAccount']->id)->firstOrFail();
        $bankLedger->update(['active' => false]);
        $payment = $this->payment($c, 2500);

        try {
            app(PaymentWorkflow::class)->validate($payment, $c['user']);
            $this->fail('Posting should fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('accounting_mapping', $exception->errors());
        }
        $this->assertSame('draft', $payment->fresh()->status);
        $this->assertDatabaseMissing('financial_account_movements', ['payment_id' => $payment->id]);
        $this->assertDatabaseCount('accounting_source_postings', 0);
    }

    public function test_later_credit_allocation_posts_only_the_allocation_amount(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 3000), $c['user']);
        $this->rule($c, 'PAYMENT-RECEIPT', 'payment', 'received', 'financial_account', 'payment_split', 'BQ');
        $this->rule($c, 'PAYMENT-CREDIT', 'payment_allocation', 'credit_applied', 'advance_control', 'receivable_control', 'AC');
        $this->approveBookAndMapPayment($c);
        app(AccountingAutomationService::class)->activate($c['book'], '2026-03-01', $c['user']);

        $payment = $this->payment($c, 5000, '2026-03-10', $c['owner']->id);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user'], 'selected_lots', []);
        app(PaymentWorkflow::class)->allocateCredit($payment, $c['user'], [
            ['lot_charge_id' => $call->charges()->first()->id, 'amount_cents' => 1200],
        ]);
        app(PaymentWorkflow::class)->validate($payment->fresh(), $c['user']);

        $allocationRegistry = AccountingSourcePosting::where('source_type', 'payment_allocation')->firstOrFail();
        $this->assertSame(1200, (int) $allocationRegistry->entry->lines()->sum('debit_minor'));
        $this->assertSame(1200, (int) $allocationRegistry->entry->lines()->sum('credit_minor'));
        $this->assertSame(3800, $payment->fresh()->credit_cents);
        $this->assertSame(2, AccountingSourcePosting::count());
    }

    public function test_fund_call_posts_once_at_call_level_and_not_once_per_materialized_charge(): void
    {
        $c = $this->context();
        $this->rule($c, 'FUND-CALL', 'fund_call', 'validated', 'receivable_control', 'charge_category', 'AC');
        $this->approveBook($c);
        $this->map($c, 'receivable_control', 0, '3421');
        $this->map($c, 'charge_category', $c['chargeCategory']->id, '7111');
        app(AccountingAutomationService::class)->activate($c['book'], '2026-01-01', $c['user']);

        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 4321), $c['user']);
        $this->assertSame(1, AccountingSourcePosting::where('source_type', 'fund_call')->count());
        $this->assertSame(1, $call->charges()->count());
        $this->assertSame(4321, (int) AccountingSourcePosting::first()->entry->lines()->sum('debit_minor'));
    }

    public function test_receivables_report_reconciles_operational_owner_aging_to_posted_accounting(): void
    {
        $c = $this->context();
        $this->rule($c, 'FUND-CALL', 'fund_call', 'validated', 'receivable_control', 'charge_category', 'AC');
        $this->approveBook($c);
        $this->map($c, 'receivable_control', 0, '3421');
        $this->map($c, 'charge_category', $c['chargeCategory']->id, '7111');
        app(AccountingAutomationService::class)->activate($c['book'], '2026-01-01', $c['user']);
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 4321), $c['user']);

        $report = app(AccountingReportService::class)->generate($c['book'], $c['exercise'], [
            'report' => 'receivables',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'as_of' => '2026-07-01',
            'owner_contact_id' => $c['owner']->id,
            'aging' => '>90',
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame($c['owner']->id, $report['rows'][0]['owner_contact_id']);
        $this->assertSame('>90', $report['rows'][0]['aging']);
        $this->assertSame(4321, $report['rows'][0]['operational_outstanding_minor']);
        $this->assertSame(0, $report['rows'][0]['difference_minor']);
        $this->assertSame('ok', $report['rows'][0]['reconciliation_status']);
    }

    public function test_supplier_invoice_posts_expense_by_category_and_payable_without_touching_checksum(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $this->rule($c, 'SUPPLIER-INVOICE', 'supplier_invoice', 'validated', 'expense_category', 'supplier_payable', 'HA');
        $this->approveBook($c);
        $this->map($c, 'expense_category', $c['expenseCategory']->id, '6131');
        $this->map($c, 'supplier_payable', 0, '4411');
        app(AccountingAutomationService::class)->activate($c['book'], '2026-01-01', $c['user']);
        $invoice = $this->invoice($c, 7890);
        $checksum = $invoice->attachments()->firstOrFail()->checksum;

        $invoice = app(SupplierInvoiceWorkflow::class)->validate($invoice, $c['user']);

        $registry = AccountingSourcePosting::where('source_type', 'supplier_invoice')->firstOrFail();
        $this->assertSame(7890, (int) $registry->entry->lines()->sum('debit_minor'));
        $this->assertSame(7890, (int) $registry->entry->lines()->sum('credit_minor'));
        $this->assertSame($checksum, $invoice->attachments()->firstOrFail()->checksum);
    }

    public function test_supplier_aging_report_exposes_operational_accounting_differences_and_supplier_filter(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $this->rule($c, 'SUPPLIER-INVOICE', 'supplier_invoice', 'validated', 'expense_category', 'supplier_payable', 'HA');
        $this->approveBook($c);
        $this->map($c, 'expense_category', $c['expenseCategory']->id, '6131');
        $this->map($c, 'supplier_payable', 0, '4411');
        app(AccountingAutomationService::class)->activate($c['book'], '2026-01-01', $c['user']);
        app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c, 7890), $c['user']);

        $report = app(AccountingReportService::class)->generate($c['book'], $c['exercise'], [
            'report' => 'payables',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'as_of' => '2026-07-01',
            'supplier_id' => $c['supplier']->id,
            'aging' => '>90',
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame($c['supplier']->id, $report['rows'][0]['supplier_id']);
        $this->assertSame('Atlas Services', $report['rows'][0]['supplier_name']);
        $this->assertSame('>90', $report['rows'][0]['aging']);
        $this->assertSame(7890, $report['rows'][0]['accounting_outstanding_minor']);
        $this->assertSame(0, $report['rows'][0]['difference_minor']);
        $this->assertSame('ok', $report['rows'][0]['reconciliation_status']);
    }

    public function test_opening_balance_requires_review_balances_exactly_and_becomes_immutable(): void
    {
        $c = $this->context();
        $journal = AccountingJournal::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'accounting_book_id' => $c['book']->id,
            'code' => 'OU',
            'label_fr' => 'Ouverture',
            'label_ar' => 'افتتاح',
            'type' => 'opening',
            'effective_from' => '2026-01-01',
            'created_by' => $c['user']->id,
            'updated_by' => $c['user']->id,
        ]);
        $accounts = $c['book']->accounts()->where('posting_allowed', true)->take(2)->get();
        $batch = AccountingOpeningBatch::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'accounting_book_id' => $c['book']->id,
            'financial_exercise_id' => $c['exercise']->id,
            'accounting_journal_id' => $journal->id,
            'opening_date' => '2026-01-01',
            'reference' => 'OPEN-2026',
            'supporting_document_reference' => 'DOSSIER-REVU-01',
            'status' => 'draft',
            'created_by' => $c['user']->id,
        ]);
        $batch->lines()->create(['sequence' => 1, 'ledger_account_id' => $accounts[0]->id, 'label' => 'Débit initial', 'debit_minor' => 10000, 'credit_minor' => 0]);
        $batch->lines()->create(['sequence' => 2, 'ledger_account_id' => $accounts[1]->id, 'label' => 'Crédit initial', 'debit_minor' => 0, 'credit_minor' => 10000]);

        app(OpeningBalanceService::class)->review($batch, $c['user']);
        $posted = app(OpeningBalanceService::class)->post($batch->fresh(), $c['user']);
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->journal_entry_id);
        $this->expectException(LogicException::class);
        $posted->update(['reference' => 'ALTERED']);
    }

    public function test_supplier_settlement_reduces_payable_once_and_controlled_reversal_reverses_the_entry(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c, 9000), $c['user']);
        $this->rule($c, 'SUPPLIER-SETTLEMENT', 'supplier_settlement', 'validated', 'supplier_payable', 'financial_account', 'BQ');
        $this->approveBook($c);
        $bank = $c['book']->accounts()->where('code', '5121')->firstOrFail();
        $bank->update(['financial_account_id' => $c['financialAccount']->id]);
        $this->map($c, 'financial_account', $c['financialAccount']->id, '5121');
        $this->map($c, 'supplier_payable', 0, '4411');
        app(AccountingAutomationService::class)->activate($c['book'], '2026-04-01', $c['user']);
        $settlement = SupplierSettlement::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'financial_exercise_id' => $c['exercise']->id,
            'supplier_id' => $c['supplier']->id,
            'financial_account_id' => $c['financialAccount']->id,
            'settlement_date' => '2026-04-01',
            'amount_cents' => 4000,
            'method' => 'bank_transfer',
        ]);

        $settlement = app(SupplierSettlementWorkflow::class)->validate($settlement, $c['user']);
        $registry = AccountingSourcePosting::where('source_type', 'supplier_settlement')->firstOrFail();
        $this->assertSame(4000, (int) $registry->entry->lines()->sum('debit_minor'));
        $this->assertSame(4000, $invoice->fresh()->paid_cents);

        app(SupplierSettlementWorkflow::class)->reverse($settlement, $c['user'], 'Virement rejeté');
        $this->assertSame('reversed', $registry->fresh()->status);
        $this->assertNotNull($registry->fresh()->reversal_entry_id);
        $this->assertSame(0, $invoice->fresh()->paid_cents);
    }

    public function test_posting_rules_and_approved_mappings_are_immutable_and_audit_is_machine_readable(): void
    {
        Storage::fake('local');
        $c = $this->activatedPaymentContext();
        app(PaymentWorkflow::class)->validate($this->payment($c, 1000), $c['user']);
        $this->assertSame(0, Artisan::call('evosyndic:audit-source-postings', [
            '--organization' => $c['organization']->id,
            '--residence' => $c['residence']->id,
            '--json' => true,
        ]));
        $this->assertStringContainsString('"ok": true', Artisan::output());

        $rule = AccountingPostingRule::firstOrFail();
        try {
            $rule->update(['version' => 'mutated']);
            $this->fail('Active rule should be immutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(LogicException::class);
        $c['book']->sourceMappings()->firstOrFail()->update(['source_id' => 999]);
    }

    public function test_supplier_credit_note_posts_payable_against_expense_and_reverses_with_domain_cancellation(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c, 6000), $c['user']);
        $this->rule($c, 'SUPPLIER-CREDIT', 'supplier_credit_note', 'validated', 'supplier_payable', 'expense_category', 'HA');
        $this->approveBook($c);
        $this->map($c, 'supplier_payable', 0, '4411');
        $this->map($c, 'expense_category', $c['expenseCategory']->id, '6131');
        app(AccountingAutomationService::class)->activate($c['book'], '2026-04-01', $c['user']);
        $credit = SupplierCreditNote::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'supplier_id' => $c['supplier']->id,
            'credit_date' => '2026-04-02',
            'amount_cents' => 2000,
            'reason' => 'Remise',
        ]);

        $credit = app(CreditNoteWorkflow::class)->validate($credit, $c['user'], [
            ['supplier_invoice_id' => $invoice->id, 'amount_cents' => 2000],
        ]);
        $registry = AccountingSourcePosting::where('source_type', 'supplier_credit_note')->firstOrFail();
        $this->assertSame(2000, (int) $registry->entry->lines()->sum('debit_minor'));
        $this->assertSame(2000, (int) $registry->entry->lines()->sum('credit_minor'));
        $this->assertSame(4000, (int) $invoice->fresh()->outstanding_cents);

        app(CreditNoteWorkflow::class)->cancel($credit, $c['user'], 'Erreur fournisseur');
        $this->assertSame('reversed', $registry->fresh()->status);
        $this->assertSame(6000, (int) $invoice->fresh()->outstanding_cents);
    }

    public function test_budget_approval_remains_planning_only_and_creates_no_source_posting(): void
    {
        $c = $this->activatedPaymentContext();
        $budget = Budget::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'financial_exercise_id' => $c['exercise']->id,
            'version' => 1,
            'title' => 'Budget sans écriture',
        ]);
        $budget->lines()->create([
            'expense_category_id' => $c['expenseCategory']->id,
            'planned_cents' => 15000,
        ]);

        app(BudgetService::class)->approve($budget, $c['user']);

        $this->assertSame('approved', $budget->fresh()->status);
        $this->assertDatabaseCount('accounting_source_postings', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $organization->users()->attach($user, ['role' => 'owner', 'all_residences' => true]);
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::factory()->create([
            'organization_id' => $organization->id,
            'residence_id' => $residence->id,
            'name' => '2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => 'open',
        ]);
        $financialAccount = FinancialAccount::factory()->create([
            'organization_id' => $organization->id,
            'residence_id' => $residence->id,
            'code' => 'BANK',
        ]);
        $chargeCategory = ChargeCategory::factory()->create([
            'organization_id' => $organization->id,
            'residence_id' => $residence->id,
            'code' => 'CALL',
            'default_distribution_method' => 'equal',
        ]);
        $expenseCategory = ExpenseCategory::create([
            'organization_id' => $organization->id,
            'residence_id' => $residence->id,
            'name' => 'Nettoyage',
            'code' => 'CLEAN',
        ]);
        $owner = Contact::factory()->for($organization)->create();
        $lot = Lot::factory()->for($residence)->create();
        $lot->ownerships()->create([
            'contact_id' => $owner->id,
            'ownership_percentage' => 100,
            'is_primary_contact' => true,
            'starts_on' => '2024-01-01',
        ]);
        $supplier = Supplier::create(['organization_id' => $organization->id, 'legal_name' => 'Atlas Services', 'preferred_language' => 'fr']);
        $framework = AccountingFramework::where('stable_code', 'MA-SYNDIC-2-23-700')->firstOrFail();
        $book = app(AccountingConfigurationService::class)->adopt($organization->id, $residence->id, $framework, 'full', '2026-01-01', $user);
        app(AccountingConfigurationService::class)->configureExercise($exercise, $book, $user);

        return compact('user', 'organization', 'residence', 'exercise', 'financialAccount', 'chargeCategory', 'expenseCategory', 'owner', 'lot', 'supplier', 'book');
    }

    private function activatedPaymentContext(): array
    {
        $c = $this->context();
        $this->rule($c, 'PAYMENT-RECEIPT', 'payment', 'received', 'financial_account', 'payment_split', 'BQ');
        $this->approveBookAndMapPayment($c);
        app(AccountingAutomationService::class)->activate($c['book'], '2026-03-01', $c['user']);

        return $c;
    }

    private function approveBookAndMapPayment(array $c): void
    {
        $this->approveBook($c);
        $bank = $c['book']->accounts()->where('code', '5121')->firstOrFail();
        $bank->update(['financial_account_id' => $c['financialAccount']->id]);
        $this->map($c, 'financial_account', $c['financialAccount']->id, '5121');
        $this->map($c, 'receivable_control', 0, '3421');
        $this->map($c, 'advance_control', 0, '4421');
    }

    private function approveBook(array $c): void
    {
        $c['book']->update(['review_status' => 'approved']);
    }

    private function map(array $c, string $type, int $sourceId, string $code): void
    {
        app(AccountingPostingConfigurationService::class)->map(
            $c['book'],
            $type,
            $sourceId,
            $c['book']->accounts()->where('code', $code)->firstOrFail(),
            '2026-01-01',
            $c['user'],
            'approved',
        );
    }

    private function rule(array $c, string $code, string $domain, string $event, string $debit, string $credit, string $journalCode): AccountingPostingRule
    {
        $rule = AccountingPostingRule::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'accounting_book_id' => $c['book']->id,
            'accounting_framework_id' => $c['book']->accounting_framework_id,
            'stable_code' => $code,
            'version' => '1.0',
            'source_domain' => $domain,
            'source_event' => $event,
            'accounting_journal_id' => $c['book']->journals()->where('code', $journalCode)->firstOrFail()->id,
            'debit_resolution' => $debit,
            'credit_resolution' => $credit,
            'effective_from' => '2026-01-01',
            'status' => 'draft',
            'professional_review_status' => 'approved',
            'created_by' => $c['user']->id,
            'reviewed_by' => $c['user']->id,
            'reviewed_at' => now(),
        ]);

        return app(AccountingPostingConfigurationService::class)->activateRule($rule, $c['user']);
    }

    private function payment(array $c, int $amount, string $date = '2026-03-10', ?int $payerId = null): Payment
    {
        return Payment::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'financial_exercise_id' => $c['exercise']->id,
            'payer_contact_id' => $payerId,
            'received_from' => $payerId ? null : 'Source non identifiée',
            'payment_date' => $date,
            'amount_cents' => $amount,
            'method' => 'bank_transfer',
            'financial_account_id' => $c['financialAccount']->id,
        ]);
    }

    private function draftCall(array $c, int $amount): FundCall
    {
        $call = FundCall::create([
            'organization_id' => $c['organization']->id,
            'residence_id' => $c['residence']->id,
            'financial_exercise_id' => $c['exercise']->id,
            'title' => 'Appel test',
            'issue_date' => '2026-02-01',
            'due_date' => '2026-02-15',
        ]);
        $call->lines()->create([
            'charge_category_id' => $c['chargeCategory']->id,
            'label' => 'Charges',
            'distribution_method' => 'equal',
            'target_type' => 'all',
            'amount_cents' => $amount,
        ]);

        return $call;
    }

    private function invoice(array $c, int $total): SupplierInvoice
    {
        $invoice = SupplierInvoice::create([
            'organization_id' => $c['organization']->id,
            'primary_residence_id' => $c['residence']->id,
            'supplier_id' => $c['supplier']->id,
            'supplier_invoice_number' => 'SUP-001',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-31',
        ]);
        $invoice->lines()->create([
            'residence_id' => $c['residence']->id,
            'financial_exercise_id' => $c['exercise']->id,
            'expense_category_id' => $c['expenseCategory']->id,
            'description' => 'Service',
            'quantity' => 1,
            'unit_price_cents' => $total,
            'tax_rate' => 0,
            'subtotal_cents' => $total,
            'tax_cents' => 0,
            'total_cents' => $total,
        ]);
        $bytes = '%PDF-1.4 source';
        Storage::disk('local')->put("invoices/{$invoice->id}.pdf", $bytes);
        $invoice->attachments()->create([
            'kind' => 'original',
            'version' => 1,
            'name' => 'invoice.pdf',
            'disk' => 'local',
            'path' => "invoices/{$invoice->id}.pdf",
            'mime_type' => 'application/pdf',
            'size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'uploaded_by' => $c['user']->id,
        ]);

        return $invoice;
    }
}
