<?php

namespace App\Http\Controllers;

use App\Services\ActivityScope;
use App\Services\HelpCenterService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context, ActivityScope $activityScope, HelpCenterService $helpCenter)
    {
        $org = $context->organization;
        if (! $org) {
            return redirect()->route('onboarding.index');
        }$res = $context->residence;
        $stats = $res ? ['buildings' => $res->buildings()->where('active', true)->count(), 'lots' => $res->lots()->where('active', true)->count(), 'owners' => $org->contacts()->whereHas('ownerships.lot', fn ($q) => $q->where('residence_id', $res->id)->whereNull('ends_on'))->count(), 'occupants' => $org->contacts()->whereHas('occupancies.lot', fn ($q) => $q->where('residence_id', $res->id)->whereNull('ends_on'))->count(), 'vacant' => $res->lots()->where('active', true)->where('occupancy_status', 'vacant')->count(), 'missing_owners' => $res->lots()->where('active', true)->whereDoesntHave('activeOwnerships')->count(), 'missing_allocations' => $res->lots()->where('active', true)->whereDoesntHave('allocationValues', fn ($q) => $q->whereHas('allocationKey', fn ($k) => $k->where('is_default', true)))->count()] : ['residences' => $org->residences()->count(), 'lots' => $org->residences()->withCount(['lots' => fn ($q) => $q->where('active', true)])->get()->sum('lots_count'), 'contacts' => $org->contacts()->count(), 'setup' => $org->residences()->where('status', 'setup')->count(), 'invitations' => $org->invitations()->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->count()];

        $finance = null;
        $expenses = null;
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

        if (request()->user()->canInOrganization('view_expenses', $org)) {
            $allowedResidenceIds ??= $org->residences()->when(! ($org->users()->whereKey(request()->user()->id)->first()?->pivot?->all_residences), fn ($q) => $q->whereHas('users', fn ($u) => $u->whereKey(request()->user()->id)))->pluck('id');
            if ($res) {
                $allowedResidenceIds = collect([$res->id]);
            }
            $expenses = DB::table('residences')->whereIn('residences.id', $allowedResidenceIds)->select(['residences.id', 'residences.name'])
                ->selectSub(fn ($q) => $q->from('budget_lines')->join('budgets', 'budgets.id', '=', 'budget_lines.budget_id')->whereColumn('budgets.residence_id', 'residences.id')->where('budgets.status', 'approved')->selectRaw('COALESCE(SUM(budget_lines.planned_cents),0)'), 'budget_cents')
                ->selectSub(fn ($q) => $q->from('supplier_invoice_lines')->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_lines.supplier_invoice_id')->whereColumn('supplier_invoice_lines.residence_id', 'residences.id')->whereIn('supplier_invoices.status', ['validated', 'partial', 'paid'])->selectRaw('COALESCE(SUM(supplier_invoice_lines.total_cents),0)'), 'actual_cents')
                ->selectSub(fn ($q) => $q->from('supplier_credit_note_allocations')->join('supplier_credit_notes', 'supplier_credit_notes.id', '=', 'supplier_credit_note_allocations.supplier_credit_note_id')->whereColumn('supplier_credit_note_allocations.residence_id', 'residences.id')->where('supplier_credit_notes.status', 'validated')->whereNull('supplier_credit_note_allocations.reversed_at')->selectRaw('COALESCE(SUM(supplier_credit_note_allocations.amount_cents),0)'), 'credit_cents')
                ->selectSub(fn ($q) => $q->from('supplier_invoices')->whereColumn('supplier_invoices.primary_residence_id', 'residences.id')->whereIn('status', ['validated', 'partial'])->selectRaw('COALESCE(SUM(total_cents-paid_cents-credited_cents),0)'), 'payable_cents')
                ->selectSub(fn ($q) => $q->from('supplier_contracts')->whereColumn('supplier_contracts.residence_id', 'residences.id')->where('status', 'active')->whereBetween('ends_on', [today(), today()->addDays(60)])->selectRaw('COUNT(*)'), 'expiring_contracts')
                ->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'budget_cents' => (int) $row->budget_cents, 'actual_cents' => max(0, (int) $row->actual_cents - (int) $row->credit_cents), 'payable_cents' => (int) $row->payable_cents, 'expiring_contracts' => (int) $row->expiring_contracts]);
        }

        $helpChecklist = collect($helpCenter->checklist(request()->user(), $org, app()->getLocale()));
        $nextStep = $helpChecklist->firstWhere('complete', false);
        $nextStepRoutes = [
            'setup-profile' => 'profile.edit',
            'setup-residence' => 'residences.create',
            'setup-structure' => 'structure.index',
            'setup-contacts' => 'contacts.index',
            'setup-ownerships' => 'structure.index',
            'setup-suppliers' => 'suppliers.index',
            'setup-accounting' => 'financial-exercises.index',
            'setup-accounts' => 'financial-accounts.index',
            'setup-allocations' => 'allocations.index',
            'setup-charges' => 'charge-categories.index',
            'setup-budget' => 'budgets.create',
            'issue-fund-call' => 'fund-calls.create',
            'record-payments' => 'payments.create',
        ];
        if ($nextStep) {
            $routeName = $nextStepRoutes[$nextStep['id']] ?? null;
            $nextStep['href'] = $routeName
                ? route($routeName)
                : route('help.index', $nextStep['article_id']);
            $nextStep['help_href'] = route('help.index', $nextStep['article_id']);
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'isResidence' => (bool) $res,
            'activity' => $activityScope->apply(Activity::query(), $org)->latest()->limit(8)->get(),
            'finance' => $finance,
            'expenses' => $expenses,
            'helpProgress' => [
                'completed' => $helpChecklist->where('complete', true)->count(),
                'total' => $helpChecklist->count(),
            ],
            'nextSetupStep' => $nextStep,
        ]);
    }
}
