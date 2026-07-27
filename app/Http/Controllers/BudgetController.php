<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\BudgetRequest;
use App\Http\Requests\Expenses\BudgetRevisionRequest;
use App\Models\Budget;
use App\Models\ExpenseCategory;
use App\Models\FinancialExercise;
use App\Queries\BudgetReportingQuery;
use App\Services\BudgetDraftService;
use App\Services\BudgetService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function index(TenantContext $context, BudgetReportingQuery $query)
    {
        return Inertia::render('Budgets/Index', ['budgets' => $query->paginate($context)]);
    }

    public function create(TenantContext $context)
    {
        return Inertia::render('Budgets/Form', ['exercises' => FinancialExercise::query()->where('residence_id', $context->residence()->id)->where('status', 'open')->get(['id', 'name']), 'categories' => ExpenseCategory::query()->where('residence_id', $context->residence()->id)->where('active', true)->get(['id', 'name'])]);
    }

    public function show(Budget $budget, TenantContext $context, BudgetReportingQuery $query)
    {
        $this->tenant($budget, $context);
        $this->authorize('view', $budget);

        return Inertia::render('Budgets/Show', $query->show($budget));
    }

    public function store(BudgetRequest $request, TenantContext $context, BudgetDraftService $service)
    {
        $budget = $service->create($request->validated(), $context->organization(), $context->residence());

        return to_route('budgets.show', $budget)->with('success', __('Budget créé.'));
    }

    public function approve(Request $request, Budget $budget, TenantContext $context, BudgetService $service)
    {
        $this->tenant($budget, $context);
        $this->authorize('approve', $budget);
        $service->approve($budget, $request->user());

        return back()->with('success', __('Budget approuvé.'));
    }

    public function revise(BudgetRevisionRequest $request, Budget $budget, TenantContext $context, BudgetService $service)
    {
        $this->tenant($budget, $context);
        $this->authorize('update', $budget);
        $copy = $service->revise($budget, $request->user(), $request->validated('reason'));

        return to_route('budgets.show', $copy)->with('success', __('Révision créée.'));
    }

    public function lock(Request $request, Budget $budget, TenantContext $context, BudgetService $service)
    {
        $this->tenant($budget, $context);
        $this->authorize('approve', $budget);
        $service->lock($budget, $request->user());

        return back()->with('success', __('Budget verrouillé.'));
    }

    public function unlock(Request $request, Budget $budget, TenantContext $context, BudgetService $service)
    {
        $this->tenant($budget, $context);
        $this->authorize('approve', $budget);
        $service->unlock($budget, $request->user(), $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']])['reason']);

        return back()->with('success', __('Budget déverrouillé.'));
    }

    public function archive(Request $request, Budget $budget, TenantContext $context, BudgetService $service)
    {
        $this->tenant($budget, $context);
        $this->authorize('approve', $budget);
        $service->archive($budget, $request->user());

        return back()->with('success', __('Budget archivé.'));
    }

    public function prepareFundCall(Request $request, Budget $budget, TenantContext $context, BudgetDraftService $service)
    {
        $this->tenant($budget, $context);
        $this->authorize('update', $budget);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'issue_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:issue_date'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.budget_line_id' => ['required', 'integer'], 'lines.*.charge_category_id' => ['required', 'integer'], 'lines.*.distribution_method' => ['required', 'string'], 'lines.*.allocation_key_id' => ['nullable', 'integer']]);
        $call = $service->prepareFundCall($budget, $data, $request->user());

        return to_route('fund-calls.show', $call)->with('success', __('Brouillon d’appel de fonds préparé.'));
    }

    private function tenant(Budget $budget, TenantContext $context): void
    {
        abort_unless($budget->organization_id === $context->organization()->id && $budget->residence_id === $context->residence()->id, 404);
    }
}
