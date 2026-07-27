<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Residence;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    public function organization(Request $request, Organization $organization)
    {
        abort_unless($request->user()->belongsToOrganization($organization), 403);
        $request->user()->update(['current_organization_id' => $organization->id, 'current_residence_id' => null]);

        return redirect()->route('dashboard');
    }

    public function residence(Request $request, Residence $residence)
    {
        abort_unless($request->user()->belongsToOrganization($residence->organization_id), 403);
        $membership = $request->user()->organizations()->whereKey($residence->organization_id)->first()?->pivot;
        abort_unless($membership?->all_residences || $request->user()->residences()->whereKey($residence->id)->exists(), 403);
        $request->user()->update(['current_organization_id' => $residence->organization_id, 'current_residence_id' => $residence->id]);

        return redirect()->route('dashboard');
    }
}
