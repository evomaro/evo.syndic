<?php

namespace App\Services;

use App\Models\AssemblyBallot;
use App\Models\AssemblyElectorate;
use App\Models\AssemblyResolution;
use App\Models\ResolutionResult;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BallotService
{
    public function __construct(private VotingRuleEngine $engine) {}

    public function enter(AssemblyResolution $resolution, AssemblyElectorate $electorate, string $choice, User $actor, ?int $proxyId = null): AssemblyBallot
    {
        return DB::transaction(function () use ($resolution, $electorate, $choice, $actor, $proxyId) {
            $resolution = AssemblyResolution::query()->whereKey($resolution->id)->with(['assembly', 'ruleSnapshot'])->lockForUpdate()->firstOrFail();
            if ($resolution->assembly_id !== $electorate->assembly_id || ! in_array($choice, ['for', 'against', 'abstention', 'not_participating', 'ineligible', 'invalid'], true)) {
                abort(404);
            }
            if ($resolution->status !== 'authorized' || $resolution->assembly->status !== 'in_session' || $resolution->assembly->quorum_status !== 'met' || ! $resolution->ruleSnapshot) {
                throw ValidationException::withMessages(['ballot' => __('Le vote contraignant n’est pas ouvert.')]);
            }
            if ($electorate->eligibility_status !== 'eligible') {
                throw ValidationException::withMessages(['ballot' => __('Cette voix n’est pas éligible.')]);
            }
            $attendance = $electorate->attendance;
            if (! $attendance || ! in_array($attendance->status, ['present', 'represented'], true)) {
                throw ValidationException::withMessages(['ballot' => __('La voix n’est ni présente ni valablement représentée.')]);
            }
            if ($attendance->status === 'represented') {
                $proxy = $electorate->assembly->proxies()->whereKey($proxyId)->where('principal_electorate_id', $electorate->id)->where('status', 'verified')->first();
                if (! $proxy) {
                    throw ValidationException::withMessages(['proxy' => __('Le mandat vérifié est requis.')]);
                }
            }
            try {
                return AssemblyBallot::create(['organization_id' => $electorate->organization_id, 'residence_id' => $electorate->residence_id, 'assembly_id' => $electorate->assembly_id, 'resolution_id' => $resolution->id, 'electorate_id' => $electorate->id, 'voter_user_id' => $actor->id, 'represented_electorate_id' => $attendance->status === 'represented' ? $electorate->id : null, 'proxy_id' => $proxyId, 'weight_numerator' => $electorate->voting_weight_numerator, 'weight_denominator' => $electorate->voting_weight_denominator, 'ownership_unit_snapshot' => $electorate->only(['contact_name_snapshot', 'lot_ids', 'ownership_fractions', 'snapshot_version']), 'rule_snapshot' => $resolution->ruleSnapshot->payload, 'choice' => $choice, 'entered_by' => $actor->id, 'entered_at' => now('UTC')]);
            } catch (QueryException) {
                throw ValidationException::withMessages(['ballot' => __('Une voix existe déjà pour ce droit de vote et cette résolution.')]);
            }
        });
    }

    public function correct(AssemblyBallot $ballot, string $choice, User $actor, string $reason): AssemblyBallot
    {
        return DB::transaction(function () use ($ballot, $choice, $actor, $reason) {
            $ballot = AssemblyBallot::query()->whereKey($ballot->id)->lockForUpdate()->firstOrFail();
            if ($ballot->finalized_at || $ballot->resolution->status !== 'authorized' || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['ballot' => __('Une voix finalisée est immuable et toute correction exige un motif détaillé.')]);
            }
            if (! in_array($choice, ['for', 'against', 'abstention', 'not_participating', 'ineligible', 'invalid'], true)) {
                throw ValidationException::withMessages(['choice' => __('Choix invalide.')]);
            }
            $from = $ballot->choice;
            $ballot->update(['choice' => $choice]);
            $ballot->corrections()->create(['from_choice' => $from, 'to_choice' => $choice, 'reason' => trim($reason), 'actor_id' => $actor->id, 'corrected_at' => now('UTC')]);
            activity()->performedOn($ballot)->causedBy($actor)->withProperties(['organization_id' => $ballot->organization_id, 'residence_id' => $ballot->residence_id, 'from' => $from, 'to' => $choice, 'reason' => trim($reason)])->log('governance.ballot_corrected');

            return $ballot->fresh();
        });
    }

    public function finalize(AssemblyResolution $resolution, User $actor): ResolutionResult
    {
        return DB::transaction(function () use ($resolution, $actor) {
            $resolution = AssemblyResolution::query()->whereKey($resolution->id)->with(['assembly.electorate', 'ruleVersion', 'ruleSnapshot'])->lockForUpdate()->firstOrFail();
            if (in_array($resolution->status, ['adopted', 'rejected'], true) && $existing = $resolution->results()->latest('version')->first()) {
                return $existing;
            }
            if ($resolution->assembly->status !== 'in_session' || $resolution->assembly->quorum_status !== 'met' || ! $resolution->ruleSnapshot) {
                throw ValidationException::withMessages(['result' => __('La résolution ne peut pas être finalisée.')]);
            }
            $ballots = $resolution->ballots()->lockForUpdate()->get();
            $eligible = (int) $resolution->assembly->electorate->where('eligibility_status', 'eligible')->sum('voting_weight_numerator');
            $sum = fn (string $choice) => (int) $ballots->where('choice', $choice)->sum('weight_numerator');
            $for = $sum('for');
            $against = $sum('against');
            $abstention = $sum('abstention');
            $invalid = $sum('invalid');
            $non = $sum('not_participating');
            $present = (int) $resolution->assembly->attendanceRecords()->where('status', 'present')->sum('active_weight_numerator');
            $represented = (int) $resolution->assembly->attendanceRecords()->where('status', 'represented')->sum('active_weight_numerator');
            $denominator = $resolution->ruleVersion->denominator_definition === 'all_eligible_weight' ? $eligible : $present + $represented;
            if ($denominator <= 0) {
                throw ValidationException::withMessages(['result' => __('Le dénominateur du vote est nul.')]);
            }
            $adopted = $this->engine->decide($resolution->ruleVersion, $for, $denominator);
            $snapshot = $ballots->map(fn ($b) => ['ballot_id' => $b->id, 'electorate_id' => $b->electorate_id, 'choice' => $b->choice, 'weight' => (int) $b->weight_numerator])->all();
            $payload = ['rule' => $resolution->ruleSnapshot->payload, 'ballots' => $snapshot, 'totals' => compact('eligible', 'present', 'represented', 'for', 'against', 'abstention', 'invalid', 'non', 'denominator', 'adopted')];
            $previous = $resolution->results()->latest('version')->first();
            $result = ResolutionResult::create(['resolution_id' => $resolution->id, 'version' => (int) $resolution->results()->max('version') + 1, 'total_eligible_weight' => $eligible, 'present_weight' => $present, 'represented_weight' => $represented, 'for_weight' => $for, 'against_weight' => $against, 'abstention_weight' => $abstention, 'invalid_weight' => $invalid, 'non_participating_weight' => $non, 'numerator' => $for, 'denominator' => $denominator, 'threshold_numerator' => $resolution->ruleVersion->threshold_numerator, 'threshold_denominator' => $resolution->ruleVersion->threshold_denominator, 'comparison' => $resolution->ruleVersion->comparison, 'adopted' => $adopted, 'rule_identifier' => $resolution->ruleVersion->identifier, 'rule_version' => $resolution->ruleVersion->version, 'rule_snapshot' => $resolution->ruleSnapshot->payload, 'ballot_snapshot' => $snapshot, 'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'finalized_by' => $actor->id, 'finalized_at' => now('UTC'), 'supersedes_result_id' => $previous?->id, 'reopen_reason' => $resolution->reopen_reason]);
            $resolution->ballots()->update(['finalized_at' => now('UTC')]);
            $resolution->update(['status' => $adopted ? 'adopted' : 'rejected', 'final_text_fr' => $resolution->proposed_text_fr, 'final_text_ar' => $resolution->proposed_text_ar]);
            activity()->performedOn($resolution)->causedBy($actor)->withProperties(['organization_id' => $resolution->assembly->organization_id, 'residence_id' => $resolution->assembly->residence_id, 'result_id' => $result->id, 'adopted' => $adopted, 'checksum' => $result->checksum])->log('governance.result_finalized');

            return $result;
        });
    }

    public function reopen(AssemblyResolution $resolution, User $actor, string $reason): AssemblyResolution
    {
        return DB::transaction(function () use ($resolution, $actor, $reason) {
            $resolution = AssemblyResolution::query()->whereKey($resolution->id)->with('assembly')->lockForUpdate()->firstOrFail();
            if (! in_array($resolution->status, ['adopted', 'rejected'], true) || ! $resolution->results()->exists() || $resolution->assembly->minutes?->status === 'signed' || mb_strlen(trim($reason)) < 15) {
                throw ValidationException::withMessages(['result' => __('La réouverture exceptionnelle exige un résultat final, un motif détaillé et aucun procès-verbal signé.')]);
            }$from = $resolution->status;
            $resolution->update(['status' => 'authorized', 'reopen_reason' => trim($reason), 'reopened_at' => now('UTC'), 'reopened_by' => $actor->id]);
            $resolution->ballots()->update(['finalized_at' => null]);
            activity()->performedOn($resolution)->causedBy($actor)->withProperties(['organization_id' => $resolution->assembly->organization_id, 'residence_id' => $resolution->assembly->residence_id, 'from' => $from, 'original_result_id' => $resolution->results()->latest('version')->value('id'), 'reason' => trim($reason)])->log('governance.result_reopened');

            return $resolution->fresh();
        });
    }
}
