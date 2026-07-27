<?php

namespace App\Http\Controllers;

use App\Models\Assembly;
use App\Models\GovernanceMandate;
use App\Services\GovernanceMandateService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GovernanceMandateController extends Controller
{
    public function index(TenantContext $c)
    {
        return Inertia::render('Governance/Mandates', ['mandates' => GovernanceMandate::where('organization_id', $c->organization()->id)->where('residence_id', $c->residence()->id)->latest('starts_on')->paginate(30), 'users' => $c->organization()->users()->get(['users.id', 'users.name']), 'contacts' => $c->organization()->contacts()->get(['id', 'first_name', 'last_name', 'company_name', 'type'])]);
    }

    public function store(Request $r, TenantContext $c, GovernanceMandateService $s)
    {
        $d = $r->validate(['user_id' => 'nullable|exists:users,id', 'contact_id' => 'nullable|exists:contacts,id', 'role' => ['required', Rule::in(['syndic', 'deputy_syndic', 'council_member'])], 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'appointing_resolution_id' => ['nullable', Rule::exists('assembly_resolutions', 'id')->where(fn ($q) => $q->whereIn('assembly_id', Assembly::where('residence_id', $c->residence()->id)->pluck('id')))]]);
        $s->create($d + ['organization_id' => $c->organization()->id, 'residence_id' => $c->residence()->id], $r->user());

        return back();
    }

    public function transition(Request $r, GovernanceMandate $mandate, TenantContext $c, GovernanceMandateService $s)
    {
        abort_unless($mandate->organization_id === $c->organization()->id && $mandate->residence_id === $c->residence()->id, 404);
        $d = $r->validate(['status' => 'required|string', 'reason' => 'required|string|min:10|max:2000']);
        $s->transition($mandate, $d['status'], $r->user(), $d['reason']);

        return back();
    }
}
