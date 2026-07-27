<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceQuotation;
use App\Models\MaintenanceWorkOrder;
use App\Models\SupplierInvoice;
use App\Services\MaintenanceNotificationService;
use App\Services\MaintenanceWorkOrderWorkflow;
use App\Services\WorkOrderInvoiceService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MaintenanceWorkOrderController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $query = MaintenanceWorkOrder::with(['request:id,reference,title', 'supplier:id,legal_name', 'invoice:id,maintenance_work_order_id,status,total_cents'])->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$request->string('q').'%')->orWhere('scope_of_work', 'like', '%'.$request->string('q').'%'));
        }

        return Inertia::render('Maintenance/WorkOrders/Index', ['workOrders' => $query->latest()->paginate(20)->withQueryString(), 'filters' => $request->only(['q', 'status'])]);
    }

    public function store(Request $request, TenantContext $context, MaintenanceWorkOrderWorkflow $workflow)
    {
        $data = $request->validate(['maintenance_request_id' => ['nullable', Rule::exists('maintenance_requests', 'id')->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)], 'preventive_intervention_id' => ['nullable', Rule::exists('preventive_interventions', 'id')->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)], 'equipment_id' => ['nullable', Rule::exists('maintenance_equipment', 'id')->where('residence_id', $context->residence()->id)->where('status', 'active')], 'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('organization_id', $context->organization()->id)], 'accepted_quotation_id' => ['nullable', 'integer'], 'supplier_contract_id' => ['nullable', 'integer'], 'assigned_user_id' => ['nullable', 'integer'], 'scope_of_work' => ['required', 'string', 'max:10000'], 'internal_instructions' => ['nullable', 'string'], 'resident_notes' => ['nullable', 'string'], 'planned_start_at' => ['nullable', 'date'], 'planned_end_at' => ['nullable', 'date', 'after_or_equal:planned_start_at'], 'estimated_cost_cents' => ['nullable', 'integer', 'min:0'], 'is_primary' => ['sometimes', 'boolean']]);
        if (! empty($data['accepted_quotation_id'])) {
            $quotation = MaintenanceQuotation::whereKey($data['accepted_quotation_id'])->where('status', 'accepted')->where('maintenance_request_id', $data['maintenance_request_id'])->firstOrFail();
            abort_unless((int) $quotation->supplier_id === (int) $data['supplier_id'], 422);
        }
        $workflow->create($data, $request->user());

        return back()->with('success', __('Bon de travail créé.'));
    }

    public function transition(Request $request, MaintenanceWorkOrder $workOrder, TenantContext $context, MaintenanceWorkOrderWorkflow $workflow)
    {
        $this->scope($workOrder, $context);
        $data = $request->validate(['status' => ['required', Rule::in(['scheduled', 'in_progress', 'completed', 'validated', 'cancelled'])], 'report' => ['nullable', 'string', 'max:10000'], 'actual_cost_cents' => ['nullable', 'integer', 'min:0']]);
        $ability = $data['status'] === 'validated' ? 'validate' : ($data['status'] === 'completed' ? 'complete' : 'update');
        $this->authorize($ability, $workOrder);
        if (array_key_exists('actual_cost_cents', $data) && $workOrder->status !== 'validated' && (int) $workOrder->actual_cost_cents !== (int) $data['actual_cost_cents']) {
            $before = $workOrder->actual_cost_cents;
            $workOrder->update(['actual_cost_cents' => $data['actual_cost_cents']]);
            activity()->performedOn($workOrder)->causedBy($request->user())->withProperties(['organization_id' => $workOrder->organization_id, 'residence_id' => $workOrder->residence_id, 'before_cents' => $before, 'after_cents' => $data['actual_cost_cents']])->log('maintenance_work_order.actual_cost_changed');
        }
        $workflow->transition($workOrder, $data['status'], $request->user(), $data['report'] ?? null);

        return back()->with('success', __('Bon de travail mis à jour.'));
    }

    public function reschedule(Request $request, MaintenanceWorkOrder $workOrder, TenantContext $context)
    {
        $this->scope($workOrder, $context);
        $this->authorize('update', $workOrder);
        abort_unless(in_array($workOrder->status, ['draft', 'scheduled'], true), 422);
        $data = $request->validate(['planned_start_at' => ['required', 'date'], 'planned_end_at' => ['required', 'date', 'after_or_equal:planned_start_at'], 'reason' => ['required', 'string', 'max:2000']]);
        $before = $workOrder->only(['planned_start_at', 'planned_end_at']);
        $workOrder->update(['planned_start_at' => $data['planned_start_at'], 'planned_end_at' => $data['planned_end_at']]);
        activity()->performedOn($workOrder)->causedBy($request->user())->withProperties(['organization_id' => $workOrder->organization_id, 'residence_id' => $workOrder->residence_id, 'before' => $before, 'after' => $workOrder->only(['planned_start_at', 'planned_end_at']), 'reason' => $data['reason']])->log('maintenance_work_order.rescheduled');
        if ($workOrder->request) {
            app(MaintenanceNotificationService::class)->requestEvent($workOrder->request, 'intervention_rescheduled', "work-order:{$workOrder->id}:{$workOrder->updated_at->getTimestamp()}");
        }

        return back()->with('success', __('Intervention replanifiée.'));
    }

    public function createInvoice(Request $request, MaintenanceWorkOrder $workOrder, TenantContext $context, WorkOrderInvoiceService $service)
    {
        $this->scope($workOrder, $context);
        $this->authorize('invoice', $workOrder);
        $data = $request->validate(['financial_exercise_id' => ['required', 'integer'], 'expense_category_id' => ['required', 'integer'], 'supplier_invoice_number' => ['nullable', 'string', 'max:255'], 'invoice_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:invoice_date'], 'total_cents' => ['required', 'integer', 'min:1'], 'amount_justification' => ['nullable', 'string', 'max:2000']]);
        $invoice = $service->createDraft($workOrder, $data, $request->user());

        return redirect()->route('supplier-invoices.show', $invoice)->with('success', __('Brouillon de facture créé.'));
    }

    public function linkInvoice(Request $request, MaintenanceWorkOrder $workOrder, TenantContext $context, WorkOrderInvoiceService $service)
    {
        $this->scope($workOrder, $context);
        $this->authorize('invoice', $workOrder);
        $data = $request->validate(['invoice_id' => ['required', 'integer'], 'amount_justification' => ['nullable', 'string', 'max:2000']]);
        $invoice = SupplierInvoice::whereKey($data['invoice_id'])->firstOrFail();
        $service->link($workOrder, $invoice, $request->user(), $data['amount_justification'] ?? null);

        return back()->with('success', __('Facture liée.'));
    }

    private function scope(MaintenanceWorkOrder $order, TenantContext $context): void
    {
        abort_unless($order->organization_id === $context->organization()->id && $order->residence_id === $context->residence()->id, 404);
    }
}
