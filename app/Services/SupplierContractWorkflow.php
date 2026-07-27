<?php

namespace App\Services;

use App\Models\SupplierContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupplierContractWorkflow
{
    public function renew(SupplierContract $contract, User $actor, string $startsOn, string $endsOn, string $reason, bool $automatic = false): SupplierContract
    {
        return DB::transaction(function () use ($contract, $actor, $startsOn, $endsOn, $reason, $automatic) {
            $contract = SupplierContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $existing = SupplierContract::query()->where('renewed_from_id', $contract->id)->first();
            if ($existing) {
                return $existing;
            }
            if (! in_array($contract->status, ['active', 'expired'], true) || blank(trim($reason))) {
                throw ValidationException::withMessages(['reason' => __('Le renouvellement exige un contrat éligible et un motif.')]);
            }
            if ($endsOn < $startsOn || ($contract->ends_on && $startsOn <= $contract->ends_on->toDateString())) {
                throw ValidationException::withMessages(['starts_on' => __('La nouvelle période doit suivre la période historique.')]);
            }
            $version = (int) $contract->renewal_version + 1;
            $renewal = $contract->replicate(['status', 'starts_on', 'ends_on', 'terminated_on', 'termination_reason', 'created_at', 'updated_at']);
            $renewal->fill([
                'renewed_from_id' => $contract->id,
                'renewal_version' => $version,
                'reference' => $contract->reference.'/R'.$version,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => 'active',
                'renewal_type' => $automatic ? 'automatic' : 'manual',
                'terminated_on' => null,
                'termination_reason' => null,
            ])->save();
            $contract->update(['status' => 'expired']);
            $attachmentVersion = 1;
            foreach ($contract->attachments()->where('status', 'active')->where('reusable_on_renewal', true)->get() as $attachment) {
                if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
                    continue;
                }
                $target = "supplier-contracts/{$renewal->organization_id}/{$renewal->id}/".basename($attachment->path);
                Storage::disk($attachment->disk)->copy($attachment->path, $target);
                $renewal->attachments()->create($attachment->only(['name', 'disk', 'mime_type', 'size', 'checksum', 'uploaded_by', 'reusable_on_renewal']) + ['path' => $target, 'version' => $attachmentVersion++, 'status' => 'active']);
            }
            activity()->performedOn($renewal)->causedBy($actor)->withProperties(['organization_id' => $contract->organization_id, 'residence_id' => $contract->residence_id, 'renewed_from_id' => $contract->id, 'reason' => trim($reason), 'automatic' => $automatic])->log('supplier_contract.renewed');

            return $renewal;
        });
    }

    public function terminate(SupplierContract $contract, User $actor, string $reason): SupplierContract
    {
        return DB::transaction(function () use ($contract, $actor, $reason) {
            $contract = SupplierContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->status !== 'active' || mb_strlen(trim($reason)) < 5) {
                throw ValidationException::withMessages(['reason' => __('La résiliation exige un motif.')]);
            }
            $contract->update(['status' => 'terminated', 'terminated_on' => today(), 'termination_reason' => trim($reason)]);
            activity()->performedOn($contract)->causedBy($actor)->withProperties(['organization_id' => $contract->organization_id, 'residence_id' => $contract->residence_id, 'reason' => trim($reason)])->log('supplier_contract.terminated');

            return $contract;
        });
    }
}
