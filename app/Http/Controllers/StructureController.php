<?php

namespace App\Http\Controllers;

use App\Http\Requests\LotRequest;
use App\Models\Building;
use App\Models\Entrance;
use App\Models\Floor;
use App\Models\Lot;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StructureController extends Controller
{
    public function index(Request $r, TenantContext $c)
    {
        $res = $c->residence();
        $lots = $res->lots()->with(['building:id,name', 'entrance:id,name', 'floor:id,name'])->when($r->search, fn ($q, $v) => $q->where(fn ($x) => $x->where('reference', 'like', "%$v%")->orWhere('lot_number', 'like', "%$v%")))->when($r->type, fn ($q, $v) => $q->where('type', $v))->when($r->building_id, fn ($q, $v) => $q->where('building_id', $v))->paginate(20)->withQueryString();

        return Inertia::render('Structure/Index', ['buildings' => $res->buildings()->with(['entrances', 'floors'])->orderBy('sort_order')->get(), 'lots' => $lots, 'filters' => $r->only(['search', 'type', 'building_id'])]);
    }

    public function building(Request $r, TenantContext $c)
    {
        $data = $r->validate(['name' => 'required|string|max:255', 'code' => ['required', 'max:50', Rule::unique('buildings')->where('residence_id', $c->residence()->id)]]);
        $c->residence()->buildings()->create($data);

        return back()->with('success', __('Building added.'));
    }

    public function entrance(Request $r, Building $building, TenantContext $c)
    {
        $this->guardBuilding($building, $c);
        $data = $r->validate(['name' => 'required|string', 'code' => ['required', Rule::unique('entrances')->where('building_id', $building->id)]]);
        $building->entrances()->create($data);

        return back();
    }

    public function floor(Request $r, Building $building, TenantContext $c)
    {
        $this->guardBuilding($building, $c);
        $data = $r->validate(['name' => 'required|string', 'level_number' => 'nullable|integer', 'entrance_id' => ['nullable', Rule::exists('entrances', 'id')->where('building_id', $building->id)]]);
        $building->floors()->create($data);

        return back();
    }

    public function lot(LotRequest $r, TenantContext $c)
    {
        $data = $r->validated();
        $this->consistent($data, $c);
        $c->residence()->lots()->create($data);

        return back()->with('success', __('Lot added.'));
    }

    public function updateLot(LotRequest $r, Lot $lot, TenantContext $c)
    {
        abort_unless($lot->residence_id === $c->residence()->id, 404);
        abort_unless($lot->active, 409);
        $data = $r->validated();
        $this->consistent($data, $c);
        $lot->update($data);

        return back();
    }

    public function bulk(Request $r, TenantContext $c)
    {
        $data = $r->validate(['prefix' => 'required|string|max:30', 'starting_number' => 'required|integer|min:0', 'quantity' => 'required|integer|min:1|max:500', 'building_id' => ['nullable', Rule::exists('buildings', 'id')->where('residence_id', $c->residence()->id)], 'floor_id' => 'nullable|exists:floors,id', 'type' => ['required', Rule::in(['apartment', 'villa', 'shop', 'office', 'garage', 'parking', 'storage', 'other'])], 'confirm' => 'accepted']);
        DB::transaction(function () use ($data, $c) {
            for ($i = 0; $i < $data['quantity']; $i++) {
                $n = $data['starting_number'] + $i;
                $c->residence()->lots()->create(['reference' => $data['prefix'].$n, 'lot_number' => (string) $n, 'type' => $data['type'], 'building_id' => $data['building_id'] ?? null, 'floor_id' => $data['floor_id'] ?? null]);
            }
        });

        return back()->with('success', __('Lots created.'));
    }

    private function guardBuilding(Building $b, TenantContext $c)
    {
        abort_unless($b->residence_id === $c->residence()->id, 404);
    }

    private function consistent(array $d, TenantContext $c)
    {
        if (! empty($d['building_id'])) {
            $b = Building::findOrFail($d['building_id']);
            $this->guardBuilding($b, $c);
            foreach (['entrance_id' => Entrance::class, 'floor_id' => Floor::class] as $key => $model) {
                if (! empty($d[$key])) {
                    abort_unless($model::whereKey($d[$key])->where('building_id', $b->id)->exists(), 422);
                }
            }
        }
    }
}
