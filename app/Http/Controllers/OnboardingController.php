<?php

namespace App\Http\Controllers;

use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\Residence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->user()->currentOrganization;
        $residence = $request->user()->currentResidence;
        $progress = $residence ? OnboardingProgress::firstOrCreate(['user_id' => $request->user()->id, 'organization_id' => $organization?->id, 'residence_id' => $residence->id]) : null;

        return Inertia::render('Onboarding/Index', [
            'organization' => $organization,
            'residence' => $residence,
            'steps' => $this->steps($organization, $residence, $progress),
            'can_activate' => $residence?->isOperational() ?? false,
        ]);
    }

    public function organization(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'code' => 'required|alpha_dash|max:50|unique:organizations,code', 'type' => ['required', Rule::in(['volunteer_syndic', 'professional_syndic'])], 'experience_mode' => ['sometimes', 'required', Rule::in(['essential', 'pro'])]]);
        DB::transaction(function () use ($request, $data) {
            $organization = Organization::create($data);
            $organization->users()->attach($request->user(), ['role' => 'owner', 'all_residences' => true]);
            $request->user()->update(['current_organization_id' => $organization->id]);
            OnboardingProgress::create(['user_id' => $request->user()->id, 'organization_id' => $organization->id, 'current_step' => 'residence', 'completed_steps' => ['organization']]);
        });

        return redirect()->route('onboarding.index')->with('success', __('Organisation créée.'));
    }

    public function residence(Request $request)
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $request->user()->belongsToOrganization($organization), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'code' => ['required', 'alpha_dash', Rule::unique('residences')->where('organization_id', $organization->id)], 'address_line_1' => 'required|string', 'city' => 'required|string']);
        DB::transaction(function () use ($request, $organization, $data) {
            $residence = $organization->residences()->create($data);
            $request->user()->update(['current_residence_id' => $residence->id]);
            OnboardingProgress::updateOrCreate(['user_id' => $request->user()->id, 'organization_id' => $organization->id, 'residence_id' => null], ['residence_id' => $residence->id, 'current_step' => 'structure', 'completed_steps' => ['organization', 'residence']]);
        });

        return redirect()->route('onboarding.index')->with('success', __('Résidence créée.'));
    }

    public function skip(Request $request, string $step)
    {
        abort_unless(in_array($step, ['contacts', 'team'], true), 422);
        $residence = $this->currentResidence($request);
        $progress = OnboardingProgress::firstOrCreate(['user_id' => $request->user()->id, 'organization_id' => $residence->organization_id, 'residence_id' => $residence->id]);
        $progress->update(['completed_steps' => array_values(array_unique([...($progress->completed_steps ?? []), "skipped:$step"]))]);

        return back();
    }

    public function acknowledgeOwnership(Request $request)
    {
        $residence = $this->currentResidence($request);
        Gate::authorize('update', $residence);
        $residence->forceFill(['ownership_incomplete_acknowledged' => true])->save();

        return back();
    }

    public function deferAllocations(Request $request)
    {
        $residence = $this->currentResidence($request);
        Gate::authorize('update', $residence);
        $residence->forceFill(['allocations_deferred' => true])->save();

        return back();
    }

    public function activate(Request $request)
    {
        $residence = $this->currentResidence($request);
        Gate::authorize('update', $residence);
        DB::transaction(function () use ($residence, $request) {
            $locked = Residence::query()->lockForUpdate()->findOrFail($residence->id);
            if (! $locked->isOperational()) {
                throw ValidationException::withMessages(['activation' => __('La résidence ne satisfait pas encore les conditions d’activation.')]);
            }
            $locked->forceFill(['status' => 'active'])->save();
            OnboardingProgress::updateOrCreate(['user_id' => $request->user()->id, 'organization_id' => $locked->organization_id, 'residence_id' => $locked->id], ['current_step' => 'complete', 'completed_steps' => ['organization', 'residence', 'structure', 'contacts', 'ownership', 'allocations', 'team', 'review']]);
            activity()->performedOn($locked)->causedBy($request->user())->withProperties(['organization_id' => $locked->organization_id, 'residence_id' => $locked->id])->log('residence.activated');
        });

        return redirect()->route('dashboard')->with('success', __('Résidence activée.'));
    }

    private function steps(?Organization $organization, ?Residence $residence, ?OnboardingProgress $progress): array
    {
        $skipped = $progress?->completed_steps ?? [];
        $lots = $residence?->lots()->where('active', true)->count() ?? 0;
        $missingOwners = $residence?->lots()->where('active', true)->whereDoesntHave('activeOwnerships')->count() ?? 0;
        $default = $residence?->allocationKeys()->where('is_default', true)->first();
        $missingValues = $default ? $residence->lots()->where('active', true)->whereDoesntHave('allocationValues', fn ($q) => $q->where('allocation_key_id', $default->id))->count() : $lots;

        return [
            ['key' => 'organization', 'complete' => (bool) $organization, 'required' => true],
            ['key' => 'residence', 'complete' => (bool) $residence, 'required' => true],
            ['key' => 'structure', 'complete' => $lots > 0, 'required' => true, 'count' => $lots],
            ['key' => 'contacts', 'complete' => ($organization?->contacts()->exists() ?? false) || in_array('skipped:contacts', $skipped, true), 'required' => false],
            ['key' => 'ownership', 'complete' => $lots > 0 && ($missingOwners === 0 || $residence->ownership_incomplete_acknowledged), 'required' => true, 'missing' => $missingOwners],
            ['key' => 'allocations', 'complete' => (bool) $default && ($missingValues === 0 || $residence->allocations_deferred), 'required' => true, 'missing' => $missingValues],
            ['key' => 'team', 'complete' => ($organization?->users()->count() ?? 0) > 1 || in_array('skipped:team', $skipped, true), 'required' => false],
            ['key' => 'review', 'complete' => $residence?->status === 'active', 'required' => true],
        ];
    }

    private function currentResidence(Request $request): Residence
    {
        $residence = $request->user()->currentResidence;
        abort_unless($residence && $request->user()->belongsToOrganization($residence->organization_id), 403);

        return $residence;
    }
}
