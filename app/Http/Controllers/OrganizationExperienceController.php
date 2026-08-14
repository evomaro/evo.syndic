<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationExperienceController extends Controller
{
    public function update(Request $request, TenantContext $context)
    {
        $organization = $context->organization();
        abort_unless($request->user()->canInOrganization('manage_organization', $organization), 403);
        $data = $request->validate(['experience_mode' => ['required', Rule::in(['essential', 'pro'])]]);
        $organization->update($data);
        activity()->performedOn($organization)->causedBy($request->user())->withProperties(['organization_id' => $organization->id, 'experience_mode' => $data['experience_mode']])->log('organization.experience_changed');

        return to_route('dashboard')->with('success', __('Expérience mise à jour.'));
    }
}
