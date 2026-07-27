<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class ExpenseLegacyRedirectTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    public static function legacyPaths(): array
    {
        return [
            'suppliers' => ['/expenses/suppliers', 'suppliers.index'], 'contracts' => ['/expenses/contracts', 'supplier-contracts.index'],
            'commitments' => ['/expenses/commitments', 'expense-commitments.index'], 'invoices' => ['/expenses/invoices', 'supplier-invoices.index'],
            'settlements' => ['/expenses/settlements', 'supplier-settlements.index'], 'credits' => ['/expenses/credit-notes', 'supplier-credit-notes.index'],
            'payables' => ['/expenses/payables', 'supplier-payables.index'], 'budgets' => ['/expenses/budgets', 'budgets.index'],
            'categories' => ['/expenses/categories', 'expense-categories.index'],
        ];
    }

    #[DataProvider('legacyPaths')]
    public function test_legacy_paths_redirect_to_exact_resource(string $path, string $routeName): void
    {
        $context = $this->phaseThreeContext();
        $this->actingAs($context['user'])->get($path)->assertRedirect(route($routeName));
    }

    #[DataProvider('legacyPaths')]
    public function test_legacy_tab_query_redirects_permanently(string $path, string $routeName): void
    {
        $context = $this->phaseThreeContext();
        $tab = basename($path) === 'credit-notes' ? 'credits' : basename($path);
        $this->actingAs($context['user'])->get(route('expenses.index', ['tab' => $tab]))->assertRedirect(route($routeName))->assertStatus(301);
    }
}
