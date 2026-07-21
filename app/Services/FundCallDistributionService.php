<?php

namespace App\Services;

use App\Models\FundCallLine;
use App\Models\Lot;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FundCallDistributionService
{
    public function distribute(FundCallLine $line): array
    {
        $lots = $this->targetLots($line);
        if ($lots->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('Aucun lot éligible pour la ligne.')]);
        }

        if ($line->distribution_method === 'manual') {
            $manual = collect($line->manual_allocations)->mapWithKeys(fn ($row) => [(int) $row['lot_id'] => Money::cents((string) $row['amount'])]);
            if ($manual->keys()->diff($lots->pluck('id'))->isNotEmpty() || $manual->sum() !== (int) $line->amount_cents) {
                throw ValidationException::withMessages(['lines' => __('La répartition manuelle doit correspondre exactement au total.')]);
            }

            return $lots->map(fn ($lot) => ['lot' => $lot, 'amount_cents' => (int) $manual->get($lot->id, 0), 'value' => null, 'total' => null, 'rounding_adjustment_cents' => 0])->filter(fn ($r) => $r['amount_cents'] > 0)->values()->all();
        }

        if ($line->distribution_method === 'fixed') {
            $amount = (int) $line->fixed_amount_cents;

            return $lots->map(fn ($lot) => ['lot' => $lot, 'amount_cents' => $amount, 'value' => null, 'total' => null, 'rounding_adjustment_cents' => 0])->all();
        }

        $weights = $line->distribution_method === 'equal'
            ? $lots->mapWithKeys(fn ($lot) => [$lot->id => 1])
            : $this->allocationWeights($line, $lots);
        $totalWeight = $weights->sum();
        if ($totalWeight <= 0) {
            throw ValidationException::withMessages(['lines' => __('Les tantièmes sont manquants ou nuls pour cette répartition.')]);
        }

        $total = (int) $line->amount_cents;
        $rows = $lots->sortBy('id')->values()->map(function ($lot) use ($weights, $total, $totalWeight) {
            $numerator = $total * (int) $weights[$lot->id];

            return ['lot' => $lot, 'amount_cents' => intdiv($numerator, $totalWeight), 'remainder' => $numerator % $totalWeight, 'value' => $weights[$lot->id], 'total' => $totalWeight, 'rounding_adjustment_cents' => 0];
        });
        $remaining = $total - $rows->sum('amount_cents');
        $rankedIds = $rows->sort(fn ($a, $b) => $b['remainder'] <=> $a['remainder'] ?: $a['lot']->id <=> $b['lot']->id)->pluck('lot.id')->all();

        return $rows->map(function ($row) use (&$remaining, $rankedIds) {
            if ($remaining > 0 && array_search($row['lot']->id, $rankedIds, true) < $remaining) {
                $row['amount_cents']++;
                $row['rounding_adjustment_cents'] = 1;
            }
            unset($row['remainder']);

            return $row;
        })->all();
    }

    private function targetLots(FundCallLine $line): Collection
    {
        $query = Lot::query()->where('residence_id', $line->fundCall->residence_id)->where('active', true);
        $ids = collect($line->target_ids)->map(fn ($id) => (int) $id);
        if ($line->target_type === 'lots') {
            $query->whereIn('id', $ids);
        }
        if ($line->target_type === 'buildings') {
            $query->whereIn('building_id', $ids);
        }
        if ($line->target_type === 'lot_types') {
            $query->whereIn('type', $line->target_ids ?? []);
        }

        return $query->orderBy('id')->get();
    }

    private function allocationWeights(FundCallLine $line, Collection $lots): Collection
    {
        if (! $line->allocation_key_id) {
            throw ValidationException::withMessages(['lines' => __('Une clé de répartition est requise.')]);
        }
        $values = $line->allocationKey()->firstOrFail()->values()->whereIn('lot_id', $lots->pluck('id'))->pluck('value', 'lot_id');
        if ($values->count() !== $lots->count()) {
            throw ValidationException::withMessages(['lines' => __('Des tantièmes sont manquants pour les lots ciblés.')]);
        }

        return $lots->mapWithKeys(fn ($lot) => [$lot->id => Money::weight((string) $values[$lot->id])]);
    }
}
