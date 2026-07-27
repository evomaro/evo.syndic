<?php

namespace Tests\Feature;

use App\Services\SupplierInvoiceDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class ExpenseFormRequestTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    public static function foreignReferences(): array
    {
        return [
            'supplier contract supplier' => ['supplier-contracts.store', 'supplier_id'],
            'commitment exercise' => ['expense-commitments.store', 'financial_exercise_id'],
            'commitment category' => ['expense-commitments.store', 'expense_category_id'],
            'invoice supplier' => ['supplier-invoices.store', 'supplier_id'],
            'invoice residence' => ['supplier-invoices.store', 'lines.0.residence_id'],
            'settlement account' => ['supplier-settlements.store', 'financial_account_id'],
            'budget exercise' => ['budgets.store', 'financial_exercise_id'],
        ];
    }

    #[DataProvider('foreignReferences')]
    public function test_form_requests_reject_foreign_tenant_references(string $routeName, string $field): void
    {
        $local = $this->phaseThreeContext();
        $foreign = $this->phaseThreeContext();
        $payload = $this->payload($routeName, $local);
        data_set($payload, $field, match ($field) {
            'supplier_id' => $foreign['supplier']->id,
            'financial_exercise_id' => $foreign['exercise']->id,
            'expense_category_id' => $foreign['category']->id,
            'lines.0.residence_id' => $foreign['residence']->id,
            'financial_account_id' => $foreign['account']->id,
        });

        $this->actingAs($local['user'])->post(route($routeName), $payload)->assertSessionHasErrors($field);
    }

    public function test_invoice_service_rejects_mismatched_residence_exercise_and_category(): void
    {
        $context = $this->phaseThreeContext('owner', false);
        $otherResidence = $this->addResidence($context, true);
        $payload = $this->payload('supplier-invoices.store', $context);
        $payload['lines'][0]['residence_id'] = $otherResidence->id;

        $this->actingAs($context['user'])->post(route('supplier-invoices.store'), $payload)->assertNotFound();
        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    public function test_blank_invoice_line_residence_uses_active_residence(): void
    {
        $context = $this->phaseThreeContext();
        $payload = $this->payload('supplier-invoices.store', $context);
        $payload['lines'][0]['residence_id'] = null;

        $invoice = app(SupplierInvoiceDraftService::class)->create(
            $payload,
            $context['organization'],
            $context['residence'],
            $context['user'],
        );

        $this->assertSame($context['residence']->id, $invoice->lines->sole()->residence_id);
    }

    private function payload(string $routeName, array $context): array
    {
        return match ($routeName) {
            'supplier-contracts.store' => ['supplier_id' => $context['supplier']->id, 'reference' => 'CTR-1', 'title' => 'Contract', 'starts_on' => '2026-01-01', 'renewal_type' => 'none', 'notice_days' => 30],
            'expense-commitments.store' => ['financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $context['category']->id, 'title' => 'Commitment', 'committed_on' => '2026-01-01', 'amount_cents' => 100],
            'supplier-invoices.store' => ['supplier_id' => $context['supplier']->id, 'invoice_date' => '2026-01-01', 'due_date' => '2026-01-31', 'lines' => [['residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $context['category']->id, 'description' => 'Service', 'quantity' => '1', 'unit_price_cents' => 100, 'tax_rate' => '0']]],
            'supplier-settlements.store' => ['financial_exercise_id' => $context['exercise']->id, 'supplier_id' => $context['supplier']->id, 'financial_account_id' => $context['account']->id, 'settlement_date' => '2026-01-01', 'amount_cents' => 100, 'method' => 'cash'],
            'budgets.store' => ['financial_exercise_id' => $context['exercise']->id, 'title' => 'Budget', 'lines' => [['expense_category_id' => $context['category']->id, 'planned_cents' => 100]]],
        };
    }
}
