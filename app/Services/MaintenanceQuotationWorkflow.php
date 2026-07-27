<?php

namespace App\Services;

use App\Models\MaintenanceQuotation;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceQuotationWorkflow
{
    public function accept(MaintenanceQuotation $quotation, User $actor): MaintenanceQuotation
    {
        return DB::transaction(function () use ($quotation, $actor) {
            MaintenanceRequest::query()->whereKey($quotation->maintenance_request_id)->lockForUpdate()->firstOrFail();
            $quotation = MaintenanceQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status === 'accepted') {
                return $quotation;
            }
            if ($quotation->status !== 'received' || ($quotation->valid_until && $quotation->valid_until->isPast())) {
                throw ValidationException::withMessages(['status' => __('Ce devis ne peut plus être accepté.')]);
            }
            if (MaintenanceQuotation::query()->where('maintenance_request_id', $quotation->maintenance_request_id)->where('status', 'accepted')->whereKeyNot($quotation->id)->exists()) {
                throw ValidationException::withMessages(['status' => __('Un devis est déjà accepté pour cette demande.')]);
            }
            $quotation->update(['status' => 'accepted', 'accepted_by' => $actor->id, 'accepted_at' => now('UTC')]);
            activity()->performedOn($quotation)->causedBy($actor)->withProperties(['organization_id' => $quotation->organization_id, 'residence_id' => $quotation->residence_id, 'request_id' => $quotation->maintenance_request_id, 'total_cents' => $quotation->total_cents])->log('maintenance_quotation.accepted');

            return $quotation->fresh();
        }, 3);
    }

    public function replace(MaintenanceQuotation $accepted, MaintenanceQuotation $replacement, User $actor, string $reason): MaintenanceQuotation
    {
        return DB::transaction(function () use ($accepted, $replacement, $actor, $reason) {
            MaintenanceRequest::query()->whereKey($accepted->maintenance_request_id)->lockForUpdate()->firstOrFail();
            $accepted = MaintenanceQuotation::query()->whereKey($accepted->id)->lockForUpdate()->firstOrFail();
            if ($accepted->status !== 'accepted' || $accepted->maintenance_request_id !== $replacement->maintenance_request_id || blank($reason) || $accepted->workOrders()->whereHas('invoice', fn ($q) => $q->whereNot('status', 'draft'))->exists()) {
                throw ValidationException::withMessages(['status' => __('Le remplacement de devis est interdit dans cet état.')]);
            }
            $accepted->update(['status' => 'rejected', 'ended_at' => now('UTC'), 'end_reason' => $reason]);

            return $this->accept($replacement, $actor);
        }, 3);
    }
}
