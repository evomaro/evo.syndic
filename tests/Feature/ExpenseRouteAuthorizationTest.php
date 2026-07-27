<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\ExpenseCommitment;
use App\Models\SupplierContract;
use App\Models\SupplierCreditNote;
use App\Models\SupplierSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class ExpenseRouteAuthorizationTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function pageRoutes(): array
    {
        return [
            'overview' => ['expenses.index'], 'supplier list' => ['suppliers.index'], 'supplier form' => ['suppliers.create'],
            'contract list' => ['supplier-contracts.index'], 'contract form' => ['supplier-contracts.create'],
            'commitment list' => ['expense-commitments.index'], 'commitment form' => ['expense-commitments.create'],
            'invoice list' => ['supplier-invoices.index'], 'invoice form' => ['supplier-invoices.create'],
            'settlement list' => ['supplier-settlements.index'], 'settlement form' => ['supplier-settlements.create'],
            'credit list' => ['supplier-credit-notes.index'], 'credit form' => ['supplier-credit-notes.create'],
            'payables' => ['supplier-payables.index'], 'budget list' => ['budgets.index'], 'budget form' => ['budgets.create'],
            'categories' => ['expense-categories.index'],
        ];
    }

    #[DataProvider('pageRoutes')]
    public function test_phase_three_pages_enforce_route_permission(string $routeName): void
    {
        $authorized = $this->phaseThreeContext();
        $this->actingAs($authorized['user'])->get(route($routeName))->assertOk();

        $unauthorized = $this->phaseThreeContext('coproprietaire');
        $this->actingAs($unauthorized['user'])->get(route($routeName))->assertForbidden();
    }

    public static function modelRoutes(): array
    {
        return [
            'supplier' => ['suppliers.show', 'supplier'],
            'supplier edit' => ['suppliers.edit', 'supplier'],
            'contract' => ['supplier-contracts.show', 'contract'],
            'commitment' => ['expense-commitments.show', 'commitment'],
            'invoice' => ['supplier-invoices.show', 'invoice'],
            'settlement' => ['supplier-settlements.show', 'settlement'],
            'credit' => ['supplier-credit-notes.show', 'credit'],
            'budget' => ['budgets.show', 'budget'],
        ];
    }

    public function test_supplier_edit_requires_manage_permission(): void
    {
        $authorized = $this->phaseThreeContext();
        $this->actingAs($authorized['user'])->get(route('suppliers.edit', $authorized['supplier']))->assertOk();

        $unauthorized = $this->phaseThreeContext('coproprietaire');
        $this->actingAs($unauthorized['user'])->get(route('suppliers.edit', $unauthorized['supplier']))->assertForbidden();
    }

    #[DataProvider('modelRoutes')]
    public function test_bound_models_reject_other_organization_and_manipulated_ids(string $routeName, string $kind): void
    {
        $local = $this->phaseThreeContext();
        $foreign = $this->phaseThreeContext();
        $model = $this->modelFor($foreign, $kind);

        $this->actingAs($local['user'])->get(route($routeName, $model))->assertNotFound();
        $this->get(route($routeName, 999999))->assertNotFound();
    }

    public function test_residence_scoped_user_cannot_open_model_from_another_residence(): void
    {
        $context = $this->phaseThreeContext('accountant', false);
        $otherResidence = $this->addResidence($context, false);
        $contract = SupplierContract::create(['organization_id' => $context['organization']->id, 'residence_id' => $otherResidence->id, 'supplier_id' => $context['supplier']->id, 'reference' => 'FOREIGN-R', 'title' => 'Other residence', 'starts_on' => '2026-01-01']);

        $this->actingAs($context['user'])->get(route('supplier-contracts.show', $contract))->assertNotFound();
    }

    private function modelFor(array $context, string $kind)
    {
        return match ($kind) {
            'supplier' => $context['supplier'],
            'contract' => SupplierContract::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'supplier_id' => $context['supplier']->id, 'reference' => 'CTR-X', 'title' => 'Contract', 'starts_on' => '2026-01-01']),
            'commitment' => ExpenseCommitment::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $context['category']->id, 'title' => 'Commitment', 'committed_on' => '2026-01-01', 'amount_cents' => 100]),
            'invoice' => $this->makePhaseThreeInvoice($context),
            'settlement' => SupplierSettlement::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'supplier_id' => $context['supplier']->id, 'financial_account_id' => $context['account']->id, 'settlement_date' => '2026-03-01', 'amount_cents' => 100, 'method' => 'cash']),
            'credit' => SupplierCreditNote::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'supplier_id' => $context['supplier']->id, 'credit_date' => '2026-03-01', 'amount_cents' => 100]),
            'budget' => Budget::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'version' => 1, 'title' => 'Budget']),
        };
    }
}
