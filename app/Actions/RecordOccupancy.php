<?php

namespace App\Actions;

use App\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordOccupancy
{
    public function execute(Lot $lot, array $data): void
    {
        DB::transaction(function () use ($lot, $data) {
            $locked = Lot::query()->lockForUpdate()->findOrFail($lot->id);
            $end = $data['ends_on'] ?? '9999-12-31';
            $overlap = $locked->occupancies()->where('contact_id', $data['contact_id'])
                ->whereDate('starts_on', '<=', $end)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $data['starts_on']))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => __('Cette personne possède déjà une période d’occupation chevauchante.')]);
            }
            if (($data['is_primary_occupant'] ?? false) && $locked->activeOccupancies($data['starts_on'])->where('is_primary_occupant', true)->exists()) {
                throw ValidationException::withMessages(['is_primary_occupant' => __('Un occupant principal existe déjà pour cette période.')]);
            }
            $locked->occupancies()->create($data);
            $this->refreshStatus($locked);
        });
    }

    public function close(Lot $lot, int $occupancyId, string $date): void
    {
        DB::transaction(function () use ($lot, $occupancyId, $date) {
            $occupancy = $lot->occupancies()->lockForUpdate()->findOrFail($occupancyId);
            if (CarbonImmutable::parse($date)->lt($occupancy->starts_on)) {
                throw ValidationException::withMessages(['ends_on' => __('La date de fin ne peut pas précéder la date de début.')]);
            }
            $occupancy->update(['ends_on' => $date]);
            $this->refreshStatus($lot->fresh());
        });
    }

    private function refreshStatus(Lot $lot): void
    {
        $types = $lot->activeOccupancies()->pluck('type');
        $status = $types->contains('tenant') ? 'rented' : ($types->contains('owner') ? 'owner_occupied' : ($types->isNotEmpty() ? 'other' : 'vacant'));
        $lot->update(['occupancy_status' => $status]);
    }
}
