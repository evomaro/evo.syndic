<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\SupplierRequest;
use App\Models\Supplier;
use App\Models\SupplierServiceCategory;
use App\Queries\SupplierQuery;
use App\Services\SupplierDuplicateService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function index(Request $request, TenantContext $context, SupplierQuery $query)
    {
        return Inertia::render('Suppliers/Index', ['suppliers' => $query->paginate($request, $context), 'filters' => $request->only('q')]);
    }

    public function create(TenantContext $context)
    {
        return Inertia::render('Suppliers/Form', ['serviceCategories' => SupplierServiceCategory::query()->where('organization_id', $context->organization()->id)->where('active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function show(Supplier $supplier, TenantContext $context, SupplierQuery $query)
    {
        abort_unless($supplier->organization_id === $context->organization()->id, 404);
        $this->authorize('view', $supplier);
        $supplier->load(['categories:id,name', 'contracts' => fn ($rows) => $rows->where('residence_id', $context->residence()->id), 'invoices' => fn ($rows) => $rows->whereHas('lines', fn ($lines) => $lines->where('residence_id', $context->residence()->id))->latest('invoice_date')->limit(20)]);
        if (! request()->user()->canInOrganization('view_supplier_private_data', $context->organization())) {
            $query->hidePrivate($supplier);
        }

        return Inertia::render('Suppliers/Show', ['supplier' => $supplier]);
    }

    public function edit(Supplier $supplier, TenantContext $context)
    {
        abort_unless($supplier->organization_id === $context->organization()->id, 404);
        $this->authorize('update', $supplier);

        return Inertia::render('Suppliers/Form', [
            'supplier' => $supplier->load('categories:id,name'),
            'serviceCategories' => SupplierServiceCategory::query()->where('organization_id', $context->organization()->id)->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function search(Request $request, TenantContext $context, SupplierQuery $query)
    {
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'max:100']])['q']);

        return response()->json($query->search($term, $context, $request->user()));
    }

    public function store(SupplierRequest $request, TenantContext $context, SupplierDuplicateService $duplicates)
    {
        $data = $request->validated();
        if ($duplicates->candidates($context->organization()->id, $data)->isNotEmpty() && blank($data['duplicate_warning_reason'] ?? null)) {
            throw ValidationException::withMessages(['duplicate_warning_reason' => __('Un fournisseur similaire existe. Confirmez avec une justification.')]);
        }
        $categoryIds = $data['category_ids'] ?? [];
        $reason = $data['duplicate_warning_reason'] ?? null;
        unset($data['category_ids'], $data['duplicate_warning_reason']);
        $supplier = Supplier::create($data + ['organization_id' => $context->organization()->id]);
        $supplier->categories()->sync($categoryIds);
        if ($reason) {
            activity()->performedOn($supplier)->causedBy($request->user())->withProperties(['organization_id' => $supplier->organization_id, 'reason' => $reason])->log('supplier.duplicate_override');
        }

        return to_route('suppliers.show', $supplier)->with('success', __('Fournisseur créé.'));
    }

    public function update(SupplierRequest $request, Supplier $supplier, TenantContext $context)
    {
        abort_unless($supplier->organization_id === $context->organization()->id, 404);
        $this->authorize('update', $supplier);
        $data = $request->validated();
        $ids = $data['category_ids'] ?? [];
        unset($data['category_ids'], $data['duplicate_warning_reason']);
        $supplier->update($data);
        $supplier->categories()->sync($ids);

        return back()->with('success', __('Fournisseur mis à jour.'));
    }
}
