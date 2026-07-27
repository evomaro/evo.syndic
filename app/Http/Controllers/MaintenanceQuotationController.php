<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceQuotation;
use App\Models\MaintenanceRequest;
use App\Models\SupplierContract;
use App\Services\MaintenanceQuotationWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceQuotationController extends Controller
{
    public function store(Request $request, MaintenanceRequest $maintenanceRequest, TenantContext $context)
    {
        $this->scope($maintenanceRequest, $context);
        $data = $request->validate(['supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $context->organization()->id)], 'supplier_contract_id' => ['nullable', 'integer'], 'supplier_reference' => ['nullable', 'string', 'max:255'], 'subtotal_cents' => ['required', 'integer', 'min:0'], 'tax_cents' => ['required', 'integer', 'min:0'], 'total_cents' => ['required', 'integer', 'min:1'], 'submitted_on' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:submitted_on'], 'internal_notes' => ['nullable', 'string']]);
        abort_if($data['total_cents'] !== $data['subtotal_cents'] + $data['tax_cents'], 422, __('Les montants du devis sont incohérents.'));
        if (! empty($data['supplier_contract_id'])) {
            abort_unless(SupplierContract::whereKey($data['supplier_contract_id'])->where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->where('supplier_id', $data['supplier_id'])->exists(), 422);
        }
        $quotation = $maintenanceRequest->quotations()->create($data + ['organization_id' => $maintenanceRequest->organization_id, 'residence_id' => $maintenanceRequest->residence_id]);
        activity()->performedOn($quotation)->causedBy($request->user())->withProperties(['organization_id' => $quotation->organization_id, 'residence_id' => $quotation->residence_id, 'request_id' => $maintenanceRequest->id, 'supplier_id' => $quotation->supplier_id, 'total_cents' => $quotation->total_cents])->log('maintenance_quotation.received');

        return back()->with('success', __('Devis enregistré.'));
    }

    public function accept(MaintenanceQuotation $quotation, TenantContext $context, MaintenanceQuotationWorkflow $workflow)
    {
        abort_unless($quotation->organization_id === $context->organization()->id && $quotation->residence_id === $context->residence()->id, 404);
        $workflow->accept($quotation, request()->user());

        return back()->with('success', __('Devis accepté.'));
    }

    public function replace(Request $request, MaintenanceQuotation $quotation, TenantContext $context, MaintenanceQuotationWorkflow $workflow)
    {
        abort_unless($quotation->organization_id === $context->organization()->id && $quotation->residence_id === $context->residence()->id, 404);
        $data = $request->validate(['replacement_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $replacement = MaintenanceQuotation::query()->whereKey($data['replacement_id'])
            ->where('organization_id', $context->organization()->id)
            ->where('residence_id', $context->residence()->id)
            ->where('maintenance_request_id', $quotation->maintenance_request_id)
            ->firstOrFail();
        $workflow->replace($quotation, $replacement, $request->user(), $data['reason']);

        return back()->with('success', __('Devis accepté remplacé.'));
    }

    private function scope(MaintenanceRequest $request, TenantContext $context): void
    {
        abort_unless($request->organization_id === $context->organization()->id && $request->residence_id === $context->residence()->id, 404);
    }
}
