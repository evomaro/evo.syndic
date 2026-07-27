<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceEquipment;
use App\Models\PreventiveIntervention;
use App\Models\PreventiveMaintenancePlan;
use App\Models\SupplierContract;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PreventiveMaintenanceController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $plans = PreventiveMaintenancePlan::with(['equipment:id,name', 'supplier:id,legal_name'])->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->latest()->paginate(20);
        $interventions = PreventiveIntervention::with('plan:id,name')->where('residence_id', $context->residence()->id)->orderBy('due_on')->paginate(20, ['*'], 'interventions_page');

        return Inertia::render('Maintenance/Preventive/Index', ['plans' => $plans, 'interventions' => $interventions, 'equipment' => MaintenanceEquipment::where('residence_id', $context->residence()->id)->where('status', 'active')->get(['id', 'name'])]);
    }

    public function store(Request $request, TenantContext $context)
    {
        $data = $request->validate(['equipment_id' => ['nullable', Rule::exists('maintenance_equipment', 'id')->where('residence_id', $context->residence()->id)->where('status', 'active')], 'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('organization_id', $context->organization()->id)], 'supplier_contract_id' => ['nullable', 'integer'], 'responsible_user_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'location' => ['nullable', 'string', 'max:255'], 'frequency_type' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'custom'])], 'frequency_interval' => ['required', 'integer', 'min:1', 'max:3650'], 'starts_on' => ['required', 'date'], 'next_intervention_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reminder_days' => ['required', 'integer', 'min:0', 'max:365'], 'checklist' => ['required', 'array', 'min:1'], 'checklist.*' => ['required', 'string', 'max:500'], 'active' => ['sometimes', 'boolean']]);
        if (! empty($data['responsible_user_id'])) {
            abort_unless($context->organization()->users()->whereKey($data['responsible_user_id'])->exists(), 422);
        }
        if (! empty($data['supplier_contract_id'])) {
            abort_unless(SupplierContract::whereKey($data['supplier_contract_id'])->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->where('supplier_id', $data['supplier_id'])->exists(), 422);
        }
        $plan = PreventiveMaintenancePlan::create($data + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id]);
        activity()->performedOn($plan)->causedBy($request->user())->withProperties(['organization_id' => $plan->organization_id, 'residence_id' => $plan->residence_id])->log('preventive_maintenance_plan.created');

        return back()->with('success', __('Plan préventif enregistré.'));
    }

    public function update(Request $request, PreventiveMaintenancePlan $plan, TenantContext $context)
    {
        abort_unless($plan->organization_id === $context->organization()->id && $plan->residence_id === $context->residence()->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'location' => ['nullable', 'string', 'max:255'], 'frequency_type' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'custom'])], 'frequency_interval' => ['required', 'integer', 'min:1', 'max:3650'], 'next_intervention_on' => ['required', 'date'], 'reminder_days' => ['required', 'integer', 'min:0', 'max:365'], 'checklist' => ['required', 'array', 'min:1'], 'checklist.*' => ['required', 'string', 'max:500'], 'active' => ['required', 'boolean']]);
        $before = $plan->only(array_keys($data));
        $plan->update($data);
        activity()->performedOn($plan)->causedBy($request->user())->withProperties(['organization_id' => $plan->organization_id, 'residence_id' => $plan->residence_id, 'before' => $before, 'after' => $plan->only(array_keys($data))])->log('preventive_maintenance_plan.updated');

        return back()->with('success', __('Plan préventif mis à jour.'));
    }
}
