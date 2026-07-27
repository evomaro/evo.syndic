<?php

namespace App\Services;

use App\Models\Assembly;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class GovernanceExportService
{
    public const TYPES = [
        'assemblies', 'convocations', 'deliveries', 'eligibility', 'attendance', 'proxies',
        'quorum', 'votes', 'resolutions', 'resolution-actions',
    ];

    public function rows(TenantContext $context, string $type, array $filters): array
    {
        $assemblies = Assembly::query()
            ->where('organization_id', $context->organization()->id)
            ->where('residence_id', $context->residence()->id);
        $this->filterAssemblies($assemblies, $filters);
        $assemblyIds = $assemblies->pluck('id');

        return match ($type) {
            'assemblies' => $assemblies->orderBy('meeting_date')->get()->map(fn ($row) => [
                'assembly_reference' => $this->text($row->reference), 'assembly_type' => $row->type,
                'meeting_date' => $row->meeting_date?->toDateString(), 'timezone' => $row->timezone,
                'agenda_version_id' => $row->active_agenda_version_id,
                'eligibility_snapshot_id' => $row->eligibility_snapshot_id,
                'legal_verification' => $row->legal_verification_status,
                'snapshot_fingerprint' => $row->finalization_fingerprint,
            ])->all(),
            'convocations' => DB::table('convocations')->join('assemblies', 'assemblies.id', '=', 'convocations.assembly_id')
                ->leftJoin('convocation_recipients', 'convocation_recipients.convocation_id', '=', 'convocations.id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('convocations.id')->get([
                    'assemblies.reference as assembly_reference', 'convocations.version', 'convocations.agenda_version_id',
                    'convocations.issued_at', 'convocations.legal_service_status',
                    'convocation_recipients.delivery_method', 'convocation_recipients.status as delivery_status',
                ])->map(fn ($row) => (array) $row)->all(),
            'deliveries' => DB::table('convocation_delivery_attempts')
                ->join('convocation_recipients', 'convocation_recipients.id', '=', 'convocation_delivery_attempts.convocation_recipient_id')
                ->join('convocations', 'convocations.id', '=', 'convocation_recipients.convocation_id')
                ->join('assemblies', 'assemblies.id', '=', 'convocations.assembly_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('convocation_delivery_attempts.id')->get([
                    'assemblies.reference as assembly_reference', 'convocations.version as convocation_version',
                    'convocation_delivery_attempts.method', 'convocation_delivery_attempts.status',
                    'convocation_delivery_attempts.attempted_at', 'convocation_delivery_attempts.failure_reason',
                    'convocations.legal_service_status',
                ])->map(fn ($row) => (array) $row)->all(),
            'eligibility' => DB::table('assembly_electorates')->join('assemblies', 'assemblies.id', '=', 'assembly_electorates.assembly_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('assembly_electorates.id')->get([
                    'assemblies.reference as assembly_reference', 'assembly_electorates.entitlement_key',
                    'assembly_electorates.eligibility_status', 'assembly_electorates.voting_weight_numerator',
                    'assembly_electorates.voting_weight_denominator', 'assembly_electorates.share_source_code',
                    'assembly_electorates.share_source_version', 'assembly_electorates.eligibility_snapshot_id',
                    'assembly_electorates.inclusion_explanation',
                ])->map(fn ($row) => (array) $row)->all(),
            'attendance' => DB::table('assembly_attendance_records')->join('assemblies', 'assemblies.id', '=', 'assembly_attendance_records.assembly_id')
                ->join('assembly_electorates', 'assembly_electorates.id', '=', 'assembly_attendance_records.electorate_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('assembly_attendance_records.id')->get([
                    'assemblies.reference as assembly_reference', 'assembly_electorates.entitlement_key',
                    'assembly_attendance_records.status', 'assembly_attendance_records.active_weight_numerator',
                    'assembly_attendance_records.active_weight_denominator', 'assembly_attendance_records.arrived_at',
                    'assembly_attendance_records.departed_at',
                ])->map(fn ($row) => (array) $row)->all(),
            'proxies' => DB::table('assembly_proxies')->join('assemblies', 'assemblies.id', '=', 'assembly_proxies.assembly_id')
                ->join('assembly_electorates', 'assembly_electorates.id', '=', 'assembly_proxies.principal_electorate_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('assembly_proxies.id')->get([
                    'assemblies.reference as assembly_reference', 'assembly_electorates.entitlement_key',
                    'assembly_proxies.status', 'assembly_proxies.entitlement_weight_numerator',
                    'assembly_proxies.entitlement_weight_denominator',
                    'assembly_proxies.verified_at', 'assembly_proxies.revoked_at',
                ])->map(fn ($row) => (array) $row)->all(),
            'quorum' => DB::table('assembly_quorum_snapshots')->join('assemblies', 'assemblies.id', '=', 'assembly_quorum_snapshots.assembly_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('assembly_quorum_snapshots.id')->get([
                    'assemblies.reference as assembly_reference', 'assembly_quorum_snapshots.sequence',
                    'assembly_quorum_snapshots.governance_rule_version_id', 'assembly_quorum_snapshots.eligible_weight_numerator',
                    'assembly_quorum_snapshots.represented_weight_numerator', 'assembly_quorum_snapshots.weight_denominator',
                    'assembly_quorum_snapshots.outcome', 'assembly_quorum_snapshots.legal_verification_status',
                    'assembly_quorum_snapshots.input_fingerprint', 'assembly_quorum_snapshots.confirmed_at',
                ])->map(fn ($row) => (array) $row)->all(),
            'votes' => DB::table('resolution_results')->join('assembly_resolutions', 'assembly_resolutions.id', '=', 'resolution_results.resolution_id')
                ->join('assemblies', 'assemblies.id', '=', 'assembly_resolutions.assembly_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('resolution_results.id')->get([
                    'assemblies.reference as assembly_reference', 'assembly_resolutions.code as resolution_code',
                    'assembly_resolutions.vote_mode', 'resolution_results.rule_identifier', 'resolution_results.rule_version',
                    'resolution_results.for_weight', 'resolution_results.against_weight',
                    'resolution_results.abstention_weight', 'resolution_results.invalid_weight',
                    'resolution_results.non_participating_weight', 'resolution_results.denominator',
                    'resolution_results.adopted', 'assembly_resolutions.legal_validity_status',
                    'resolution_results.checksum as snapshot_fingerprint',
                ])->map(fn ($row) => (array) $row)->all(),
            'resolutions' => DB::table('assembly_resolutions')->join('assemblies', 'assemblies.id', '=', 'assembly_resolutions.assembly_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('assembly_resolutions.id')->get([
                    'assemblies.reference as assembly_reference', 'assembly_resolutions.code',
                    'assembly_resolutions.category', 'assembly_resolutions.status',
                    'assembly_resolutions.execution_status', 'assembly_resolutions.legal_validity_status',
                    'assembly_resolutions.vote_mode', 'assembly_resolutions.voting_closed_at',
                ])->map(fn ($row) => (array) $row)->all(),
            'resolution-actions' => DB::table('resolution_execution_actions')
                ->join('assembly_resolutions', 'assembly_resolutions.id', '=', 'resolution_execution_actions.resolution_id')
                ->join('assemblies', 'assemblies.id', '=', 'assembly_resolutions.assembly_id')
                ->whereIn('assemblies.id', $assemblyIds)->orderBy('resolution_execution_actions.id')->get([
                    'assemblies.reference as assembly_reference', 'assembly_resolutions.code as resolution_code',
                    'resolution_execution_actions.action_type', 'resolution_execution_actions.status',
                    'resolution_execution_actions.responsible_role', 'resolution_execution_actions.due_on',
                    'resolution_execution_actions.priority', 'resolution_execution_actions.completed_at',
                ])->map(fn ($row) => (array) $row)->all(),
        };
    }

    public function metadata(TenantContext $context, string $type, array $filters, int $rowCount): array
    {
        return [
            'organization' => $context->organization()->name,
            'residence' => $context->residence()->name,
            'register' => $type, 'filters' => $filters, 'row_count' => $rowCount,
            'generated_at' => now('UTC')->toIso8601String(),
            'classification' => 'technical_snapshot_not_certified',
            'legal_notice_fr' => 'Document technique non certifié — ne constitue pas un avis juridique.',
            'legal_notice_ar' => 'وثيقة تقنية غير معتمدة ولا تشكل استشارة قانونية.',
        ];
    }

    private function filterAssemblies(Builder $query, array $filters): void
    {
        $query->when($filters['assembly'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('meeting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('meeting_date', '<=', $date));
    }

    private function text(?string $value): ?string
    {
        return $value === null ? null : "'".ltrim($value, "'");
    }
}
