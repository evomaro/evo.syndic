<?php

namespace App\Http\Controllers;

use App\Models\ChargeCategory;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ChargeCategoryController extends Controller
{
    public function index(TenantContext $context)
    {
        $r = $context->residence();

        return Inertia::render('Finance/Categories', ['categories' => $r->chargeCategories()->with('defaultAllocationKey')->orderBy('sort_order')->get(), 'allocationKeys' => $r->allocationKeys()->where('active', true)->get(['id', 'name'])]);
    }

    public function store(Request $request, TenantContext $context)
    {
        $r = $context->residence();
        $data = $this->validated($request, $r->id);
        $category = $r->chargeCategories()->create($data + ['organization_id' => $r->organization_id]);
        activity()->performedOn($category)->causedBy($request->user())->withProperties(['organization_id' => $r->organization_id, 'residence_id' => $r->id])->log('charge_category.created');

        return back();
    }

    public function update(Request $request, ChargeCategory $category, TenantContext $context)
    {
        $this->tenant($category, $context);
        $category->update($this->validated($request, $category->residence_id, $category->id));

        return back();
    }

    public function seed(Request $request, TenantContext $context)
    {
        $r = $context->residence();
        foreach ([['Entretien courant', 'entretien', 'ordinary'], ['Eau et électricité', 'utilities', 'ordinary'], ['Assurance', 'insurance', 'ordinary'], ['Travaux exceptionnels', 'works', 'exceptional']] as [$name, $code, $type]) {
            $r->chargeCategories()->firstOrCreate(['code' => $code], ['organization_id' => $r->organization_id, 'name' => $name, 'type' => $type, 'default_distribution_method' => 'allocation_key', 'default_allocation_key_id' => $r->allocationKeys()->where('is_default', true)->value('id')]);
        }

return back()->with('success', __('Catégories suggérées ajoutées.'));
    }

    private function validated(Request $r, int $residenceId, ?int $ignore = null): array
    {
        return $r->validate(['name' => 'required|string|max:120', 'code' => ['required', 'alpha_dash', Rule::unique('charge_categories')->where('residence_id', $residenceId)->ignore($ignore)], 'description' => 'nullable|string|max:2000', 'type' => ['required', Rule::in(['ordinary', 'exceptional'])], 'default_distribution_method' => ['required', Rule::in(['allocation_key', 'equal', 'fixed', 'manual'])], 'default_allocation_key_id' => ['nullable', Rule::exists('allocation_keys', 'id')->where('residence_id', $residenceId)], 'active' => 'boolean', 'sort_order' => 'integer|min:0']);
    }

    private function tenant(ChargeCategory $a, TenantContext $c): void
    {
        abort_unless($a->organization_id === $c->organization()->id && $a->residence_id === $c->residence()->id, 404);
    }
}
