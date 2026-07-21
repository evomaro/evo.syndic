<?php

namespace App\Http\Controllers;

use App\Models\AllocationKey;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AllocationController extends Controller
{
    public function index(TenantContext $c)
    {
        $r = $c->residence();
        $keys = $r->allocationKeys()->with(['lots:id', 'values'])->get()->map(function ($key) use ($r) {
            $assigned = $key->values->sum(fn ($value) => (float) $value->value);
            $scopeIds = $key->applies_to_all_lots ? $r->lots()->where('active', true)->pluck('id') : $key->lots->pluck('id');
            $key->setAttribute('assigned_total', number_format($assigned, 4, '.', ''));
            $key->setAttribute('difference', $key->expected_total === null ? null : number_format((float) $key->expected_total - $assigned, 4, '.', ''));
            $key->setAttribute('missing_lots', $scopeIds->diff($key->values->pluck('lot_id'))->count());

            return $key;
        });

        return Inertia::render('Allocations/Index', ['keys' => $keys, 'lots' => $r->lots()->where('active', true)->with('allocationValues')->orderBy('reference')->paginate(50)]);
    }

    public function store(Request $r, TenantContext $c)
    {
        $data = $r->validate(['name' => 'required|string', 'code' => ['required', 'alpha_dash', Rule::unique('allocation_keys')->where('residence_id', $c->residence()->id)], 'type' => ['required', Rule::in(['general', 'special'])], 'expected_total' => 'nullable|decimal:0,4|min:0', 'applies_to_all_lots' => 'boolean', 'lot_ids' => 'array', 'lot_ids.*' => ['integer', 'distinct', Rule::exists('lots', 'id')->where('residence_id', $c->residence()->id)]]);
        if ($data['type'] === 'general') {
            $data['applies_to_all_lots'] = true;
        }
        if ($data['type'] === 'special' && ! ($data['applies_to_all_lots'] ?? false) && empty($data['lot_ids'])) {
            throw ValidationException::withMessages(['lot_ids' => __('Sélectionnez au moins un lot pour cette clé spéciale.')]);
        }
        DB::transaction(function () use ($c, $data) {
            $key = $c->residence()->allocationKeys()->create(collect($data)->except('lot_ids')->all());
            $key->lots()->sync($data['lot_ids'] ?? []);
        });

        return back();
    }

    public function values(Request $r, AllocationKey $allocationKey, TenantContext $c)
    {
        abort_unless($allocationKey->residence_id === $c->residence()->id, 404);
        $data = $r->validate(['values' => 'required|array', 'values.*.lot_id' => ['required', 'distinct', Rule::exists('lots', 'id')->where('residence_id', $c->residence()->id)], 'values.*.value' => 'required|decimal:0,4|min:0']);
        if (! $allocationKey->applies_to_all_lots) {
            $allowed = $allocationKey->lots()->pluck('lots.id')->all();
            abort_if(collect($data['values'])->pluck('lot_id')->diff($allowed)->isNotEmpty(), 422);
        }
        DB::transaction(fn () => collect($data['values'])->each(fn ($v) => $allocationKey->values()->updateOrCreate(['lot_id' => $v['lot_id']], ['value' => $v['value']])));

        return back()->with('success', __('Allocation values saved.'));
    }

    public function bulk(Request $request, AllocationKey $allocationKey, TenantContext $context)
    {
        abort_unless($allocationKey->residence_id === $context->residence()->id, 404);
        $data = $request->validate(['paste' => 'required|string|max:200000']);
        $parsed = collect(preg_split('/\R/u', trim($data['paste'])))->filter()->map(function ($line, $index) {
            $columns = preg_split('/[\t;,]/u', trim($line));
            if (count($columns) !== 2 || ! preg_match('/^\d+(?:[.,]\d{1,4})?$/', trim($columns[1]))) {
                throw ValidationException::withMessages(['paste' => __('Ligne :line invalide. Utilisez référence + tabulation + valeur.', ['line' => $index + 1])]);
            }

            return ['reference' => trim($columns[0]), 'value' => str_replace(',', '.', trim($columns[1]))];
        });
        if ($parsed->pluck('reference')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['paste' => __('Les références de lots doivent être uniques.')]);
        }
        $lots = $context->residence()->lots()->whereIn('reference', $parsed->pluck('reference'))->get()->keyBy('reference');
        if ($lots->count() !== $parsed->count()) {
            throw ValidationException::withMessages(['paste' => __('Une ou plusieurs références sont inconnues dans cette résidence.')]);
        }
        if (! $allocationKey->applies_to_all_lots && $lots->pluck('id')->diff($allocationKey->lots()->pluck('lots.id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['paste' => __('Un lot ne fait pas partie de cette clé spéciale.')]);
        }
        DB::transaction(fn () => $parsed->each(fn ($row) => $allocationKey->values()->updateOrCreate(['lot_id' => $lots[$row['reference']]->id], ['value' => $row['value']])));

        return back()->with('success', __('Valeurs importées.'));
    }
}
