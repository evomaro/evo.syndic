<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCommitment;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\ResidenceDocument;
use App\Models\Supplier;
use App\Models\SupplierContract;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\BudgetService;
use App\Services\BudgetThresholdNotificationService;
use App\Services\OverdueSupplierInvoiceNotificationService;
use App\Services\ResidenceDocumentService;
use App\Services\SupplierContractAttachmentService;
use App\Services\SupplierContractRenewalService;
use App\Services\SupplierInvoiceWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseThreeClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    private function context(string $role = 'owner'): array
    {
        $user = User::factory()->create(['preferred_language' => 'fr']);
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => true]);
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $account = FinancialAccount::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Banque', 'code' => 'BANK', 'type' => 'bank', 'active' => true]);
        $category = ExpenseCategory::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Nettoyage', 'code' => 'cleaning']);
        $supplier = Supplier::create(['organization_id' => $organization->id, 'legal_name' => 'Atlas Services']);

        return compact('user', 'organization', 'residence', 'exercise', 'account', 'category', 'supplier');
    }

    private function invoice(array $c, int $total = 9000, string $due = '2026-03-01'): SupplierInvoice
    {
        $invoice = SupplierInvoice::create(['organization_id' => $c['organization']->id, 'primary_residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'supplier_invoice_number' => uniqid('F-'), 'invoice_date' => '2026-02-01', 'due_date' => $due, 'subtotal_cents' => $total, 'tax_cents' => 0, 'total_cents' => $total]);
        $invoice->lines()->create(['residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'expense_category_id' => $c['category']->id, 'description' => 'Service', 'quantity' => 1, 'unit_price_cents' => $total, 'tax_rate' => 0, 'subtotal_cents' => $total, 'tax_cents' => 0, 'total_cents' => $total]);
        $bytes = '%PDF invoice';
        Storage::disk('local')->put("invoices/{$invoice->id}.pdf", $bytes);
        $invoice->attachments()->create(['kind' => 'original', 'version' => 1, 'name' => 'invoice.pdf', 'disk' => 'local', 'path' => "invoices/{$invoice->id}.pdf", 'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $c['user']->id]);

        return app(SupplierInvoiceWorkflow::class)->validate($invoice, $c['user']);
    }

    public function test_refactored_pages_and_legacy_redirects_are_reachable(): void
    {
        $c = $this->context();
        $contract = SupplierContract::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'reference' => 'CTR-1', 'title' => 'Contrat', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $commitment = ExpenseCommitment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'expense_category_id' => $c['category']->id, 'title' => 'Engagement', 'committed_on' => '2026-01-01', 'amount_cents' => 1000]);
        $invoice = $this->invoice($c);
        $settlement = SupplierSettlement::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'supplier_id' => $c['supplier']->id, 'financial_account_id' => $c['account']->id, 'settlement_date' => '2026-03-02', 'amount_cents' => 1000, 'method' => 'cash']);
        $credit = SupplierCreditNote::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'credit_date' => '2026-03-02', 'amount_cents' => 100]);
        $budget = Budget::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'version' => 1, 'title' => 'Budget']);
        $budget->lines()->create(['expense_category_id' => $c['category']->id, 'planned_cents' => 10000]);
        $this->actingAs($c['user']);
        foreach (['expenses.index', 'suppliers.index', 'suppliers.create', 'supplier-contracts.index', 'supplier-contracts.create', 'expense-commitments.index', 'expense-commitments.create', 'supplier-invoices.index', 'supplier-invoices.create', 'supplier-settlements.index', 'supplier-settlements.create', 'supplier-credit-notes.index', 'supplier-credit-notes.create', 'supplier-payables.index', 'budgets.index', 'budgets.create', 'expense-categories.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach ([['suppliers.show', $c['supplier']], ['supplier-contracts.show', $contract], ['expense-commitments.show', $commitment], ['supplier-invoices.show', $invoice], ['supplier-settlements.show', $settlement], ['supplier-credit-notes.show', $credit], ['budgets.show', $budget]] as [$route, $model]) {
            $this->get(route($route, $model))->assertOk();
        }
        foreach (['suppliers', 'contracts', 'commitments', 'invoices', 'settlements', 'credit-notes', 'payables', 'budgets', 'categories'] as $legacy) {
            $this->get("/expenses/{$legacy}")->assertRedirect();
        }
    }

    public function test_form_request_and_policy_reject_foreign_scope(): void
    {
        $c = $this->context();
        $other = $this->context();
        $this->actingAs($c['user'])->post(route('supplier-contracts.store'), ['supplier_id' => $other['supplier']->id, 'reference' => 'X', 'title' => 'Foreign', 'starts_on' => '2026-01-01', 'renewal_type' => 'none', 'notice_days' => 30])->assertSessionHasErrors('supplier_id');
        $this->get(route('supplier-contracts.show', SupplierContract::create(['organization_id' => $other['organization']->id, 'residence_id' => $other['residence']->id, 'supplier_id' => $other['supplier']->id, 'reference' => 'OTHER', 'title' => 'Other', 'starts_on' => '2026-01-01'])))->assertNotFound();
    }

    public function test_budget_and_overdue_notifications_are_staged_preference_aware_and_idempotent(): void
    {
        $c = $this->context();
        NotificationPreference::create(['user_id' => $c['user']->id, 'organization_id' => $c['organization']->id, 'database_enabled' => true, 'email_enabled' => false]);
        $budget = Budget::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'version' => 1, 'title' => 'Budget']);
        $budget->lines()->create(['expense_category_id' => $c['category']->id, 'planned_cents' => 10000]);
        app(BudgetService::class)->approve($budget, $c['user']);
        $invoice = $this->invoice($c, 11000, '2026-03-01');
        $budgetResult = app(BudgetThresholdNotificationService::class)->dispatch([], true);
        $this->assertSame(3, $budgetResult['events']);
        app(BudgetThresholdNotificationService::class)->dispatch([], true);
        $this->assertSame(3, \DB::table('notification_dispatches')->where('event_type', 'budget_threshold')->count());
        $overdue = app(OverdueSupplierInvoiceNotificationService::class)->dispatch(CarbonImmutable::parse('2026-06-15'), [], true);
        $this->assertSame(1, $overdue['events']);
        app(OverdueSupplierInvoiceNotificationService::class)->dispatch(CarbonImmutable::parse('2026-06-15'), [], true);
        $this->assertSame(1, \DB::table('notification_dispatches')->where('event_type', 'overdue_supplier_invoice')->count());
        $this->assertSame('90', Notification::sent($c['user'], PortalNotification::class)->last()->payload['stage']);
        $this->assertSame('validated', $invoice->status);
    }

    public function test_automatic_renewal_is_deterministic_and_duplicate_safe(): void
    {
        $c = $this->context();
        $contract = SupplierContract::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'reference' => 'AUTO', 'title' => 'Mensuel', 'starts_on' => '2024-02-01', 'ends_on' => '2024-02-29', 'billing_frequency' => 'monthly', 'renewal_type' => 'automatic', 'notice_days' => 0, 'status' => 'active']);
        $service = app(SupplierContractRenewalService::class);
        $service->dispatch(CarbonImmutable::parse('2024-02-29'), [], true);
        $service->dispatch(CarbonImmutable::parse('2024-02-29'), [], true);
        $renewal = $contract->renewals()->firstOrFail();
        $this->assertSame('2024-03-01', $renewal->starts_on->toDateString());
        $this->assertSame('2024-03-31', $renewal->ends_on->toDateString());
        $this->assertSame(1, $contract->renewals()->count());
    }

    public function test_contract_attachments_are_private_versioned_and_checksum_checked(): void
    {
        $c = $this->context();
        $contract = SupplierContract::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'supplier_id' => $c['supplier']->id, 'reference' => 'ATT', 'title' => 'Attachment', 'starts_on' => '2026-01-01']);
        $service = app(SupplierContractAttachmentService::class);
        $attachment = $service->upload($contract, UploadedFile::fake()->create('Contrat privé.pdf', 10, 'application/pdf'), $c['user'], true);
        $this->assertStringNotContainsString('Contrat privé', $attachment->path);
        $this->actingAs($c['user'])->get(route('supplier-contracts.attachments.download', $attachment))->assertOk()->assertHeader('cache-control', 'max-age=0, no-store, private');
        Storage::disk('local')->put($attachment->path, 'tampered');
        $this->get(route('supplier-contracts.attachments.download', $attachment))->assertStatus(409);
        $other = $this->context();
        $this->actingAs($other['user'])->get(route('supplier-contracts.attachments.download', $attachment))->assertNotFound();
    }

    public function test_scheduled_document_failure_is_preserved_and_retry_resolves_it(): void
    {
        $c = $this->context();
        $document = ResidenceDocument::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'title' => 'Document', 'category' => 'report', 'audience' => 'staff', 'status' => 'scheduled', 'scheduled_for' => now()->subMinute(), 'created_by' => $c['user']->id]);
        $service = app(ResidenceDocumentService::class);
        $this->assertFalse($service->attemptPublish($document, $c['user']));
        $this->assertSame('scheduled', $document->fresh()->status);
        $this->assertNotNull($document->fresh()->publication_failed_at);
        $service->storeVersion($document, UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'), $c['user']);
        $this->assertTrue($service->attemptPublish($document->fresh(), $c['user']));
        $this->assertSame('published', $document->fresh()->status);
        $this->assertNull($document->fresh()->publication_failed_at);
        $this->assertNotNull($document->fresh()->publication_failure_resolved_at);
    }
}
