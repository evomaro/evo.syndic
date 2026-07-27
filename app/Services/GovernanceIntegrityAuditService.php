<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyResolution;
use App\Models\GovernanceDocumentVersion;
use App\Models\GovernanceRuleVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GovernanceIntegrityAuditService
{
    public function assemblies(array $filters = []): array
    {
        $violations = [];
        $query = $this->assembliesQuery($filters);
        foreach ($query->with([
            'eligibilitySnapshot', 'minutes.signedVersion', 'resolutions.finalResult',
            'resolutions.ruleVersion', 'resolutions.executionActions', 'resolutions.transitions',
            'attendanceRecords', 'quorumSnapshots', 'participants', 'proxies',
            'convocations.recipients.attempts', 'transitions',
        ])->get() as $assembly) {
            if (! $assembly->eligibilitySnapshot) {
                $violations[] = $this->violation('missing_eligibility_snapshot', $assembly);
            } elseif ($assembly->eligibilitySnapshot->stale_at) {
                $violations[] = $this->violation('stale_eligibility_snapshot', $assembly);
            }
            if ($assembly->finalized_at && (! $assembly->finalization_fingerprint || ! $assembly->minutes?->signedVersion)) {
                $violations[] = $this->violation('finalized_without_immutable_minutes', $assembly);
            }
            if ($assembly->attendanceRecords->groupBy('electorate_id')->contains(fn ($rows) => $rows->whereIn('status', ['present', 'represented'])->count() > 1)) {
                $violations[] = $this->violation('attendance_counted_twice', $assembly);
            }
            if ($assembly->participants->contains(fn ($participant) => $participant->organization_id !== $assembly->organization_id || $participant->residence_id !== $assembly->residence_id)) {
                $violations[] = $this->violation('cross_residence_participant', $assembly);
            }
            if ($assembly->proxies->where('status', 'verified')->groupBy('principal_electorate_id')->contains(fn ($rows) => $rows->count() > 1)) {
                $violations[] = $this->violation('duplicate_active_proxy', $assembly);
            }
            $revokedPrincipals = $assembly->proxies->whereIn('status', ['revoked', 'rejected'])->pluck('principal_electorate_id');
            if ($assembly->attendanceRecords->contains(fn ($record) => $record->status === 'represented' && $revokedPrincipals->contains($record->electorate_id))) {
                $violations[] = $this->violation('revoked_proxy_counted', $assembly);
            }
            foreach ($assembly->quorumSnapshots->whereNotNull('confirmed_at') as $snapshot) {
                $currentAttendance = $assembly->attendanceRecords->whereIn('status', ['present', 'represented'])
                    ->sortBy('electorate_id')
                    ->map(fn ($row) => ['electorate_id' => $row->electorate_id, 'status' => $row->status, 'weight' => (int) $row->active_weight_numerator])
                    ->values()->all();
                $snapshottedAttendance = collect($snapshot->input_snapshot['attendance'] ?? [])
                    ->sortBy('electorate_id')
                    ->map(fn ($row) => [
                        'electorate_id' => (int) $row['electorate_id'],
                        'status' => (string) $row['status'],
                        'weight' => (int) $row['weight'],
                    ])->values()->all();
                if ($currentAttendance !== $snapshottedAttendance) {
                    $violations[] = $this->violation('quorum_inconsistent_with_attendance', $assembly, ['quorum_snapshot_id' => $snapshot->id]);
                }
            }
            foreach ($assembly->convocations as $convocation) {
                foreach ($convocation->recipients as $recipient) {
                    if ($recipient->attempts->where('status', 'successful')->count() > 1) {
                        $violations[] = $this->violation('duplicate_successful_convocation_delivery', $assembly, ['recipient_id' => $recipient->id]);
                    }
                }
            }
            if ($assembly->resolutions->contains(fn ($resolution) => $resolution->status === 'voting_open' && ! $assembly->quorumSnapshots->contains(fn ($snapshot) => $snapshot->confirmed_at && ! $snapshot->stale_at))) {
                $violations[] = $this->violation('voting_opened_without_confirmed_quorum', $assembly);
            }
            foreach ($assembly->resolutions as $resolution) {
                $rule = $resolution->ruleVersion;
                if ($rule && ($assembly->meeting_date->lt($rule->effective_from) || ($rule->effective_until && $assembly->meeting_date->gt($rule->effective_until)))) {
                    $violations[] = $this->violation('rule_outside_effective_dates', $assembly, ['rule_version_id' => $rule->id]);
                }
                if ($resolution->status !== 'draft' && $resolution->transitions->isEmpty()) {
                    $violations[] = $this->violation('missing_resolution_transition_evidence', $assembly, ['resolution_id' => $resolution->id]);
                }
                foreach ($resolution->executionActions as $action) {
                    if (
                        $action->organization_id !== $assembly->organization_id
                        || $action->residence_id !== $assembly->residence_id
                    ) {
                        $violations[] = $this->violation('cross_tenant_downstream_link', $assembly, ['action_id' => $action->id]);
                    }
                    if ($action->status === 'completed' && ($resolution->status !== 'adopted' || ! $action->completed_by)) {
                        $violations[] = $this->violation('resolution_executed_without_authorization', $assembly, ['action_id' => $action->id]);
                    }
                }
            }
            if ($assembly->finalized_at) {
                if ($assembly->transitions->isEmpty()) {
                    $violations[] = $this->violation('missing_activity_transition_evidence', $assembly);
                }
                if ($assembly->finalization_fingerprint && ! hash_equals($assembly->finalization_fingerprint, $this->assemblyFingerprint($assembly))) {
                    $violations[] = $this->violation('modified_data_after_finalization', $assembly);
                }
            }
        }
        foreach ($this->rules($filters)['violations'] as $violation) {
            $violations[] = $violation;
        }

        return $this->report('assemblies', $query->count(), $violations);
    }

    public function eligibility(array $filters = []): array
    {
        $violations = [];
        $query = $this->assembliesQuery($filters);
        foreach ($query->with(['eligibilitySnapshot', 'electorate'])->get() as $assembly) {
            if ($assembly->electorate->groupBy('entitlement_key')->contains(fn ($rows) => $rows->count() > 1)) {
                $violations[] = $this->violation('duplicate_voting_interest', $assembly);
            }
            if ($assembly->electorate->contains(fn ($interest) => (int) $interest->voting_weight_denominator <= 0 || (int) $interest->voting_weight_numerator < 0)) {
                $violations[] = $this->violation('invalid_voting_share', $assembly);
            }
            if ($assembly->electorate->contains(fn ($interest) => $interest->organization_id !== $assembly->organization_id || $interest->residence_id !== $assembly->residence_id)) {
                $violations[] = $this->violation('cross_residence_voting_interest', $assembly);
            }
            if ($assembly->electorate->contains(fn ($interest) => $interest->eligibility_status === 'eligible' && (! $interest->share_source_code || (int) $interest->voting_weight_numerator === 0))) {
                $violations[] = $this->violation('missing_voting_share', $assembly);
            }
            foreach ($assembly->eligibilitySnapshot?->findings ?? [] as $finding) {
                if ($finding['blocking'] ?? false) {
                    $violations[] = $this->violation($finding['code'] ?? 'eligibility_blocker', $assembly, $finding);
                }
            }
        }

        return $this->report('eligibility', $query->count(), $violations);
    }

    public function votes(array $filters = []): array
    {
        $violations = [];
        $query = $this->assembliesQuery($filters);
        foreach ($query->with(['resolutions.ballots', 'resolutions.ballots.electorate', 'resolutions.finalResult', 'resolutions.secretBallotAggregate'])->get() as $assembly) {
            foreach ($assembly->resolutions as $resolution) {
                if ($resolution->vote_mode === 'secret_aggregate' && $resolution->ballots->isNotEmpty()) {
                    $violations[] = $this->violation('secret_ballot_identity_leakage', $assembly, ['resolution_id' => $resolution->id]);
                }
                if ($resolution->ballots->groupBy('electorate_id')->contains(fn ($rows) => $rows->count() > 1)) {
                    $violations[] = $this->violation('duplicate_ballot', $assembly, ['resolution_id' => $resolution->id]);
                }
                foreach ($resolution->ballots->whereNotNull('proxy_id') as $ballot) {
                    $proxy = DB::table('assembly_proxies')->where('id', $ballot->proxy_id)->first();
                    if (
                        ! $proxy
                        || (int) $proxy->assembly_id !== $assembly->id
                        || (int) $proxy->principal_electorate_id !== (int) $ballot->electorate_id
                        || $proxy->status !== 'verified'
                    ) {
                        $violations[] = $this->violation('ballot_outside_proxy_scope', $assembly, ['resolution_id' => $resolution->id, 'ballot_id' => $ballot->id]);
                    }
                }
                if ($resolution->finalResult && $resolution->finalResult->checksum !== $this->resultChecksum($resolution)) {
                    $violations[] = $this->violation('vote_result_snapshot_mismatch', $assembly, ['resolution_id' => $resolution->id]);
                }
            }
        }

        return $this->report('votes', $query->count(), $violations);
    }

    public function resolutions(array $filters = []): array
    {
        $violations = [];
        $query = AssemblyResolution::query()->with(['assembly', 'finalResult', 'ruleVersion']);
        $this->scopeResolutionQuery($query, $filters);
        foreach ($query->get() as $resolution) {
            if ($resolution->status === 'adopted' && ! $resolution->finalResult) {
                $violations[] = $this->violation('adopted_without_closed_vote', $resolution->assembly, ['resolution_id' => $resolution->id]);
            } elseif ($resolution->status === 'adopted' && (! $resolution->voting_closed_at || ! $resolution->finalResult->finalized_at)) {
                $violations[] = $this->violation('adopted_without_closed_vote', $resolution->assembly, ['resolution_id' => $resolution->id]);
            }
            if ($resolution->status === 'adopted' && $resolution->ruleVersion?->status !== 'active') {
                $violations[] = $this->violation('result_from_unapproved_rule', $resolution->assembly, ['resolution_id' => $resolution->id]);
            }
        }

        return $this->report('resolutions', $query->count(), $violations);
    }

    public function evidence(array $filters = []): array
    {
        $violations = [];
        $query = GovernanceDocumentVersion::query()->with('document.assembly')
            ->when($filters['assembly'] ?? null, fn ($q, $id) => $q->whereHas('document', fn ($documents) => $documents->where('assembly_id', $id)))
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->whereHas('document', fn ($documents) => $documents->where('organization_id', $id)))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->whereHas('document', fn ($documents) => $documents->where('residence_id', $id)));
        foreach ($query->get() as $version) {
            $disk = Storage::disk($version->disk);
            if (! $disk->exists($version->path) || ! hash_equals($version->checksum, hash('sha256', $disk->get($version->path)))) {
                $violations[] = ['code' => 'broken_evidence_checksum', 'document_version_id' => $version->id, 'assembly_id' => $version->document->assembly_id];
            }
        }

        return $this->report('evidence', $query->count(), $violations);
    }

    public function rules(array $filters = []): array
    {
        $violations = [];
        $query = GovernanceRuleVersion::query()
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->whereHas('rule', fn ($rules) => $rules->where('organization_id', $id)))
            ->when($filters['rule-version'] ?? null, fn ($q, $id) => $q->whereKey($id));
        foreach ($query->with('source')->get() as $version) {
            if ($version->status === 'active' && (! $version->source || $version->source->confidence !== 'source_verified')) {
                $violations[] = ['code' => 'active_rule_without_verified_source', 'rule_version_id' => $version->id];
            }
            if ($version->status === 'active') {
                $overlap = GovernanceRuleVersion::query()
                    ->where('governance_rule_id', $version->governance_rule_id)
                    ->whereKeyNot($version->id)
                    ->where('status', 'active')
                    ->whereDate('effective_from', '<=', $version->effective_until ?: '9999-12-31')
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $version->effective_from))
                    ->exists();
                if ($overlap) {
                    $violations[] = ['code' => 'overlapping_active_rule_versions', 'rule_version_id' => $version->id];
                }
            }
        }

        return $this->report('rules', $query->count(), $violations);
    }

    private function assembliesQuery(array $filters)
    {
        return Assembly::query()
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->where('residence_id', $id))
            ->when($filters['assembly'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['fiscal-year'] ?? null, fn ($q, $year) => $q->whereYear('meeting_date', $year))
            ->when($filters['rule-version'] ?? null, fn ($q, $id) => $q->whereHas('resolutions', fn ($resolutions) => $resolutions->where('governance_rule_version_id', $id)))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
    }

    private function scopeResolutionQuery($query, array $filters): void
    {
        $query->when($filters['organization'] ?? null, fn ($q, $id) => $q->whereHas('assembly', fn ($assemblies) => $assemblies->where('organization_id', $id)))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->whereHas('assembly', fn ($assemblies) => $assemblies->where('residence_id', $id)))
            ->when($filters['assembly'] ?? null, fn ($q, $id) => $q->where('assembly_id', $id))
            ->when($filters['resolution'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
    }

    private function resultChecksum(AssemblyResolution $resolution): string
    {
        $result = $resolution->finalResult;
        $ballots = collect($result->ballot_snapshot)->map(fn ($ballot) => [
            'ballot_id' => (int) $ballot['ballot_id'],
            'electorate_id' => (int) $ballot['electorate_id'],
            'choice' => (string) $ballot['choice'],
            'weight' => (int) $ballot['weight'],
        ])->all();
        $totals = [
            'eligible' => (int) $result->total_eligible_weight, 'present' => (int) $result->present_weight,
            'represented' => (int) $result->represented_weight, 'for' => (int) $result->for_weight,
            'against' => (int) $result->against_weight, 'abstention' => (int) $result->abstention_weight,
            'invalid' => (int) $result->invalid_weight, 'non' => (int) $result->non_participating_weight,
            'denominator' => (int) $result->denominator, 'adopted' => (bool) $result->adopted,
        ];

        return hash('sha256', json_encode(['rule' => $result->rule_snapshot, 'ballots' => $ballots, 'totals' => $totals], JSON_THROW_ON_ERROR));
    }

    private function violation(string $code, Assembly $assembly, array $details = []): array
    {
        return ['code' => $code, 'assembly_id' => $assembly->id, 'organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id] + $details;
    }

    private function report(string $kind, int $checked, array $violations): array
    {
        return [
            'kind' => $kind,
            'checked' => $checked,
            'ok' => $violations === [],
            'blocking_count' => count($violations),
            'warning_count' => 0,
            'warnings' => [],
            'violations' => $violations,
            'generated_at' => now('UTC')->toIso8601String(),
            'read_only' => true,
        ];
    }

    private function assemblyFingerprint(Assembly $assembly): string
    {
        $payload = [
            'assembly_id' => $assembly->id,
            'eligibility' => $assembly->eligibilitySnapshot?->input_fingerprint,
            'quorum' => $assembly->quorumSnapshots->whereNotNull('confirmed_at')->last()?->checksum,
            'results' => $assembly->resolutions->map(fn ($resolution) => $resolution->finalResult?->checksum)->all(),
            'minutes' => $assembly->minutes?->signedVersion?->checksum,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
