<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyMinutes;
use App\Models\AssemblyMinuteVersion;
use App\Models\AssemblyQuorumSnapshot;
use App\Models\AssemblyResolution;
use App\Models\AssemblySecretBallotAggregate;
use App\Models\GovernanceDocumentVersion;
use App\Models\GovernanceRuleVersion;
use App\Models\ResolutionResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhaseSevenGovernanceWorkflow
{
    public function previewQuorum(Assembly $assembly, GovernanceRuleVersion $rule, User $actor): AssemblyQuorumSnapshot
    {
        return DB::transaction(function () use ($assembly, $rule, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->with(['eligibilitySnapshot', 'electorate', 'attendanceRecords'])->lockForUpdate()->firstOrFail();
            $eligible = $assembly->electorate->where('eligibility_status', 'eligible');
            $active = $assembly->attendanceRecords->whereIn('status', ['present', 'represented']);
            $unreviewedProxyIds = $assembly->proxies()
                ->where('status', 'verified')
                ->where('legal_verification_status', '!=', 'reviewed_configuration')
                ->pluck('principal_electorate_id');
            $active = $active->reject(fn ($record) => $record->status === 'represented' && $unreviewedProxyIds->contains($record->electorate_id));
            $eligibleWeight = (int) $eligible->sum('voting_weight_numerator');
            $activeWeight = (int) $active->sum('active_weight_numerator');
            $eligibleHeadcount = $eligible->count();
            $activeHeadcount = $active->count();
            $unavailable = ! $assembly->eligibilitySnapshot || $assembly->eligibilitySnapshot->stale_at || $eligibleWeight <= 0;
            $reviewRequired = $rule->status !== 'active' || ! in_array($rule->confidence, ['professionally_reviewed', 'counsel_reviewed'], true);
            $numerator = $rule->numerator_definition === 'present_represented_weight' ? $activeWeight : $activeHeadcount;
            $denominator = $rule->denominator_definition === 'all_eligible_weight' ? $eligibleWeight : $eligibleHeadcount;
            $met = ! $unavailable && $denominator > 0
                ? app(VotingRuleEngine::class)->decide($rule, $numerator, $denominator)
                : false;
            $outcome = $unavailable ? 'unavailable_configuration' : ($reviewRequired ? 'professional_review_required' : ($met ? 'satisfied' : 'not_satisfied'));
            $input = [
                'eligibility_snapshot_id' => $assembly->eligibility_snapshot_id,
                'eligibility_fingerprint' => $assembly->eligibilitySnapshot?->input_fingerprint,
                'attendance' => $active->sortBy('electorate_id')->map(fn ($row) => ['electorate_id' => $row->electorate_id, 'status' => $row->status, 'weight' => (int) $row->active_weight_numerator])->values()->all(),
                'excluded_unreviewed_proxy_interests' => $unreviewedProxyIds->values()->all(),
                'eligible_ids' => $eligible->pluck('id')->values()->all(),
                'rule_id' => $rule->id, 'rule_version' => $rule->version,
                'numerator' => $numerator, 'denominator' => $denominator,
            ];
            $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $snapshot = $assembly->quorumSnapshots()->create([
                'governance_rule_version_id' => $rule->id,
                'sequence' => (int) $assembly->quorumSnapshots()->max('sequence') + 1,
                'eligible_headcount' => $eligibleHeadcount, 'present_or_represented_headcount' => $activeHeadcount,
                'eligible_weight_numerator' => $eligibleWeight, 'represented_weight_numerator' => $activeWeight,
                'weight_denominator' => (int) ($eligible->first()?->voting_weight_denominator ?: 1),
                'threshold_numerator' => $rule->threshold_numerator, 'threshold_denominator' => $rule->threshold_denominator,
                'quorum_met' => $met, 'input_snapshot' => $input, 'checksum' => $fingerprint,
                'input_fingerprint' => $fingerprint, 'outcome' => $outcome,
                'legal_verification_status' => $reviewRequired ? 'unverified' : 'reviewed_configuration',
                'calculated_by' => $actor->id, 'calculated_at' => now('UTC'),
            ]);
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'snapshot_id' => $snapshot->id, 'outcome' => $outcome])->log('governance.quorum_previewed');

            return $snapshot;
        });
    }

    public function confirmQuorum(AssemblyQuorumSnapshot $snapshot, User $actor): AssemblyQuorumSnapshot
    {
        return DB::transaction(function () use ($snapshot, $actor) {
            $snapshot = AssemblyQuorumSnapshot::query()->whereKey($snapshot->id)->with(['assembly.eligibilitySnapshot', 'assembly.attendanceRecords'])->lockForUpdate()->firstOrFail();
            if ($snapshot->outcome !== 'satisfied' || $snapshot->legal_verification_status !== 'reviewed_configuration' || $snapshot->stale_at || $snapshot->assembly->eligibilitySnapshot?->status !== 'reviewed') {
                throw ValidationException::withMessages(['quorum' => __('Un quorum satisfait, vérifié et fondé sur une éligibilité relue est requis.')]);
            }
            if (($snapshot->input_snapshot['excluded_unreviewed_proxy_interests'] ?? []) !== []) {
                throw ValidationException::withMessages(['proxy' => __('Les intérêts représentés par un mandat non revu restent exclus de la confirmation du quorum.')]);
            }
            $currentAttendance = $snapshot->assembly->attendanceRecords->whereIn('status', ['present', 'represented'])
                ->sortBy('electorate_id')
                ->map(fn ($row) => ['electorate_id' => $row->electorate_id, 'status' => $row->status, 'weight' => (int) $row->active_weight_numerator])->values()->all();
            $snapshottedAttendance = collect($snapshot->input_snapshot['attendance'] ?? [])
                ->sortBy('electorate_id')
                ->map(fn ($row) => [
                    'electorate_id' => (int) $row['electorate_id'],
                    'status' => (string) $row['status'],
                    'weight' => (int) $row['weight'],
                ])->values()->all();
            if ($currentAttendance !== $snapshottedAttendance) {
                $snapshot->update(['stale_at' => now('UTC'), 'stale_reason' => 'Attendance changed after quorum preview.']);
                throw ValidationException::withMessages(['quorum' => __('La présence a changé depuis le calcul; un nouveau snapshot est requis.')]);
            }
            $snapshot->update(['confirmed_by' => $actor->id, 'confirmed_at' => now('UTC')]);
            $snapshot->assembly->update(['quorum_status' => 'met']);
            activity()->performedOn($snapshot->assembly)->causedBy($actor)->withProperties(['organization_id' => $snapshot->assembly->organization_id, 'residence_id' => $snapshot->assembly->residence_id, 'snapshot_id' => $snapshot->id])->log('governance.quorum_confirmed');

            return $snapshot->fresh();
        });
    }

    public function openVoting(AssemblyResolution $resolution, string $mode, User $actor): AssemblyResolution
    {
        return DB::transaction(function () use ($resolution, $mode, $actor) {
            $resolution = AssemblyResolution::query()->whereKey($resolution->id)->with('assembly')->lockForUpdate()->firstOrFail();
            if ($resolution->status !== 'authorized' || $resolution->assembly->status !== 'in_session' || $resolution->assembly->quorum_status !== 'met' || ! in_array($mode, ['recorded_interest', 'recorded_participant', 'secret_aggregate'], true)) {
                throw ValidationException::withMessages(['voting' => __('Le vote ne peut pas être ouvert dans cette configuration.')]);
            }
            if ($resolution->agendaItem?->information_only) {
                throw ValidationException::withMessages(['voting' => __('Un point d’information ne peut pas recevoir un vote contraignant.')]);
            }
            $resolution->update(['status' => 'voting_open', 'vote_mode' => $mode, 'voting_opened_at' => now('UTC'), 'voting_opened_by' => $actor->id, 'immutable_at' => now('UTC')]);
            $this->transition($resolution, 'authorized', 'voting_open', $actor, null, "open:{$resolution->id}");

            return $resolution->fresh();
        });
    }

    public function challenge(AssemblyResolution $resolution, string $to, string $reason, GovernanceDocumentVersion $evidence, User $actor): AssemblyResolution
    {
        return DB::transaction(function () use ($resolution, $to, $reason, $evidence, $actor) {
            $resolution = AssemblyResolution::query()->whereKey($resolution->id)->with('assembly')->lockForUpdate()->firstOrFail();
            abort_unless($evidence->document->organization_id === $resolution->assembly->organization_id && $evidence->document->residence_id === $resolution->assembly->residence_id, 404);
            if (! in_array($to, ['under_challenge', 'suspended', 'failed', 'superseded'], true) || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['resolution' => __('Un état autorisé, un motif détaillé et une preuve sont requis.')]);
            }
            $from = $resolution->status;
            $changes = ['status' => $to];
            if ($to === 'under_challenge') {
                $changes += ['challenge_reason' => trim($reason), 'challenged_at' => now('UTC')];
            }
            if ($to === 'suspended') {
                $changes += ['suspension_reason' => trim($reason), 'suspended_at' => now('UTC')];
            }
            $resolution->update($changes);
            $this->transition($resolution, $from, $to, $actor, $reason, "{$to}:{$evidence->id}", $evidence);

            return $resolution->fresh();
        });
    }

    public function closeSecretBallot(AssemblyResolution $resolution, array $totals, GovernanceDocumentVersion $evidence, User $recorder, User $reviewer): ResolutionResult
    {
        return DB::transaction(function () use ($resolution, $totals, $evidence, $recorder, $reviewer) {
            $resolution = AssemblyResolution::query()->whereKey($resolution->id)
                ->with(['assembly', 'ruleVersion', 'ruleSnapshot'])->lockForUpdate()->firstOrFail();
            abort_unless(
                $evidence->document->organization_id === $resolution->assembly->organization_id
                && $evidence->document->residence_id === $resolution->assembly->residence_id,
                404,
            );
            if ($resolution->status !== 'voting_open' || $resolution->vote_mode !== 'secret_aggregate' || $resolution->ballots()->exists()) {
                throw ValidationException::withMessages(['ballot' => __('Le résultat secret ne peut pas être clôturé dans cet état.')]);
            }
            if ($recorder->id === $reviewer->id) {
                throw ValidationException::withMessages(['reviewer' => __('Le rapprochement indépendant exige un second acteur.')]);
            }
            $values = collect(['for', 'against', 'abstention', 'invalid', 'not_cast'])
                ->mapWithKeys(fn ($key) => [$key => (int) ($totals[$key] ?? -1)]);
            if ($values->contains(fn ($value) => $value < 0) || (int) ($totals['denominator'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['totals' => __('Les totaux et le dénominateur doivent être explicites et non négatifs.')]);
            }
            $denominator = (int) $totals['denominator'];
            $eligible = $values->sum();
            if ($eligible !== $denominator) {
                throw ValidationException::withMessages(['denominator' => __('Le dénominateur ne correspond pas aux totaux réconciliés; aucune normalisation automatique n’est permise.')]);
            }
            $adopted = app(VotingRuleEngine::class)->decide($resolution->ruleVersion, $values['for'], $denominator);
            $aggregatePayload = [
                'resolution_id' => $resolution->id, 'for' => $values['for'], 'against' => $values['against'],
                'abstention' => $values['abstention'], 'invalid' => $values['invalid'],
                'not_cast' => $values['not_cast'], 'denominator' => $denominator,
                'evidence_version_id' => $evidence->id,
            ];
            $checksum = hash('sha256', json_encode($aggregatePayload, JSON_THROW_ON_ERROR));
            AssemblySecretBallotAggregate::create([
                'organization_id' => $resolution->assembly->organization_id,
                'residence_id' => $resolution->assembly->residence_id,
                'assembly_id' => $resolution->assembly_id, 'resolution_id' => $resolution->id,
                'for_weight' => $values['for'], 'against_weight' => $values['against'],
                'abstention_weight' => $values['abstention'], 'invalid_weight' => $values['invalid'],
                'not_cast_weight' => $values['not_cast'], 'weight_denominator' => $denominator,
                'reconciliation_document_version_id' => $evidence->id, 'checksum' => $checksum,
                'recorded_by' => $recorder->id, 'reviewed_by' => $reviewer->id, 'closed_at' => now('UTC'),
            ]);
            $resultPayload = [
                'rule' => $resolution->ruleSnapshot->payload,
                'ballots' => [],
                'totals' => [
                    'eligible' => $eligible, 'present' => $eligible - $values['not_cast'], 'represented' => 0,
                    'for' => $values['for'], 'against' => $values['against'],
                    'abstention' => $values['abstention'], 'invalid' => $values['invalid'],
                    'non' => $values['not_cast'], 'denominator' => $denominator, 'adopted' => $adopted,
                ],
            ];
            $result = ResolutionResult::create([
                'resolution_id' => $resolution->id, 'version' => 1,
                'total_eligible_weight' => $eligible, 'present_weight' => $eligible - $values['not_cast'],
                'represented_weight' => 0, 'for_weight' => $values['for'], 'against_weight' => $values['against'],
                'abstention_weight' => $values['abstention'], 'invalid_weight' => $values['invalid'],
                'non_participating_weight' => $values['not_cast'], 'numerator' => $values['for'],
                'denominator' => $denominator, 'threshold_numerator' => $resolution->ruleVersion->threshold_numerator,
                'threshold_denominator' => $resolution->ruleVersion->threshold_denominator,
                'comparison' => $resolution->ruleVersion->comparison, 'adopted' => $adopted,
                'rule_identifier' => $resolution->ruleVersion->identifier,
                'rule_version' => $resolution->ruleVersion->version,
                'rule_snapshot' => $resolution->ruleSnapshot->payload, 'ballot_snapshot' => [],
                'checksum' => hash('sha256', json_encode($resultPayload, JSON_THROW_ON_ERROR)),
                'finalized_by' => $reviewer->id, 'finalized_at' => now('UTC'),
            ]);
            $verified = $resolution->ruleVersion->status === 'active'
                && in_array($resolution->ruleVersion->confidence, ['professionally_reviewed', 'counsel_reviewed'], true);
            $resolution->update([
                'status' => $adopted ? 'adopted' : 'rejected',
                'legal_validity_status' => $verified ? 'reviewed_configuration' : 'unverified',
                'voting_closed_at' => now('UTC'), 'voting_closed_by' => $reviewer->id, 'immutable_at' => now('UTC'),
            ]);
            $this->transition($resolution, 'voting_open', $resolution->status, $reviewer, 'Independent secret-ballot aggregate reconciliation.', "secret-close:{$resolution->id}", $evidence);

            return $result;
        });
    }

    public function approveMinutes(AssemblyMinuteVersion $version, string $type, User $actor, ?GovernanceDocumentVersion $evidence = null): AssemblyMinutes
    {
        return DB::transaction(function () use ($version, $type, $actor, $evidence) {
            $version = AssemblyMinuteVersion::query()->whereKey($version->id)->with('minutes.assembly')->lockForUpdate()->firstOrFail();
            if (! in_array($type, ['review', 'approval'], true) || $version->status === 'signed') {
                throw ValidationException::withMessages(['minutes' => __('Cette approbation de procès-verbal est invalide.')]);
            }
            $version->minutes->approvals()->updateOrCreate(
                ['minute_version_id' => $version->id, 'approval_type' => $type],
                ['status' => 'approved', 'actor_id' => $actor->id, 'evidence_version_id' => $evidence?->id, 'approved_at' => now('UTC')],
            );
            activity()->performedOn($version->minutes)->causedBy($actor)->withProperties(['organization_id' => $version->minutes->organization_id, 'residence_id' => $version->minutes->residence_id, 'minute_version_id' => $version->id, 'approval_type' => $type])->log('governance.minutes_approved');

            return $version->minutes->fresh('approvals');
        });
    }

    public function finalizeAssembly(Assembly $assembly, User $actor): Assembly
    {
        return DB::transaction(function () use ($assembly, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->with(['eligibilitySnapshot', 'resolutions.finalResult', 'minutes.signedVersion', 'minutes.approvals', 'quorumSnapshots'])->lockForUpdate()->firstOrFail();
            if ($assembly->finalized_at) {
                return $assembly;
            }
            if ($assembly->eligibilitySnapshot?->status !== 'reviewed' || $assembly->eligibilitySnapshot?->stale_at || $assembly->resolutions->isEmpty() || $assembly->resolutions->contains(fn ($resolution) => ! $resolution->finalResult || $resolution->legal_validity_status !== 'reviewed_configuration') || ! $assembly->minutes?->signedVersion || $assembly->minutes->approvals->where('approval_type', 'approval')->isEmpty() || ! $assembly->quorumSnapshots->whereNotNull('confirmed_at')->last()) {
                throw ValidationException::withMessages(['finalization' => __('Éligibilité, quorum, résultats, procès-verbal et approbations vérifiés sont obligatoires. La finalisation légale reste bloquée si une règle est non vérifiée.')]);
            }
            $payload = [
                'assembly_id' => $assembly->id,
                'eligibility' => $assembly->eligibilitySnapshot->input_fingerprint,
                'quorum' => $assembly->quorumSnapshots->whereNotNull('confirmed_at')->last()->checksum,
                'results' => $assembly->resolutions->map(fn ($resolution) => $resolution->finalResult->checksum)->all(),
                'minutes' => $assembly->minutes->signedVersion->checksum,
            ];
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $assembly->minutes->signedVersion->update(['finalization_fingerprint' => $fingerprint, 'immutable_at' => now('UTC')]);
            $assembly->update(['status' => 'finalized', 'finalization_fingerprint' => $fingerprint, 'finalized_at' => now('UTC'), 'finalized_by' => $actor->id, 'legal_verification_status' => 'reviewed_configuration']);
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'fingerprint' => $fingerprint])->log('governance.assembly_finalized');

            return $assembly->fresh();
        });
    }

    private function transition(AssemblyResolution $resolution, string $from, string $to, User $actor, ?string $reason, string $idempotencyKey, ?GovernanceDocumentVersion $evidence = null): void
    {
        $resolution->transitions()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            ['from_status' => $from, 'to_status' => $to, 'actor_id' => $actor->id, 'reason' => $reason, 'evidence_version_id' => $evidence?->id, 'transitioned_at' => now('UTC')],
        );
        activity()->performedOn($resolution)->causedBy($actor)->withProperties(['organization_id' => $resolution->assembly->organization_id, 'residence_id' => $resolution->assembly->residence_id, 'from' => $from, 'to' => $to])->log('governance.resolution_transitioned');
    }
}
