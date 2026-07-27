<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyElectorate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ElectorateSnapshotService
{
    private const DENOMINATOR = 10000000000;

    public function generate(Assembly $assembly, User $actor): int
    {
        return DB::transaction(function () use ($assembly, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->lockForUpdate()->firstOrFail();
            if ($assembly->electorate()->exists()) {
                return $assembly->electorate()->count();
            }
            if (! in_array($assembly->status, ['preparing', 'convocation_issued'], true)) {
                throw ValidationException::withMessages(['status' => __('Le corps électoral ne peut être figé à cette étape.')]);
            }
            $ownerships = DB::table('lot_ownerships')
                ->join('lots', 'lots.id', '=', 'lot_ownerships.lot_id')
                ->join('contacts', 'contacts.id', '=', 'lot_ownerships.contact_id')
                ->leftJoin('allocation_keys', function ($join) {
                    $join->on('allocation_keys.residence_id', '=', 'lots.residence_id')->where('allocation_keys.is_default', true);
                })
                ->leftJoin('lot_allocation_values', function ($join) {
                    $join->on('lot_allocation_values.lot_id', '=', 'lots.id')->on('lot_allocation_values.allocation_key_id', '=', 'allocation_keys.id');
                })
                ->where('lots.residence_id', $assembly->residence_id)->where('lots.active', true)
                ->whereDate('lot_ownerships.starts_on', '<=', $assembly->meeting_date)
                ->where(fn ($q) => $q->whereNull('lot_ownerships.ends_on')->orWhereDate('lot_ownerships.ends_on', '>=', $assembly->meeting_date))
                ->orderBy('lot_ownerships.contact_id')->orderBy('lots.id')
                ->get(['lot_ownerships.id as ownership_id', 'lot_ownerships.contact_id', 'lot_ownerships.ownership_percentage', 'lots.id as lot_id', 'lots.reference', 'lot_allocation_values.value', 'contacts.type', 'contacts.first_name', 'contacts.last_name', 'contacts.company_name', 'contacts.primary_email', 'contacts.primary_phone', 'contacts.address', 'contacts.preferred_language']);
            if ($ownerships->isEmpty()) {
                throw ValidationException::withMessages(['ownership' => __('Aucun copropriétaire actif ne peut être figé.')]);
            }
            $rows = $ownerships->groupBy('contact_id')->map(function ($items) {
                $first = $items->first();
                $weight = $items->sum(fn ($row) => $this->decimalInteger((string) ($row->value ?? '0'), 4) * $this->decimalInteger((string) $row->ownership_percentage, 4));
                $name = $first->type === 'company' ? $first->company_name : trim($first->first_name.' '.$first->last_name);

                return ['contact_id' => (int) $first->contact_id, 'name' => $name, 'weight' => (int) $weight, 'items' => $items];
            })->values();
            $total = (int) $rows->sum('weight');
            $rows = $rows->map(function ($row) use ($total) {
                if ($row['weight'] > ($total - $row['weight']) / 2) {
                    $row['weight'] = intdiv($total, 2);
                }

                return $row;
            });
            foreach ($rows as $row) {
                $first = $row['items']->first();
                AssemblyElectorate::create([
                    'organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'assembly_id' => $assembly->id,
                    'contact_id' => $row['contact_id'], 'entitlement_key' => 'contact:'.$row['contact_id'],
                    'lot_ids' => $row['items']->pluck('lot_id')->map(fn ($id) => (int) $id)->all(),
                    'ownership_fractions' => $row['items']->map(fn ($item) => ['lot_id' => (int) $item->lot_id, 'reference' => $item->reference, 'percentage' => (string) $item->ownership_percentage, 'allocation_value' => (string) ($item->value ?? '0')])->all(),
                    'voting_weight_numerator' => $row['weight'], 'voting_weight_denominator' => self::DENOMINATOR,
                    'contact_name_snapshot' => $row['name'], 'email_snapshot' => $first->primary_email, 'phone_snapshot' => $first->primary_phone,
                    'address_snapshot' => $first->address, 'preferred_language' => in_array($first->preferred_language, ['fr', 'ar'], true) ? $first->preferred_language : 'fr',
                    'source_ownership_ids' => $row['items']->pluck('ownership_id')->map(fn ($id) => (int) $id)->all(),
                    'generated_after_cutoff' => now()->greaterThan($assembly->convocation_deadline_at), 'snapshotted_at' => now('UTC'),
                ]);
            }
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'entitlements' => $rows->count(), 'total_weight_numerator' => $rows->sum('weight'), 'weight_denominator' => self::DENOMINATOR])->log('governance.electorate_frozen');

            return $rows->count();
        });
    }

    public function correct(AssemblyElectorate $electorate, array $changes, User $actor, string $reason): AssemblyElectorate
    {
        return DB::transaction(function () use ($electorate, $changes, $actor, $reason) {
            $electorate = AssemblyElectorate::query()->whereKey($electorate->id)->lockForUpdate()->firstOrFail();
            if ($electorate->ballots()->whereNotNull('finalized_at')->exists() || $electorate->assembly->minutes?->status === 'signed') {
                throw ValidationException::withMessages(['snapshot' => __('Un snapshot utilisé par un vote finalisé ou un procès-verbal signé est immuable.')]);
            }
            if (mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['reason' => __('Un motif détaillé est obligatoire.')]);
            }
            $allowed = collect($changes)->only(['eligibility_status', 'restriction_reason', 'voting_weight_numerator'])->all();
            $before = $electorate->only(array_keys($allowed));
            $electorate->update($allowed + ['snapshot_version' => $electorate->snapshot_version + 1]);
            $electorate->corrections()->create(['actor_id' => $actor->id, 'before_payload' => $before, 'after_payload' => $electorate->fresh()->only(array_keys($allowed)), 'reason' => trim($reason), 'corrected_at' => now('UTC')]);
            activity()->performedOn($electorate)->causedBy($actor)->withProperties(['organization_id' => $electorate->organization_id, 'residence_id' => $electorate->residence_id, 'reason' => trim($reason)])->log('governance.electorate_corrected');

            return $electorate->fresh();
        });
    }

    private function decimalInteger(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return ((int) $whole * (10 ** $scale)) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');
    }
}
