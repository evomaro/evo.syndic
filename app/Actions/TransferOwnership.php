<?php

namespace App\Actions;

use App\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferOwnership
{
    public function execute(Lot $lot, array $data): void
    {
        $scaledTotal = collect($data['owners'])->sum(fn ($owner) => (int) round(((float) $owner['percentage']) * 10000));
        if ($scaledTotal !== 1000000 && ! ($data['acknowledge_incomplete'] ?? false)) {
            throw ValidationException::withMessages(['owners' => __('La propriété doit totaliser 100 %, sauf reconnaissance explicite d’une configuration incomplète.')]);
        }
        if (collect($data['owners'])->where('is_primary', true)->count() > 1) {
            throw ValidationException::withMessages(['owners' => __('Un seul propriétaire principal est autorisé.')]);
        }

        DB::transaction(function () use ($lot, $data, $scaledTotal) {
            $date = CarbonImmutable::parse($data['effective_date'])->toDateString();
            $lockedLot = Lot::query()->lockForUpdate()->findOrFail($lot->id);
            if ($lockedLot->ownerships()->whereDate('starts_on', '>', $date)->exists()) {
                throw ValidationException::withMessages(['effective_date' => __('Une propriété future existe déjà. Supprimez ce conflit avant le transfert.')]);
            }
            $current = $lockedLot->activeOwnerships($date)->lockForUpdate()->get();
            foreach ($data['owners'] as $owner) {
                $overlap = $lockedLot->ownerships()->where('contact_id', $owner['contact_id'])
                    ->whereNotIn('id', $current->pluck('id'))
                    ->whereDate('starts_on', '<=', $date)
                    ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
                    ->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['owners' => __('Une période de propriété chevauchante existe déjà.')]);
                }
            }
            $end = CarbonImmutable::parse($date)->subDay()->toDateString();
            foreach ($current as $ownership) {
                if ($end < $ownership->starts_on->toDateString()) {
                    throw ValidationException::withMessages(['effective_date' => __('La date du transfert précède la propriété actuelle.')]);
                }
                $ownership->update(['ends_on' => $end]);
            }
            foreach ($data['owners'] as $owner) {
                $lockedLot->ownerships()->create(['contact_id' => $owner['contact_id'], 'ownership_percentage' => $owner['percentage'], 'is_primary_contact' => $owner['is_primary'] ?? false, 'starts_on' => $date]);
            }
            activity()->performedOn($lockedLot)->causedBy(auth()->user())->withProperties(['organization_id' => $lockedLot->residence->organization_id, 'residence_id' => $lockedLot->residence_id, 'effective_date' => $date, 'new_total' => $scaledTotal / 10000])->log('ownership.transferred');
        });
    }
}
