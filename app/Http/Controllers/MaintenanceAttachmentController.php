<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceQuotation;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Models\PreventiveIntervention;
use App\Services\MaintenanceAttachmentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceAttachmentController extends Controller
{
    private const TYPES = ['request' => MaintenanceRequest::class, 'equipment' => MaintenanceEquipment::class, 'quotation' => MaintenanceQuotation::class, 'work_order' => MaintenanceWorkOrder::class, 'intervention' => PreventiveIntervention::class];

    public function store(Request $request, string $type, int $id, TenantContext $context, MaintenanceAttachmentService $service)
    {
        $entity = $this->entity($type, $id, $context);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'], 'kind' => ['required', Rule::in(['evidence', 'internal', 'quotation', 'work_order', 'before', 'after', 'equipment', 'preventive'])], 'visibility' => ['required', Rule::in(['internal', 'resident'])], 'replaces_id' => ['nullable', 'integer']]);
        $resident = $type === 'request' && $entity->reporter_user_id === $request->user()->id && ! $request->user()->canInOrganization('manage_maintenance_requests', $context->organization());
        if ($resident) {
            $data['kind'] = 'evidence';
            $data['visibility'] = 'resident';
        } else {
            abort_unless($request->user()->canInOrganization('download_internal_maintenance_attachments', $context->organization()), 403);
        }
        $replaces = isset($data['replaces_id']) ? MaintenanceAttachment::findOrFail($data['replaces_id']) : null;
        if ($resident && $replaces) {
            abort_unless($replaces->attachable_type === $entity->getMorphClass()
                && $replaces->attachable_id === $entity->id
                && $replaces->visibility === 'resident'
                && $replaces->uploaded_by === $request->user()->id, 404);
        }
        $service->upload($entity, $data['file'], $data['kind'], $data['visibility'], $request->user(), $replaces);

        return back()->with('success', __('Pièce jointe enregistrée.'));
    }

    public function download(MaintenanceAttachment $attachment, TenantContext $context, MaintenanceAttachmentService $service)
    {
        abort_unless($attachment->organization_id === $context->organization()->id && $attachment->residence_id === $context->residence()->id, 404);
        $entity = $attachment->attachable;
        $resident = $entity instanceof MaintenanceRequest && $entity->reporter_user_id === request()->user()->id && request()->user()->residences()->whereKey($attachment->residence_id)->exists();
        abort_unless(($resident && $attachment->visibility === 'resident') || request()->user()->canInOrganization('download_internal_maintenance_attachments', $context->organization()), 403);

        return $service->download($attachment, request()->user());
    }

    private function entity(string $type, int $id, TenantContext $context)
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $entity = self::TYPES[$type]::findOrFail($id);
        abort_unless($entity->organization_id === $context->organization()->id && $entity->residence_id === $context->residence()->id, 404);

        return $entity;
    }
}
