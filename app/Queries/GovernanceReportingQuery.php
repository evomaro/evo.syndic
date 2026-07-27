<?php

namespace App\Queries;

use App\Models\Assembly;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class GovernanceReportingQuery
{
    public function summary(TenantContext $context): array
    {
        $organizationId = $context->organization()->id;
        $residenceId = $context->residence()->id;
        $base = Assembly::query()->where('organization_id', $organizationId)->where('residence_id', $residenceId);
        $held = (clone $base)->whereIn('status', ['deliberations_completed', 'minutes_prepared', 'minutes_signed', 'decisions_notified', 'closed'])->count();
        $pending = (clone $base)->whereNotIn('status', ['closed', 'cancelled', 'replaced_by_second_convocation'])->count();

        $latestResultIds = DB::table('resolution_results')->selectRaw('MAX(resolution_results.id)')
            ->whereColumn('resolution_results.resolution_id', 'assembly_resolutions.id')
            ->whereColumn('resolution_results.version', DB::raw('(SELECT MAX(rr2.version) FROM resolution_results rr2 WHERE rr2.resolution_id = assembly_resolutions.id)'));
        $results = DB::table('resolution_results')
            ->join('assembly_resolutions', 'assembly_resolutions.id', '=', 'resolution_results.resolution_id')
            ->join('assemblies', 'assemblies.id', '=', 'assembly_resolutions.assembly_id')
            ->where('assemblies.organization_id', $organizationId)->where('assemblies.residence_id', $residenceId)
            ->whereIn('resolution_results.id', $latestResultIds);

        $quorum = DB::table('assembly_quorum_snapshots')->join('assemblies', 'assemblies.id', '=', 'assembly_quorum_snapshots.assembly_id')
            ->where('assemblies.organization_id', $organizationId)->where('assemblies.residence_id', $residenceId);
        $eligibleWeight = (int) (clone $quorum)->sum('eligible_weight_numerator');
        $activeWeight = (int) (clone $quorum)->sum('represented_weight_numerator');
        $eligibleHeadcount = (int) (clone $quorum)->sum('eligible_headcount');
        $activeHeadcount = (int) (clone $quorum)->sum('present_or_represented_headcount');
        $representedWeight = (int) DB::table('assembly_attendance_records')->join('assemblies', 'assemblies.id', '=', 'assembly_attendance_records.assembly_id')->where('assemblies.organization_id', $organizationId)->where('assemblies.residence_id', $residenceId)->where('assembly_attendance_records.status', 'represented')->sum('active_weight_numerator');

        $ruleDistribution = (clone $results)->select('rule_identifier', DB::raw('COUNT(*) as total'))->groupBy('rule_identifier')->pluck('total', 'rule_identifier')->map(fn ($value) => (int) $value)->all();
        $financialStatuses = DB::table('assembly_resolutions')->join('assemblies', 'assemblies.id', '=', 'assembly_resolutions.assembly_id')->where('assemblies.organization_id', $organizationId)->where('assemblies.residence_id', $residenceId)->whereIn('assembly_resolutions.category', ['budget', 'budget_approval', 'accounts', 'account_approval'])->select('assembly_resolutions.category', 'assembly_resolutions.status', DB::raw('COUNT(*) as total'))->groupBy('assembly_resolutions.category', 'assembly_resolutions.status')->get()->map(fn ($row) => ['category' => $row->category, 'status' => $row->status, 'total' => (int) $row->total])->all();

        return [
            'assemblies_held' => (int) $held,
            'assemblies_pending' => (int) $pending,
            'convocation_late_exceptions' => (int) DB::table('convocations')->where('organization_id', $organizationId)->where('residence_id', $residenceId)->where('late_exception', true)->count(),
            'delivery_failures' => (int) DB::table('convocation_recipients')->join('convocations', 'convocations.id', '=', 'convocation_recipients.convocation_id')->where('convocations.organization_id', $organizationId)->where('convocations.residence_id', $residenceId)->whereIn('convocation_recipients.status', ['failed', 'returned'])->count(),
            'participation_weight' => ['numerator' => $activeWeight, 'denominator' => $eligibleWeight, 'basis' => 'latest persisted quorum snapshots'],
            'participation_headcount' => ['numerator' => $activeHeadcount, 'denominator' => $eligibleHeadcount, 'basis' => 'latest persisted quorum snapshots'],
            'representation_weight' => ['numerator' => $representedWeight, 'denominator' => $eligibleWeight, 'basis' => 'persisted represented attendance'],
            'quorum_failures' => (int) (clone $quorum)->where('quorum_met', false)->count(),
            'adopted' => (int) (clone $results)->where('adopted', true)->count(),
            'rejected' => (int) (clone $results)->where('adopted', false)->count(),
            'majority_rule_distribution' => $ruleDistribution,
            'budget_account_status' => $financialStatuses,
            'execution_pending' => (int) DB::table('resolution_execution_actions')->where('organization_id', $organizationId)->where('residence_id', $residenceId)->whereIn('status', ['pending', 'in_progress', 'blocked'])->count(),
            'execution_overdue' => (int) DB::table('resolution_execution_actions')->where('organization_id', $organizationId)->where('residence_id', $residenceId)->whereIn('status', ['pending', 'in_progress'])->whereDate('due_on', '<', today())->count(),
            'mandates_expiring' => (int) DB::table('governance_mandates')->where('organization_id', $organizationId)->where('residence_id', $residenceId)->where('status', 'active')->whereBetween('ends_on', [today(), today()->addDays(60)])->count(),
        ];
    }
}
