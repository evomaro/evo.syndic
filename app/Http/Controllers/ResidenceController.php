<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResidenceRequest;
use App\Models\Residence;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ResidenceController extends Controller
{
    public function index(TenantContext $c)
    {
        return Inertia::render('Residences/Index', ['residences' => $c->organization()->residences()->with('media')->withCount(['buildings', 'lots'])->paginate(15)]);
    }

    public function create()
    {
        return Inertia::render('Residences/Form');
    }

    public function store(ResidenceRequest $r, TenantContext $c)
    {
        $res = $c->organization()->residences()->create(collect($r->validated())->except('logo')->all());
        if ($r->hasFile('logo')) {
            $res->addMediaFromRequest('logo')->toMediaCollection('logo');
        }
        $r->user()->update(['current_residence_id' => $res->id]);

        return redirect()->route('residences.show', $res)->with('success', __('Residence created.'));
    }

    public function show(Residence $residence, TenantContext $c)
    {
        $this->guard($residence, $c);
        Gate::authorize('view', $residence);
        $residence->loadCount(['buildings', 'lots']);

        return Inertia::render('Residences/Show', ['residence' => $residence, 'setup' => ['operational' => $residence->isOperational(), 'missing_owners' => $residence->lots()->where('active', true)->whereDoesntHave('activeOwnerships')->count(), 'missing_allocations' => $residence->lots()->where('active', true)->whereDoesntHave('allocationValues')->count()]]);
    }

    public function edit(Residence $residence, TenantContext $c)
    {
        $this->guard($residence, $c);
        Gate::authorize('update', $residence);

        return Inertia::render('Residences/Form', ['residence' => $residence]);
    }

    public function update(ResidenceRequest $r, Residence $residence, TenantContext $c)
    {
        $this->guard($residence, $c);
        abort_if($residence->status === 'archived', 409);
        Gate::authorize('update', $residence);
        $residence->update(collect($r->validated())->except('logo')->all());
        if ($r->hasFile('logo')) {
            $residence->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        return back()->with('success', __('Residence updated.'));
    }

    public function archive(Request $request, Residence $residence, TenantContext $c)
    {
        $this->guard($residence, $c);
        Gate::authorize('archive', $residence);
        $residence->forceFill(['status' => 'archived'])->save();
        if ($request->user()->current_residence_id === $residence->id) {
            $replacement = $c->organization()->residences()->where('status', '!=', 'archived')->whereKeyNot($residence->id)->first();
            $request->user()->update(['current_residence_id' => $replacement?->id]);
        }
        activity()->performedOn($residence)->causedBy($request->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id])->log('residence.archived');

        return redirect()->route('residences.index')->with('success', __('Residence archived.'));
    }

    public function restore(Request $request, Residence $residence, TenantContext $c)
    {
        $this->guard($residence, $c);
        Gate::authorize('restore', $residence);
        $residence->forceFill(['status' => 'setup'])->save();
        activity()->performedOn($residence)->causedBy($request->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id])->log('residence.restored');

        return back()->with('success', __('Residence restored.'));
    }

    public function removeLogo(Residence $residence, TenantContext $c)
    {
        $this->guard($residence, $c);
        Gate::authorize('update', $residence);
        $residence->clearMediaCollection('logo');

        return back()->with('success', __('Logo supprimé.'));
    }

    private function guard(Residence $r, TenantContext $c): void
    {
        abort_unless($r->organization_id === $c->organization()->id, 404);
    }
}
