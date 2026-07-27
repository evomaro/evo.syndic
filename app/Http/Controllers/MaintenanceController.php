<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceCategory;
use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Models\PreventiveIntervention;
use App\Models\Supplier;
use App\Models\SupplierContract;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MaintenanceController extends Controller
{
    public function dashboard(TenantContext $context)
    {
        $residence = $context->residence();
        $base = MaintenanceRequest::query()->where('organization_id', $context->organization()->id)->where('residence_id', $residence->id);
        $statuses = (clone $base)->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v);
        $priorities = (clone $base)->selectRaw('priority, COUNT(*) total')->groupBy('priority')->pluck('total', 'priority')->map(fn ($v) => (int) $v);
        $actual = (int) MaintenanceWorkOrder::query()->where('residence_id', $residence->id)->whereIn('status', ['completed', 'validated'])->sum('actual_cost_cents');
        $validatedInvoices = (int) MaintenanceWorkOrder::query()->join('supplier_invoices', 'supplier_invoices.maintenance_work_order_id', '=', 'maintenance_work_orders.id')->where('maintenance_work_orders.residence_id', $residence->id)->whereIn('supplier_invoices.status', ['validated', 'partial', 'paid'])->sum('supplier_invoices.total_cents');

        return Inertia::render('Maintenance/Dashboard', ['metrics' => [
            'open' => (clone $base)->whereNotIn('status', ['closed', 'rejected', 'cancelled'])->count(), 'statuses' => $statuses,
            'priorities' => $priorities, 'overdue' => (clone $base)->whereNotIn('status', ['resolved', 'closed', 'rejected', 'cancelled'])->where('resolution_deadline_at', '<', now('UTC'))->count(),
            'reopened' => (int) (clone $base)->sum('reopen_count'), 'actual_work_cost_cents' => $actual, 'validated_invoice_cents' => $validatedInvoices,
            'preventive_due' => PreventiveIntervention::where('residence_id', $residence->id)->whereIn('status', ['due', 'overdue'])->count(),
        ]]);
    }

    public function operations(Request $request, TenantContext $context)
    {
        $residence = $context->residence();
        $mode = str($request->route()->getName())->afterLast('.')->toString();
        $base = MaintenanceRequest::query()->where('organization_id', $context->organization()->id)->where('residence_id', $residence->id);
        $requests = (clone $base)->with('category:id,name_fr,name_ar')->when($mode === 'overdue', fn ($q) => $q->whereNotIn('status', ['resolved', 'closed', 'rejected', 'cancelled'])->where('resolution_deadline_at', '<', now('UTC')))->latest()->paginate($mode === 'kanban' ? 60 : 25)->withQueryString();
        $calendar = MaintenanceWorkOrder::query()->where('residence_id', $residence->id)->whereNotNull('planned_start_at')->orderBy('planned_start_at')->limit(200)->get(['id', 'reference', 'status', 'planned_start_at', 'planned_end_at', 'resident_notes']);
        $completionSeconds = \DB::getDriverName() === 'mysql'
            ? 'TIMESTAMPDIFF(SECOND, maintenance_work_orders.actual_start_at, maintenance_work_orders.completed_at)'
            : "strftime('%s', maintenance_work_orders.completed_at) - strftime('%s', maintenance_work_orders.actual_start_at)";
        $supplierPerformance = MaintenanceWorkOrder::query()->leftJoin('suppliers', 'suppliers.id', '=', 'maintenance_work_orders.supplier_id')->where('maintenance_work_orders.residence_id', $residence->id)->whereNotNull('supplier_id')->groupBy('suppliers.id', 'suppliers.legal_name')->selectRaw("suppliers.id, suppliers.legal_name, COUNT(*) work_orders, SUM(CASE WHEN maintenance_work_orders.status = ? THEN 1 ELSE 0 END) validated, AVG(CASE WHEN maintenance_work_orders.completed_at IS NOT NULL AND maintenance_work_orders.actual_start_at IS NOT NULL THEN {$completionSeconds} ELSE NULL END) average_completion_seconds", ['validated'])->get()->map(fn ($row) => ['id' => $row->id, 'legal_name' => $row->legal_name, 'work_orders' => (int) $row->work_orders, 'validated' => (int) $row->validated, 'average_completion_seconds' => $row->average_completion_seconds === null ? null : (float) $row->average_completion_seconds]);
        $averages = (clone $base)->selectRaw('AVG(CASE WHEN acknowledged_at IS NOT NULL THEN '.(\DB::getDriverName() === 'mysql' ? 'TIMESTAMPDIFF(SECOND, submitted_at, acknowledged_at)' : "strftime('%s', acknowledged_at) - strftime('%s', submitted_at)").' END) avg_ack, AVG(CASE WHEN resolved_at IS NOT NULL THEN '.(\DB::getDriverName() === 'mysql' ? 'TIMESTAMPDIFF(SECOND, submitted_at, resolved_at)' : "strftime('%s', resolved_at) - strftime('%s', submitted_at)").' END) avg_resolution')->first();

        return Inertia::render('Maintenance/Operations', ['mode' => $mode, 'requests' => $requests, 'calendar' => $calendar, 'supplierPerformance' => $supplierPerformance, 'averages' => ['ack_seconds' => $averages->avg_ack === null ? null : (float) $averages->avg_ack, 'resolution_seconds' => $averages->avg_resolution === null ? null : (float) $averages->avg_resolution]]);
    }

    public function requests(Request $request, TenantContext $context)
    {
        $query = MaintenanceRequest::query()->with(['category:id,name_fr,name_ar', 'reporter:id,name'])->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id);
        $this->filters($query, $request, ['status', 'priority', 'maintenance_category_id']);
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$request->string('q').'%')->orWhere('title', 'like', '%'.$request->string('q').'%'));
        }

        return Inertia::render('Maintenance/Requests/Index', ['requests' => $query->latest()->paginate(20)->withQueryString(), 'filters' => $request->only(['q', 'status', 'priority', 'maintenance_category_id']), 'options' => $this->options($context)]);
    }

    public function createRequest(TenantContext $context)
    {
        return Inertia::render('Maintenance/Requests/Form', ['options' => $this->options($context)]);
    }

    public function editRequest(MaintenanceRequest $maintenanceRequest, TenantContext $context)
    {
        $this->scoped($maintenanceRequest, $context);
        $this->authorize('update', $maintenanceRequest);
        abort_unless(in_array($maintenanceRequest->status, ['draft', 'submitted', 'under_review'], true), 422);

        return Inertia::render('Maintenance/Requests/Form', ['maintenanceRequest' => $maintenanceRequest, 'options' => $this->options($context)]);
    }

    public function storeRequest(Request $request, TenantContext $context)
    {
        $data = $this->requestData($request, $context);
        $category = MaintenanceCategory::whereKey($data['maintenance_category_id'])->where('organization_id', $context->organization()->id)->where('active', true)->firstOrFail();
        $role = $request->user()->organizations()->whereKey($context->organization()->id)->first()?->pivot?->role ?? 'resident';
        $model = MaintenanceRequest::create($data + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id, 'reporter_user_id' => $request->user()->id, 'reporter_role' => $role, 'reference' => 'DM-'.now()->format('Y').'-'.str()->upper(str()->random(8)), 'priority' => $data['priority'] ?? $category->default_priority, 'sla_snapshot' => $category->only(['ack_target_minutes', 'schedule_target_minutes', 'resolution_target_minutes'])]);
        activity()->performedOn($model)->causedBy($request->user())->withProperties(['organization_id' => $model->organization_id, 'residence_id' => $model->residence_id])->log('maintenance_request.created');

        $showRoute = $request->routeIs('portal.maintenance.*')
            ? 'portal.maintenance.show'
            : 'maintenance.requests.show';

        return redirect()->route($showRoute, $model)->with('success', __('Demande enregistrée.'));
    }

    public function showRequest(MaintenanceRequest $maintenanceRequest, TenantContext $context)
    {
        $this->scoped($maintenanceRequest, $context);
        $this->authorize('view', $maintenanceRequest);
        $maintenanceRequest->load(['category', 'equipment', 'reporter:id,name', 'transitions.actor:id,name', 'updates' => fn ($q) => $q->with('author:id,name')->whereNull('archived_at')->latest(), 'assignments.user:id,name', 'quotations.supplier:id,legal_name', 'workOrders.invoice', 'attachments' => fn ($q) => $q->whereNull('archived_at')]);

        return Inertia::render('Maintenance/Requests/Show', ['maintenanceRequest' => $maintenanceRequest, 'options' => $this->options($context)]);
    }

    public function categories(TenantContext $context)
    {
        return Inertia::render('Maintenance/Categories', ['categories' => MaintenanceCategory::where('organization_id', $context->organization()->id)->orderBy('sort_order')->paginate(30)]);
    }

    public function storeCategory(Request $request, TenantContext $context)
    {
        $data = $request->validate(['name_fr' => ['required', 'string', 'max:255'], 'name_ar' => ['required', 'string', 'max:255'], 'description_fr' => ['nullable', 'string'], 'description_ar' => ['nullable', 'string'], 'default_priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'ack_target_minutes' => ['required', 'integer', 'min:1'], 'schedule_target_minutes' => ['required', 'integer', 'min:1'], 'resolution_target_minutes' => ['required', 'integer', 'min:1'], 'responsible_user_id' => ['nullable', 'integer'], 'active' => ['sometimes', 'boolean'], 'sort_order' => ['nullable', 'integer', 'min:0']]);
        if (! empty($data['responsible_user_id'])) {
            abort_unless($context->organization()->users()->whereKey($data['responsible_user_id'])->exists(), 422);
        }
        MaintenanceCategory::create($data + ['organization_id' => $context->organization()->id]);

        return back()->with('success', __('Catégorie enregistrée.'));
    }

    public function updateCategory(Request $request, MaintenanceCategory $category, TenantContext $context)
    {
        abort_unless($category->organization_id === $context->organization()->id, 404);
        $data = $request->validate(['name_fr' => ['required', 'string', 'max:255'], 'name_ar' => ['required', 'string', 'max:255'], 'description_fr' => ['nullable', 'string'], 'description_ar' => ['nullable', 'string'], 'default_priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'ack_target_minutes' => ['required', 'integer', 'min:1'], 'schedule_target_minutes' => ['required', 'integer', 'min:1'], 'resolution_target_minutes' => ['required', 'integer', 'min:1'], 'responsible_user_id' => ['nullable', 'integer'], 'active' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0']]);
        if (! empty($data['responsible_user_id'])) {
            abort_unless($context->organization()->users()->whereKey($data['responsible_user_id'])->exists(), 422);
        }
        $before = $category->only(array_keys($data));
        $category->update($data);
        activity()->performedOn($category)->causedBy($request->user())->withProperties(['organization_id' => $category->organization_id, 'before' => $before, 'after' => $category->only(array_keys($data))])->log('maintenance_category.updated');

        return back()->with('success', __('Catégorie mise à jour.'));
    }

    public function seedCategories(TenantContext $context)
    {
        $defaults = [
            ['Plomberie', 'السباكة'], ['Électricité', 'الكهرباء'], ['Ascenseur', 'المصعد'], ['Nettoyage', 'النظافة'],
            ['Sécurité', 'الأمن'], ['Jardinage', 'البستنة'], ['Dommages structurels', 'أضرار هيكلية'],
            ['Équipement incendie', 'معدات الحريق'], ['Parking', 'موقف السيارات'], ['Autre', 'أخرى'],
        ];
        foreach ($defaults as $order => [$fr, $ar]) {
            MaintenanceCategory::firstOrCreate(['organization_id' => $context->organization()->id, 'name_fr' => $fr], ['name_ar' => $ar, 'default_priority' => 'normal', 'ack_target_minutes' => 1440, 'schedule_target_minutes' => 2880, 'resolution_target_minutes' => 10080, 'sort_order' => $order]);
        }

        return back()->with('success', __('Catégories par défaut vérifiées.'));
    }

    public function equipment(Request $request, TenantContext $context)
    {
        $query = MaintenanceEquipment::query()->with('category:id,name_fr,name_ar')->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id);
        $this->filters($query, $request, ['status', 'condition', 'maintenance_category_id']);
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%')->orWhere('serial_number', 'like', '%'.$request->string('q').'%')->orWhere('location', 'like', '%'.$request->string('q').'%'));
        }

        return Inertia::render('Maintenance/Equipment/Index', ['equipment' => $query->latest()->paginate(20)->withQueryString(), 'filters' => $request->only(['q', 'status', 'condition', 'maintenance_category_id']), 'options' => $this->options($context)]);
    }

    public function storeEquipment(Request $request, TenantContext $context)
    {
        $data = $this->equipmentData($request, $context);
        MaintenanceEquipment::create($data + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id]);

        return back()->with('success', __('Équipement enregistré.'));
    }

    public function showEquipment(MaintenanceEquipment $equipment, TenantContext $context)
    {
        $this->scoped($equipment, $context);
        $equipment->load(['category', 'building:id,name', 'supplier:id,legal_name', 'contract:id,reference', 'attachments' => fn ($query) => $query->whereNull('archived_at'), 'requests:id,equipment_id,reference,title,status', 'workOrders:id,equipment_id,reference,status', 'preventivePlans:id,equipment_id,name,active,next_intervention_on']);

        return Inertia::render('Maintenance/Equipment/Show', ['equipment' => $equipment, 'options' => $this->options($context)]);
    }

    public function updateEquipment(Request $request, MaintenanceEquipment $equipment, TenantContext $context)
    {
        $this->scoped($equipment, $context);
        $data = $this->equipmentData($request, $context, $equipment);
        $before = $equipment->only(array_keys($data));
        $equipment->update($data);
        activity()->performedOn($equipment)->causedBy($request->user())->withProperties(['organization_id' => $equipment->organization_id, 'residence_id' => $equipment->residence_id, 'before' => $before, 'after' => $equipment->only(array_keys($data))])->log('maintenance_equipment.updated');

        return back()->with('success', __('Équipement mis à jour.'));
    }

    public function equipmentTransition(Request $request, MaintenanceEquipment $equipment, TenantContext $context)
    {
        $this->scoped($equipment, $context);
        $data = $request->validate(['action' => ['required', Rule::in(['retire', 'reactivate'])], 'reason' => ['nullable', 'string', 'max:2000']]);
        if ($data['action'] === 'retire' && blank($data['reason'])) {
            return back()->withErrors(['reason' => __('Un motif est obligatoire.')]);
        }
        $equipment->update($data['action'] === 'retire' ? ['status' => 'retired', 'retired_at' => now('UTC'), 'retirement_reason' => $data['reason']] : ['status' => 'active', 'retired_at' => null, 'retirement_reason' => null]);
        activity()->performedOn($equipment)->causedBy($request->user())->withProperties(['organization_id' => $equipment->organization_id, 'residence_id' => $equipment->residence_id, 'action' => $data['action'], 'reason' => $data['reason']])->log('maintenance_equipment.'.$data['action']);

        return back()->with('success', __('État de l’équipement mis à jour.'));
    }

    private function options(TenantContext $context): array
    {
        $org = $context->organization();
        $res = $context->residence();

        return ['categories' => MaintenanceCategory::where('organization_id', $org->id)->where('active', true)->orderBy('sort_order')->get(), 'equipment' => MaintenanceEquipment::where('residence_id', $res->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'location']), 'buildings' => $res->buildings()->orderBy('name')->get(['id', 'name']), 'suppliers' => Supplier::where('organization_id', $org->id)->where('active', true)->orderBy('legal_name')->get(['id', 'legal_name']), 'users' => $org->users()->orderBy('name')->get(['users.id', 'name'])];
    }

    private function requestData(Request $request, TenantContext $context): array
    {
        return $request->validate(['building_id' => ['nullable', Rule::exists('buildings', 'id')->where('residence_id', $context->residence()->id)], 'floor_id' => ['nullable', 'integer'], 'equipment_id' => ['nullable', Rule::exists('maintenance_equipment', 'id')->where('residence_id', $context->residence()->id)->where('status', 'active')], 'maintenance_category_id' => ['required', Rule::exists('maintenance_categories', 'id')->where('organization_id', $context->organization()->id)], 'title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:10000'], 'location' => ['nullable', 'string', 'max:255'], 'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'observed_on' => ['nullable', 'date'], 'contact_method' => ['nullable', Rule::in(['email', 'phone', 'app'])], 'contact_details' => ['nullable', 'string', 'max:255'], 'contact_visible_to_assignees' => ['sometimes', 'boolean']]);
    }

    private function equipmentData(Request $request, TenantContext $context, ?MaintenanceEquipment $equipment = null): array
    {
        $data = $request->validate(['building_id' => ['nullable', Rule::exists('buildings', 'id')->where('residence_id', $context->residence()->id)], 'maintenance_category_id' => ['required', Rule::exists('maintenance_categories', 'id')->where('organization_id', $context->organization()->id)], 'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('organization_id', $context->organization()->id)], 'supplier_contract_id' => ['nullable', 'integer'], 'location' => ['nullable', 'string', 'max:255'], 'name' => ['required', 'string', 'max:255'], 'manufacturer' => ['nullable', 'string', 'max:255'], 'model' => ['nullable', 'string', 'max:255'], 'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('maintenance_equipment')->where('organization_id', $context->organization()->id)->ignore($equipment)], 'installed_on' => ['nullable', 'date'], 'warranty_expires_on' => ['nullable', 'date'], 'condition' => ['required', Rule::in(['good', 'fair', 'poor', 'critical'])], 'public_description' => ['nullable', 'string'], 'internal_notes' => ['nullable', 'string']]);
        if (! empty($data['supplier_contract_id'])) {
            abort_unless(SupplierContract::whereKey($data['supplier_contract_id'])->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->where('supplier_id', $data['supplier_id'])->exists(), 422);
        }

        return $data;
    }

    private function filters(Builder $query, Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            if ($request->filled($key)) {
                $query->where($key, $request->input($key));
            }
        }
    }

    private function scoped($model, TenantContext $context): void
    {
        abort_unless($model->organization_id === $context->organization()->id && $model->residence_id === $context->residence()->id, 404);
    }
}
