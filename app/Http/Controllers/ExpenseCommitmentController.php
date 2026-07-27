<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\ExpenseCommitmentRequest;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCommitment;
use App\Models\FinancialExercise;
use App\Models\SupplierContract;
use App\Services\CommitmentWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExpenseCommitmentController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $query = ExpenseCommitment::query()->where('residence_id', $context->residence()->id)->with(['supplier:id,legal_name', 'category:id,name']);
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return Inertia::render('ExpenseCommitments/Index', ['commitments' => $query->latest('committed_on')->paginate(20)->withQueryString(), 'filters' => $request->only('status')]);
    }

    public function create(TenantContext $context)
    {
        return Inertia::render('ExpenseCommitments/Form', $this->options($context));
    }

    public function show(ExpenseCommitment $commitment, TenantContext $context)
    {
        $this->tenant($commitment, $context);
        $this->authorize('view', $commitment);

        return Inertia::render('ExpenseCommitments/Show', ['commitment' => $commitment->load(['supplier', 'category', 'contract', 'exercise', 'invoices'])]);
    }

    public function store(ExpenseCommitmentRequest $request, TenantContext $context)
    {
        $commitment = ExpenseCommitment::create($request->validated() + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id]);

        return to_route('expense-commitments.show', $commitment)->with('success', __('Engagement créé.'));
    }

    public function transition(Request $request, ExpenseCommitment $commitment, TenantContext $context, CommitmentWorkflow $workflow)
    {
        $this->tenant($commitment, $context);
        $this->authorize('update', $commitment);
        $data = $request->validate(['action' => ['required', Rule::in(['submit', 'approve', 'reject', 'cancel'])], 'reason' => ['nullable', 'string', 'max:1000']]);
        $workflow->transition($commitment, $request->user(), $data['action'], $data['reason'] ?? null);

        return back()->with('success', __('Engagement mis à jour.'));
    }

    private function options(TenantContext $context): array
    {
        return ['exercises' => FinancialExercise::query()->where('residence_id', $context->residence()->id)->where('status', 'open')->get(['id', 'name']), 'categories' => ExpenseCategory::query()->where('residence_id', $context->residence()->id)->where('active', true)->get(['id', 'name']), 'contracts' => SupplierContract::query()->where('residence_id', $context->residence()->id)->where('status', 'active')->get(['id', 'title', 'supplier_id'])];
    }

    private function tenant(ExpenseCommitment $commitment, TenantContext $context): void
    {
        abort_unless($commitment->organization_id === $context->organization()->id && $commitment->residence_id === $context->residence()->id, 404);
    }
}
