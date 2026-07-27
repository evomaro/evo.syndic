<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Services\MaintenanceNotificationService;
use App\Services\MaintenanceRequestWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceRequestActionController extends Controller
{
    public function transition(Request $httpRequest, MaintenanceRequest $maintenanceRequest, TenantContext $context, MaintenanceRequestWorkflow $workflow)
    {
        $this->scope($maintenanceRequest, $context);
        $this->authorize('transition', $maintenanceRequest);
        $data = $httpRequest->validate(['status' => ['required', Rule::in(['submitted', 'under_review', 'approved', 'rejected', 'in_progress', 'resolved', 'closed', 'cancelled'])], 'reason' => ['nullable', 'string', 'max:5000'], 'idempotency_key' => ['required', 'string', 'max:64']]);
        $workflow->transition($maintenanceRequest, $data['status'], $httpRequest->user(), $data['reason'] ?? null, $data['idempotency_key']);

        return back()->with('success', __('Statut de la demande mis à jour.'));
    }

    public function assign(Request $request, MaintenanceRequest $maintenanceRequest, TenantContext $context)
    {
        $this->scope($maintenanceRequest, $context);
        $this->authorize('assign', $maintenanceRequest);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'role' => ['required', Rule::in(['responsible', 'employee'])]]);
        $member = $context->organization()->users()->whereKey($data['user_id'])->firstOrFail();
        abort_unless($member->pivot->all_residences || $member->residences()->whereKey($maintenanceRequest->residence_id)->exists(), 422);
        $maintenanceRequest->assignments()->where('role', $data['role'])->whereNull('ended_at')->update(['ended_at' => now('UTC'), 'ended_by' => $request->user()->id]);
        $assignment = $maintenanceRequest->assignments()->create(['assigned_user_id' => $member->id, 'role' => $data['role'], 'assigned_by' => $request->user()->id, 'assigned_at' => now('UTC')]);
        activity()->performedOn($maintenanceRequest)->causedBy($request->user())->withProperties(['organization_id' => $maintenanceRequest->organization_id, 'residence_id' => $maintenanceRequest->residence_id, 'assignment_id' => $assignment->id, 'user_id' => $member->id, 'role' => $data['role']])->log('maintenance_request.assigned');
        app(MaintenanceNotificationService::class)->requestEvent($maintenanceRequest, 'assignment_changed', "assignment:{$assignment->id}");

        return back()->with('success', __('Affectation mise à jour.'));
    }

    public function update(Request $request, MaintenanceRequest $maintenanceRequest, TenantContext $context)
    {
        $this->scope($maintenanceRequest, $context);
        $this->authorize('update', $maintenanceRequest);
        abort_unless(in_array($maintenanceRequest->status, ['draft', 'submitted', 'under_review'], true), 422);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:10000'], 'location' => ['nullable', 'string', 'max:255'], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'observed_on' => ['nullable', 'date'], 'contact_method' => ['nullable', Rule::in(['email', 'phone', 'app'])], 'contact_details' => ['nullable', 'string', 'max:255'], 'contact_visible_to_assignees' => ['sometimes', 'boolean']]);
        $safeKeys = ['title', 'location', 'priority', 'observed_on', 'contact_method', 'contact_visible_to_assignees'];
        $before = $maintenanceRequest->only($safeKeys);
        $maintenanceRequest->update($data);
        activity()->performedOn($maintenanceRequest)->causedBy($request->user())->withProperties(['organization_id' => $maintenanceRequest->organization_id, 'residence_id' => $maintenanceRequest->residence_id, 'before' => $before, 'after' => $maintenanceRequest->only($safeKeys)])->log('maintenance_request.updated');

        return back()->with('success', __('Demande mise à jour.'));
    }

    public function updateNote(Request $request, MaintenanceRequest $maintenanceRequest, TenantContext $context)
    {
        $this->scope($maintenanceRequest, $context);
        $this->authorize('view', $maintenanceRequest);
        $isResident = $maintenanceRequest->reporter_user_id === $request->user()->id && ! $request->user()->canInOrganization('manage_maintenance_requests', $context->organization());
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'visibility' => ['required', Rule::in(['internal', 'resident'])]]);
        if ($isResident) {
            $data['visibility'] = 'resident';
        }
        $update = $maintenanceRequest->updates()->create($data + ['author_id' => $request->user()->id]);
        activity()->performedOn($update)->causedBy($request->user())->withProperties(['organization_id' => $maintenanceRequest->organization_id, 'residence_id' => $maintenanceRequest->residence_id, 'visibility' => $data['visibility']])->log('maintenance_request.update_added');

        return back()->with('success', __('Mise à jour publiée.'));
    }

    private function scope(MaintenanceRequest $request, TenantContext $context): void
    {
        abort_unless($request->organization_id === $context->organization()->id && $request->residence_id === $context->residence()->id, 404);
    }
}
