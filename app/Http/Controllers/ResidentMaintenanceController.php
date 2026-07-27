<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceCategory;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ResidentMaintenanceController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $this->member($request, $context);
        $requests = MaintenanceRequest::with('category:id,name_fr,name_ar')->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->where('reporter_user_id', $request->user()->id)->latest()->paginate(15);
        $planned = MaintenanceWorkOrder::query()->where('residence_id', $context->residence()->id)->whereIn('status', ['scheduled', 'in_progress'])->whereNotNull('resident_notes')->where('resident_notes', '!=', '')->orderBy('planned_start_at')->get(['id', 'reference', 'resident_notes', 'planned_start_at', 'planned_end_at', 'status']);

        return Inertia::render('Portal/Maintenance/Index', ['requests' => $requests, 'planned' => $planned, 'categories' => MaintenanceCategory::where('organization_id', $context->organization()->id)->where('active', true)->orderBy('sort_order')->get()]);
    }

    public function show(Request $request, MaintenanceRequest $maintenanceRequest, TenantContext $context)
    {
        $this->member($request, $context);
        abort_unless($maintenanceRequest->organization_id === $context->organization()->id && $maintenanceRequest->residence_id === $context->residence()->id && $maintenanceRequest->reporter_user_id === $request->user()->id, 404);
        $maintenanceRequest->load(['category:id,name_fr,name_ar', 'transitions' => fn ($q) => $q->select(['id', 'maintenance_request_id', 'from_status', 'to_status', 'transitioned_at']), 'updates' => fn ($q) => $q->where('visibility', 'resident')->whereNull('archived_at')->select(['id', 'maintenance_request_id', 'body', 'created_at']), 'attachments' => fn ($q) => $q->where('visibility', 'resident')->whereNull('archived_at')->select(['id', 'attachable_type', 'attachable_id', 'kind', 'name', 'created_at'])]);
        $safe = $maintenanceRequest->makeHidden(['contact_details']);

        return Inertia::render('Portal/Maintenance/Show', ['maintenanceRequest' => $safe]);
    }

    private function member(Request $request, TenantContext $context): void
    {
        abort_unless($request->user()->residences()->whereKey($context->residence()->id)->exists() && $request->user()->organizations()->whereKey($context->organization()->id)->exists(), 403);
    }
}
