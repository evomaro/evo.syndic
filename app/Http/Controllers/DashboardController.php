<?php

namespace App\Http\Controllers;

use App\Services\ActivityScope;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context, ActivityScope $activityScope)
    {
        $org = $context->organization;
        if (! $org) {
            return redirect()->route('onboarding.index');
        }$res = $context->residence;
        $stats = $res ? ['buildings' => $res->buildings()->where('active', true)->count(), 'lots' => $res->lots()->where('active', true)->count(), 'owners' => $org->contacts()->whereHas('ownerships.lot', fn ($q) => $q->where('residence_id', $res->id)->whereNull('ends_on'))->count(), 'occupants' => $org->contacts()->whereHas('occupancies.lot', fn ($q) => $q->where('residence_id', $res->id)->whereNull('ends_on'))->count(), 'vacant' => $res->lots()->where('active', true)->where('occupancy_status', 'vacant')->count(), 'missing_owners' => $res->lots()->where('active', true)->whereDoesntHave('activeOwnerships')->count(), 'missing_allocations' => $res->lots()->where('active', true)->whereDoesntHave('allocationValues', fn ($q) => $q->whereHas('allocationKey', fn ($k) => $k->where('is_default', true)))->count()] : ['residences' => $org->residences()->count(), 'lots' => $org->residences()->withCount(['lots' => fn ($q) => $q->where('active', true)])->get()->sum('lots_count'), 'contacts' => $org->contacts()->count(), 'setup' => $org->residences()->where('status', 'setup')->count(), 'invitations' => $org->invitations()->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->count()];

        $finance = null;
        if (request()->user()->canInOrganization('view_finance', $org)) {
            $allowedResidenceIds = $org->residences()->when(! ($org->users()->whereKey(request()->user()->id)->first()?->pivot?->all_residences), fn ($q) => $q->whereHas('users', fn ($u) => $u->whereKey(request()->user()->id)))->pluck('id');
            if ($res) {
                $allowedResidenceIds = collect([$res->id]);
            }
            $finance = DB::table('residences')->whereIn('residences.id', $allowedResidenceIds)
                ->select(['residences.id', 'residences.name'])
                ->selectSub(fn ($q) => $q->from('lot_charges')->whereColumn('lot_charges.residence_id', 'residences.id')->whereNull('cancelled_at')->selectRaw('COALESCE(SUM(amount_cents),0)'), 'called_cents')
                ->selectSub(fn ($q) => $q->from('payment_allocations')->join('lot_charges', 'lot_charges.id', '=', 'payment_allocations.lot_charge_id')->whereColumn('lot_charges.residence_id', 'residences.id')->whereNull('lot_charges.cancelled_at')->whereNull('payment_allocations.reversed_at')->selectRaw('COALESCE(SUM(payment_allocations.amount_cents),0)'), 'collected_cents')
                ->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'called_cents' => (int) $row->called_cents, 'collected_cents' => (int) $row->collected_cents, 'outstanding_cents' => max(0, (int) $row->called_cents - (int) $row->collected_cents)]);
        }

        return Inertia::render('Dashboard', ['stats' => $stats, 'isResidence' => (bool) $res, 'activity' => $activityScope->apply(Activity::query(), $org)->latest()->limit(8)->get(), 'finance' => $finance]);
    }
}
