<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\SupplierContractRequest;
use App\Models\ExpenseCategory;
use App\Models\SupplierContract;
use App\Models\SupplierServiceCategory;
use App\Services\SupplierContractWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupplierContractController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $query = SupplierContract::query()->where('residence_id', $context->residence()->id)->with('supplier:id,legal_name');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return Inertia::render('SupplierContracts/Index', ['contracts' => $query->latest('starts_on')->paginate(20)->withQueryString(), 'filters' => $request->only('status')]);
    }

    public function create(TenantContext $context)
    {
        return Inertia::render('SupplierContracts/Form', [
            'serviceCategories' => SupplierServiceCategory::query()->where('organization_id', $context->organization()->id)->where('active', true)->get(['id', 'name']),
            'expenseCategories' => ExpenseCategory::query()->where('residence_id', $context->residence()->id)->where('active', true)->get(['id', 'name']),
        ]);
    }

    public function show(SupplierContract $contract, TenantContext $context)
    {
        $this->tenant($contract, $context);
        $this->authorize('view', $contract);

        return Inertia::render('SupplierContracts/Show', ['contract' => $contract->load(['supplier', 'serviceCategory', 'attachments' => fn ($q) => $q->where('status', 'active'), 'renewedFrom', 'renewals'])]);
    }

    public function store(SupplierContractRequest $request, TenantContext $context)
    {
        $contract = SupplierContract::create($request->validated() + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id]);

        return to_route('supplier-contracts.show', $contract)->with('success', __('Contrat créé.'));
    }

    public function transition(Request $request, SupplierContract $contract, TenantContext $context, SupplierContractWorkflow $workflow)
    {
        $this->tenant($contract, $context);
        $this->authorize('update', $contract);
        $data = $request->validate(['action' => ['required', Rule::in(['renew', 'terminate'])], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        if ($data['action'] === 'terminate') {
            $workflow->terminate($contract, $request->user(), $data['reason']);
        } else {
            abort_if(blank($data['starts_on'] ?? null) || blank($data['ends_on'] ?? null), 422, __('La nouvelle période est obligatoire.'));
            $workflow->renew($contract, $request->user(), $data['starts_on'], $data['ends_on'], $data['reason']);
        }

        return back()->with('success', __('Contrat mis à jour.'));
    }

    private function tenant(SupplierContract $contract, TenantContext $context): void
    {
        abort_unless($contract->organization_id === $context->organization()->id && $contract->residence_id === $context->residence()->id, 404);
    }
}
