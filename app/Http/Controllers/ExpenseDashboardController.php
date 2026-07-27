<?php

namespace App\Http\Controllers;

use App\Queries\ExpenseDashboardQuery;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseDashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, ExpenseDashboardQuery $query)
    {
        $legacy = ['suppliers' => 'suppliers.index', 'contracts' => 'supplier-contracts.index', 'commitments' => 'expense-commitments.index', 'invoices' => 'supplier-invoices.index', 'settlements' => 'supplier-settlements.index', 'credits' => 'supplier-credit-notes.index', 'payables' => 'supplier-payables.index', 'budgets' => 'budgets.index', 'categories' => 'expense-categories.index'];
        if ($route = $legacy[(string) $request->query('tab')] ?? null) {
            return to_route($route, status: 301);
        }

        return Inertia::render('Expenses/Overview', $query->get($context));
    }
}
