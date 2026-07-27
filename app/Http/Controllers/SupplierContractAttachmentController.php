<?php

namespace App\Http\Controllers;

use App\Models\SupplierContract;
use App\Models\SupplierContractAttachment;
use App\Services\SupplierContractAttachmentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SupplierContractAttachmentController extends Controller
{
    public function store(Request $request, SupplierContract $contract, TenantContext $context, SupplierContractAttachmentService $service)
    {
        $this->contract($contract, $context);
        $this->authorize('update', $contract);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:20480'], 'reusable_on_renewal' => ['sometimes', 'boolean'], 'replaces_id' => ['nullable', 'integer']]);
        $replaces = isset($data['replaces_id']) ? $contract->attachments()->whereKey($data['replaces_id'])->firstOrFail() : null;
        $service->upload($contract, $data['file'], $request->user(), (bool) ($data['reusable_on_renewal'] ?? false), $replaces);

        return back()->with('success', __('Pièce jointe enregistrée.'));
    }

    public function download(SupplierContractAttachment $attachment, TenantContext $context, SupplierContractAttachmentService $service)
    {
        $attachment->load('contract');
        $this->contract($attachment->contract, $context);
        $this->authorize('view', $attachment->contract);

        return $service->download($attachment, request()->user());
    }

    public function destroy(SupplierContractAttachment $attachment, TenantContext $context, SupplierContractAttachmentService $service)
    {
        $attachment->load('contract');
        $this->contract($attachment->contract, $context);
        $this->authorize('update', $attachment->contract);
        $service->archive($attachment, request()->user());

        return back()->with('success', __('Pièce jointe archivée.'));
    }

    private function contract(SupplierContract $contract, TenantContext $context): void
    {
        abort_unless($contract->organization_id === $context->organization()->id && $contract->residence_id === $context->residence()->id, 404);
    }
}
