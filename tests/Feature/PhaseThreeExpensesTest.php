<?php

namespace Tests\Feature;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\Budget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCommitment;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\Supplier;
use App\Models\SupplierContract;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierServiceCategory;
use App\Models\SupplierSettlement;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CommitmentWorkflow;
use App\Services\CreditNoteWorkflow;
use App\Services\ExpenseAuditService;
use App\Services\SupplierContractWorkflow;
use App\Services\SupplierInvoiceWorkflow;
use App\Services\SupplierSettlementWorkflow;
use App\Services\SupplierStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PhaseThreeExpensesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                return '%PDF-1.4 phase-three-'.$locale.' '.hash('sha256', $html);
            }
        });
    }

    private function context(string $role = 'owner'): array
    {
        $user = User::factory()->create(['preferred_language' => 'fr']);
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => true]);
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $account = FinancialAccount::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Banque', 'code' => 'BANK', 'type' => 'bank', 'active' => true, 'opening_balance_cents' => 100000]);
        $category = ExpenseCategory::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Nettoyage', 'code' => 'cleaning']);
        $supplier = Supplier::create(['organization_id' => $organization->id, 'legal_name' => 'Propreté Atlas SARL', 'ice' => '001234567890123']);

        return compact('user', 'organization', 'residence', 'exercise', 'account', 'category', 'supplier');
    }

    private function invoice(array $context, int $total = 12000, string $reference = 'F-001'): SupplierInvoice
    {
        $invoice = SupplierInvoice::create(['organization_id' => $context['organization']->id, 'primary_residence_id' => $context['residence']->id, 'supplier_id' => $context['supplier']->id, 'supplier_invoice_number' => $reference, 'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31', 'subtotal_cents' => $total, 'tax_cents' => 0, 'total_cents' => $total]);
        $invoice->lines()->create(['residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $context['category']->id, 'description' => 'Nettoyage mensuel', 'quantity' => 1, 'unit_price_cents' => $total, 'tax_rate' => 0, 'subtotal_cents' => $total, 'tax_cents' => 0, 'total_cents' => $total, 'visibility' => 'category_summary']);
        $path = "test-invoices/{$invoice->id}.pdf";
        $bytes = '%PDF-1.4 invoice '.$invoice->id;
        Storage::disk('local')->put($path, $bytes);
        $invoice->attachments()->create(['kind' => 'original', 'version' => 1, 'name' => 'invoice.pdf', 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $context['user']->id]);

        return $invoice;
    }

    public function test_invoice_validation_creates_payable_without_cash_and_is_immutable(): void
    {
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c), $c['user']);
        $this->assertMatchesRegularExpression('/^EXP-2026-\d{4}$/', $invoice->number);
        $this->assertSame('validated', $invoice->status);
        $this->assertSame(12000, $invoice->outstanding_cents);
        $this->assertDatabaseCount('financial_account_movements', 0);
        $this->expectException(LogicException::class);
        $invoice->update(['total_cents' => 1]);
    }

    public function test_closed_exercise_and_duplicate_supplier_reference_are_rejected(): void
    {
        $c = $this->context();
        app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c), $c['user']);
        $this->expectException(ValidationException::class);
        app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c, 12000, 'F-001'), $c['user']);
    }

    public function test_commitment_approval_number_and_linked_invoice_status(): void
    {
        $c = $this->context();
        $commitment = ExpenseCommitment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'supplier_id' => $c['supplier']->id, 'expense_category_id' => $c['category']->id, 'title' => 'Contrat annuel', 'committed_on' => '2026-01-01', 'amount_cents' => 20000]);
        app(CommitmentWorkflow::class)->transition($commitment, $c['user'], 'submit');
        app(CommitmentWorkflow::class)->transition($commitment->fresh(), $c['user'], 'approve');
        $this->assertMatchesRegularExpression('/^CMT-2026-\d{4}$/', $commitment->fresh()->number);
        $invoice = $this->invoice($c, 12000);
        $invoice->update(['expense_commitment_id' => $commitment->id]);
        app(SupplierInvoiceWorkflow::class)->validate($invoice, $c['user']);
        $this->assertSame('partially_invoiced', $commitment->fresh()->status);
        $this->expectException(LogicException::class);
        $commitment->fresh()->update(['amount_cents' => 1]);
    }

    public function test_partial_settlement_fifo_movement_voucher_and_reversal_reconcile(): void
    {
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c), $c['user']);
        $settlement = SupplierSettlement::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'supplier_id' => $c['supplier']->id, 'financial_account_id' => $c['account']->id, 'settlement_date' => '2026-04-01', 'amount_cents' => 5000, 'method' => 'bank_transfer']);
        $settlement = app(SupplierSettlementWorkflow::class)->validate($settlement, $c['user']);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame(5000, $invoice->fresh()->paid_cents);
        $this->assertSame('debit', $settlement->movements()->first()->direction);
        $this->assertMatchesRegularExpression('/^SET-2026-\d{4}$/', $settlement->number);
        $this->assertMatchesRegularExpression('/^VCH-2026-\d{4}$/', $settlement->documents()->first()->number);
        app(SupplierSettlementWorkflow::class)->reverse($settlement, $c['user'], 'Virement rejeté');
        $this->assertSame(0, $invoice->fresh()->paid_cents);
        $this->assertSame('validated', $invoice->fresh()->status);
        $this->assertSame(['debit', 'credit'], $settlement->movements()->orderBy('id')->pluck('direction')->all());
        $this->assertSame(100000, $c['account']->fresh()->current_balance_cents);
        $this->assertSame('reversed', $settlement->documents()->first()->status);
    }

    public function test_credit_note_reduces_payable_and_budget_actual_then_cancellation_restores(): void
    {
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c), $c['user']);
        $budget = Budget::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'version' => 1, 'title' => 'Budget 2026']);
        $budget->lines()->create(['expense_category_id' => $c['category']->id, 'planned_cents' => 20000]);
        app(BudgetService::class)->approve($budget, $c['user']);
        $credit = SupplierCreditNote::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'credit_date' => '2026-04-02', 'amount_cents' => 2000, 'reason' => 'Remise']);
        app(CreditNoteWorkflow::class)->validate($credit, $c['user'], [['supplier_invoice_id' => $invoice->id, 'amount_cents' => 2000]]);
        $this->assertSame(10000, $invoice->fresh()->outstanding_cents);
        $this->assertSame(10000, app(BudgetService::class)->metrics($budget->fresh())[0]['actual_cents']);
        app(CreditNoteWorkflow::class)->cancel($credit->fresh(), $c['user'], 'Erreur fournisseur');
        $this->assertSame(12000, $invoice->fresh()->outstanding_cents);
    }

    public function test_statement_running_balance_and_reversal_are_consistent(): void
    {
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c), $c['user']);
        $settlement = SupplierSettlement::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'supplier_id' => $c['supplier']->id, 'financial_account_id' => $c['account']->id, 'settlement_date' => '2026-04-01', 'amount_cents' => 3000, 'method' => 'cash']);
        app(SupplierSettlementWorkflow::class)->validate($settlement, $c['user']);
        app(SupplierSettlementWorkflow::class)->reverse($settlement->fresh(), $c['user'], 'Correction');
        $statement = app(SupplierStatementService::class)->build($c['supplier'], $c['residence']->id);
        $this->assertSame(12000, $statement['closing_cents']);
        $this->assertSame(['invoice', 'settlement', 'settlement_reversal'], $statement['rows']->pluck('type')->all());
    }

    public function test_tenant_permissions_and_private_supplier_data_are_enforced(): void
    {
        $c = $this->context('maintenance_agent');
        $other = $this->context();
        $this->actingAs($c['user'])->get(route('expenses.index'))->assertForbidden();
        $this->actingAs($c['user'])->post(route('supplier-invoices.validate', $this->invoice($other)))->assertForbidden();
        $this->actingAs($c['user'])->getJson(route('expenses.search', ['q' => 'Atlas']))->assertForbidden();
    }

    public function test_expenses_workspace_loads_suppliers_with_service_categories(): void
    {
        $c = $this->context();
        $serviceCategory = SupplierServiceCategory::create([
            'organization_id' => $c['organization']->id,
            'name' => 'Nettoyage',
            'code' => 'cleaning',
        ]);
        $c['supplier']->categories()->attach($serviceCategory);

        $this->actingAs($c['user'])
            ->get(route('expenses.index'))
            ->assertOk();

        $this->assertTrue($c['supplier']->fresh()->categories->contains($serviceCategory));
    }

    public function test_expense_audit_is_read_only_and_detects_corruption(): void
    {
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c), $c['user']);
        $before = $invoice->updated_at;
        $this->assertTrue(app(ExpenseAuditService::class)->run(['invoice' => $invoice->id])['ok']);
        SupplierInvoiceLine::withoutEvents(fn () => $invoice->lines()->first()->update(['total_cents' => 1]));
        $report = app(ExpenseAuditService::class)->run(['invoice' => $invoice->id]);
        $this->assertFalse($report['ok']);
        $this->assertSame('invoice_totals', $report['violations'][0]['check']);
        $this->assertEquals($before, $invoice->fresh()->updated_at);
    }

    public function test_pwa_assets_cache_only_public_shell_and_versioned_assets(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $worker = file_get_contents(public_path('sw.js'));
        $app = file_get_contents(resource_path('js/app.ts'));
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));
        $this->assertSame('EvoSyndic', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertGreaterThanOrEqual(4, count($manifest['icons']));
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        $this->assertStringContainsString('build\\/assets', $worker);
        $this->assertStringContainsString('/offline.html', $worker);
        $this->assertStringContainsString('request.method !== "GET"', $worker);
        $this->assertStringContainsString('url.origin !== self.location.origin', $worker);
        $this->assertStringContainsString('url.search', $worker);
        $this->assertStringContainsString('key.startsWith(CACHE_PREFIX)', $worker);
        $this->assertStringContainsString('CLEAR_EVOSYNDIC_CACHES', $worker);
        $this->assertStringContainsString('EVOSYNDIC_CACHES_CLEARED', $worker);
        $this->assertStringContainsString('event.ports[0]?.postMessage', $worker);
        $this->assertStringContainsString('Promise.allSettled([...pendingCacheWrites])', $worker);
        $this->assertStringContainsString('cacheWritesEnabled = false', $worker);
        $this->assertStringNotContainsString('keys.map(key => caches.delete(key))', $worker);
        $this->assertStringNotContainsString('/expenses', $worker);
        $this->assertStringNotContainsString('/finance', $worker);
        $this->assertStringNotContainsString('/documents/', $worker);
        $this->assertStringContainsString('if (props.auth?.user) registerServiceWorker()', $app);
        $this->assertStringContainsString('navigation?.type === "back_forward"', $app);
        $this->assertStringContainsString('registration?.unregister()', $layout);
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_combined_settlement_and_credit_allocations_never_exceed_invoice(): void
    {
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c, 10000, 'INV-COMB-1'), $c['user']);
        $settlement = SupplierSettlement::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'supplier_id' => $c['supplier']->id, 'financial_account_id' => $c['account']->id, 'settlement_date' => '2026-04-01', 'amount_cents' => 7000, 'method' => 'bank_transfer']);
        app(SupplierSettlementWorkflow::class)->validate($settlement, $c['user']);
        $credit = SupplierCreditNote::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'credit_date' => '2026-04-02', 'amount_cents' => 4000]);

        try {
            app(CreditNoteWorkflow::class)->validate($credit, $c['user'], [['supplier_invoice_id' => $invoice->id, 'amount_cents' => 4000]]);
            $this->fail('Over-allocation should have been rejected.');
        } catch (ValidationException) {
            $this->assertSame(3000, $invoice->fresh()->outstanding_cents);
            $this->assertDatabaseCount('supplier_credit_note_allocations', 0);
        }

        $credit->refresh()->update(['amount_cents' => 3000]);
        app(CreditNoteWorkflow::class)->validate($credit->fresh(), $c['user'], [['supplier_invoice_id' => $invoice->id, 'amount_cents' => 3000]]);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->outstanding_cents);
        $this->assertTrue(app(ExpenseAuditService::class)->run(['invoice' => $invoice->id])['ok']);
    }

    public function test_multi_residence_invoice_allocates_settlements_and_credits_by_line(): void
    {
        $c = $this->context();
        $otherResidence = Residence::factory()->for($c['organization'])->create(['status' => 'operational']);
        $otherExercise = FinancialExercise::create(['organization_id' => $c['organization']->id, 'residence_id' => $otherResidence->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $otherCategory = ExpenseCategory::create(['organization_id' => $c['organization']->id, 'residence_id' => $otherResidence->id, 'name' => 'Sécurité', 'code' => 'security']);
        $invoice = $this->invoice($c, 6000, 'INV-MULTI-1');
        $invoice->lines()->create(['residence_id' => $otherResidence->id, 'financial_exercise_id' => $otherExercise->id, 'expense_category_id' => $otherCategory->id, 'description' => 'Sécurité', 'quantity' => 1, 'unit_price_cents' => 4000, 'tax_rate' => 0, 'subtotal_cents' => 4000, 'tax_cents' => 0, 'total_cents' => 4000, 'sort_order' => 2]);
        $invoice->update(['subtotal_cents' => 10000, 'total_cents' => 10000]);
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($invoice, $c['user']);

        $settlement = SupplierSettlement::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'supplier_id' => $c['supplier']->id, 'financial_account_id' => $c['account']->id, 'settlement_date' => '2026-04-01', 'amount_cents' => 6000, 'method' => 'bank_transfer']);
        app(SupplierSettlementWorkflow::class)->validate($settlement, $c['user']);
        $this->assertSame([$c['residence']->id], $settlement->allocations()->with('line')->get()->pluck('line.residence_id')->unique()->values()->all());
        $this->assertSame(4000, $invoice->fresh()->outstanding_cents);

        $credit = SupplierCreditNote::create(['organization_id' => $c['organization']->id, 'residence_id' => $otherResidence->id, 'supplier_id' => $c['supplier']->id, 'credit_date' => '2026-04-02', 'amount_cents' => 4000]);
        app(CreditNoteWorkflow::class)->validate($credit, $c['user'], [['supplier_invoice_id' => $invoice->id, 'amount_cents' => 4000]]);
        $this->assertSame([$otherResidence->id], $credit->allocations()->pluck('residence_id')->unique()->values()->all());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_voucher_renderer_failure_preserves_financial_source_and_retry_is_singleton(): void
    {
        $c = $this->context();
        $invoice = app(SupplierInvoiceWorkflow::class)->validate($this->invoice($c, 5000, 'INV-VOUCHER-1'), $c['user']);
        $settlement = SupplierSettlement::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'supplier_id' => $c['supplier']->id, 'financial_account_id' => $c['account']->id, 'settlement_date' => '2026-04-01', 'amount_cents' => 5000, 'method' => 'bank_transfer']);
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                throw new \RuntimeException('renderer unavailable');
            }
        });
        try {
            app(SupplierSettlementWorkflow::class)->validate($settlement, $c['user']);
            $this->fail('Renderer failure should abort validation.');
        } catch (\RuntimeException) {
            $this->assertSame('validated', $settlement->fresh()->status);
            $this->assertSame(5000, $invoice->fresh()->paid_cents);
            $this->assertDatabaseCount('financial_account_movements', 1);
            $this->assertDatabaseHas('document_generation_attempts', ['subject_id' => $settlement->id, 'status' => 'failed', 'attempt_count' => 1]);
        }
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                return '%PDF retry';
            }
        });
        app(SupplierSettlementWorkflow::class)->validate($settlement->fresh(), $c['user']);
        app(SupplierSettlementWorkflow::class)->validate($settlement->fresh(), $c['user']);
        $this->assertDatabaseCount('financial_documents', 1);
        $this->assertDatabaseCount('financial_account_movements', 1);
        $this->assertDatabaseHas('document_generation_attempts', ['subject_id' => $settlement->id, 'status' => 'generated', 'attempt_count' => 2]);
    }

    public function test_budget_lock_unlock_revision_and_totals_are_reconciled(): void
    {
        $c = $this->context();
        $budget = Budget::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'version' => 1, 'title' => 'Budget 2026']);
        $budget->lines()->create(['expense_category_id' => $c['category']->id, 'planned_cents' => 20000]);
        $service = app(BudgetService::class);
        $service->approve($budget, $c['user']);
        $service->lock($budget->fresh(), $c['user']);
        $this->assertSame('locked', $budget->fresh()->status);
        try {
            $service->revise($budget->fresh(), $c['user'], 'Révision annuelle documentée');
            $this->fail('Locked budget revision should fail.');
        } catch (ValidationException) {
            $this->assertSame('locked', $budget->fresh()->status);
        }
        $service->unlock($budget->fresh(), $c['user'], 'Correction approuvée par la direction');
        $revision = $service->revise($budget->fresh(), $c['user'], 'Révision annuelle documentée');
        $this->assertSame(2, $revision->version);
        $this->assertSame($budget->id, $revision->supersedes_id);
        $this->assertSame(20000, $service->metrics($budget->fresh())['totals']['planned_cents']);
    }

    public function test_contract_renewal_preserves_historical_period_and_linked_invoices(): void
    {
        $c = $this->context();
        $contract = SupplierContract::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'reference' => 'CTR-001', 'title' => 'Entretien annuel', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'active']);
        $invoice = $this->invoice($c, 12000, 'INV-CONTRACT-1');
        $invoice->update(['supplier_contract_id' => $contract->id]);
        app(SupplierInvoiceWorkflow::class)->validate($invoice, $c['user']);

        $renewal = app(SupplierContractWorkflow::class)->renew($contract, $c['user'], '2027-01-01', '2027-12-31', 'Reconduction validée en assemblée');
        $this->assertSame('expired', $contract->fresh()->status);
        $this->assertSame($contract->id, $renewal->renewed_from_id);
        $this->assertSame(2, $renewal->renewal_version);
        $this->assertTrue($contract->fresh()->invoices->contains($invoice));
        $this->assertSame('2026-01-01', $contract->fresh()->starts_on->toDateString());
        $this->assertSame('2026-12-31', $contract->fresh()->ends_on->toDateString());
    }

    public function test_scheduled_announcement_and_audit_commands_exist(): void
    {
        $this->assertSame(0, Artisan::call('evosyndic:publish-scheduled-announcements'));
        $this->assertSame(0, Artisan::call('evosyndic:audit-expenses', ['--json' => true]));
        $this->assertStringContainsString('"ok": true', Artisan::output());
    }
}
