<?php

namespace App\Http\Controllers;

use App\Actions\RecordOccupancy;
use App\Http\Requests\OccupancyRequest;
use App\Models\Lot;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class OccupancyController extends Controller
{
    public function store(OccupancyRequest $r, Lot $lot, TenantContext $c, RecordOccupancy $action)
    {
        $this->guard($lot, $c);
        $action->execute($lot, $r->validated());

        return back()->with('success', __('Occupancy recorded.'));
    }

    public function close(Request $r, Lot $lot, int $occupancy, TenantContext $c, RecordOccupancy $action)
    {
        $this->guard($lot, $c);
        $data = $r->validate(['ends_on' => 'required|date']);
        $action->close($lot, $occupancy, $data['ends_on']);

        return back();
    }

    private function guard(Lot $lot, TenantContext $c)
    {
        abort_unless($lot->residence_id === $c->residence()->id, 404);
        abort_unless($lot->active,409);
    }
}
