<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyQuorumSnapshot;
use App\Models\GovernanceRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuorumService
{
    public function calculate(Assembly $assembly, GovernanceRuleVersion $rule, User $actor): AssemblyQuorumSnapshot
    {
        return DB::transaction(function () use ($assembly, $rule, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->lockForUpdate()->firstOrFail();
            if (! in_array($assembly->status, ['scheduled', 'in_session'], true)) {
                throw ValidationException::withMessages(['quorum' => __('Le quorum ne peut pas être arrêté à cette étape.')]);
            }
            $eligible = $assembly->electorate()->where('eligibility_status', 'eligible')->get();
            $active = $assembly->attendanceRecords()->whereIn('status', ['present', 'represented'])->get();
            $eligibleHeadcount = $eligible->count();
            $activeHeadcount = $active->count();
            $met = $assembly->convocation_number === 2 ? true : $activeHeadcount * 2 >= $eligibleHeadcount;
            $input = ['eligible_ids' => $eligible->pluck('id')->all(), 'attendance' => $active->map(fn ($r) => ['electorate_id' => $r->electorate_id, 'status' => $r->status, 'weight' => (int) $r->active_weight_numerator])->all(), 'convocation_number' => $assembly->convocation_number];
            $snapshot = $assembly->quorumSnapshots()->create(['governance_rule_version_id' => $rule->id, 'sequence' => (int) $assembly->quorumSnapshots()->max('sequence') + 1, 'eligible_headcount' => $eligibleHeadcount, 'present_or_represented_headcount' => $activeHeadcount, 'eligible_weight_numerator' => (int) $eligible->sum('voting_weight_numerator'), 'represented_weight_numerator' => (int) $active->sum('active_weight_numerator'), 'weight_denominator' => (int) ($eligible->first()?->voting_weight_denominator ?? 1), 'threshold_numerator' => $assembly->convocation_number === 2 ? 0 : 1, 'threshold_denominator' => $assembly->convocation_number === 2 ? 1 : 2, 'quorum_met' => $met, 'input_snapshot' => $input, 'checksum' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), 'calculated_by' => $actor->id, 'calculated_at' => now('UTC')]);
            $assembly->update(['quorum_status' => $met ? 'met' : 'failed']);
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'quorum_snapshot_id' => $snapshot->id, 'met' => $met])->log('governance.quorum_calculated');

            return $snapshot;
        });
    }
}
