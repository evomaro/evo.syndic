<?php

namespace App\Http\Controllers;

use App\Actions\TransferOwnership;
use App\Http\Requests\OwnershipTransferRequest;
use App\Models\Lot;
use App\Support\TenantContext;

class OwnershipController extends Controller
{
    public function transfer(OwnershipTransferRequest $r, Lot $lot, TenantContext $c, TransferOwnership $action)
    {
        abort_unless($lot->residence_id === $c->residence()->id, 404);
        abort_unless($lot->active, 409);
        $action->execute($lot, $r->validated());

        return back()->with('success', __('Ownership transferred.'));
    }
}
