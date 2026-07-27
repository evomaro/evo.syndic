<?php

namespace App\Services;

use App\Models\ComplianceApplicabilityDecision;
use App\Models\ComplianceTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplianceApplicabilityService
{
    public function preview(ComplianceTemplateVersion $version, array $attributes): array
    {
        if ($version->status !== 'active') {
            return $this->result('undetermined', __('La version n’est pas active.'), __('الإصدار غير مفعل.'), $attributes);
        }
        if ($version->professional_review_required && $version->professional_review_status !== 'approved') {
            return $this->result('professional_review_required', __('Une interprétation professionnelle est requise.'), __('يلزم تفسير مهني.'), $attributes);
        }
        $rule = $version->applicability_rule ?? [];
        if (! isset($rule['attribute'], $rule['operator'], $rule['value'])) {
            return $this->result('undetermined', __('Aucune règle explicite complète.'), __('لا توجد قاعدة صريحة مكتملة.'), $attributes);
        }
        if (! array_key_exists($rule['attribute'], $attributes) || $attributes[$rule['attribute']] === null || $attributes[$rule['attribute']] === '') {
            return $this->result('undetermined', __('Information explicite manquante : :attribute', ['attribute' => $rule['attribute']]), __('معلومة صريحة مفقودة.'), $attributes);
        }
        $matches = match ($rule['operator']) {
            'equals' => $attributes[$rule['attribute']] === $rule['value'],
            'in' => is_array($rule['value']) && in_array($attributes[$rule['attribute']], $rule['value'], true),
            'boolean_is' => (bool) $attributes[$rule['attribute']] === (bool) $rule['value'],
            default => null,
        };
        if ($matches === null) {
            return $this->result('professional_review_required', __('L’opérateur nécessite une revue.'), __('تتطلب القاعدة مراجعة.'), $attributes);
        }

        return $this->result($matches ? 'applies' : 'does_not_apply', $matches ? __('La règle explicite correspond.') : __('La règle explicite ne correspond pas.'), $matches ? __('القاعدة الصريحة مطابقة.') : __('القاعدة الصريحة غير مطابقة.'), $attributes);
    }

    public function decide(ComplianceTemplateVersion $version, int $organizationId, ?int $residenceId, array $attributes, User $actor, ?int $exerciseId = null, array $deadlineInputs = []): ComplianceApplicabilityDecision
    {
        $preview = $this->preview($version, $attributes);

        return ComplianceApplicabilityDecision::create([
            'organization_id' => $organizationId, 'residence_id' => $residenceId,
            'template_version_id' => $version->id, 'financial_exercise_id' => $exerciseId,
            'outcome' => $preview['outcome'], 'inputs' => $preview['inputs'], 'deadline_inputs' => $deadlineInputs,
            'explanation_fr' => $preview['explanation_fr'], 'explanation_ar' => $preview['explanation_ar'],
            'decided_by' => $actor->id, 'decided_at' => now('UTC'),
        ]);
    }

    public function override(ComplianceApplicabilityDecision $decision, string $outcome, string $reason, string $evidenceReference, User $actor): ComplianceApplicabilityDecision
    {
        if (trim($reason) === '' || trim($evidenceReference) === '') {
            throw ValidationException::withMessages(['reason' => __('Un motif et une référence de preuve sont requis.')]);
        }

        return DB::transaction(function () use ($decision, $outcome, $reason, $evidenceReference, $actor) {
            $decision = ComplianceApplicabilityDecision::query()->lockForUpdate()->findOrFail($decision->id);
            if ($decision->superseded_by_id) {
                throw ValidationException::withMessages(['decision' => __('Cette décision a déjà été remplacée.')]);
            }
            $replacement = $decision->replicate(['supersedes_id', 'superseded_by_id']);
            $replacement->outcome = $outcome;
            $replacement->manual_override = true;
            $replacement->supersedes_id = $decision->id;
            $replacement->override_reason = trim($reason);
            $replacement->evidence_reference = trim($evidenceReference);
            $replacement->decided_by = $actor->id;
            $replacement->decided_at = now('UTC');
            $replacement->save();
            $decision->update(['superseded_by_id' => $replacement->id]);

            if ($outcome !== 'applies') {
                $obligations = DB::table('compliance_obligations')
                    ->where('applicability_decision_id', $decision->id)
                    ->whereNotIn('operational_status', ['accepted', 'completed_internally', 'waived', 'not_applicable', 'cancelled', 'superseded'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($obligations as $obligation) {
                    DB::table('compliance_obligations')->where('id', $obligation->id)->update([
                        'operational_status' => 'superseded',
                        'updated_at' => now('UTC'),
                    ]);
                    DB::table('compliance_obligation_transitions')->insert([
                        'obligation_id' => $obligation->id,
                        'from_status' => $obligation->operational_status,
                        'to_status' => 'superseded',
                        'reason' => trim($reason),
                        'actor_id' => $actor->id,
                        'transitioned_at' => now('UTC'),
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
                }
            }
            activity()->performedOn($replacement)->causedBy($actor)->withProperties(['organization_id' => $replacement->organization_id, 'residence_id' => $replacement->residence_id, 'previous_decision_id' => $decision->id, 'outcome' => $outcome])->log('compliance.applicability_overridden');

            return $replacement;
        }, 3);
    }

    private function result(string $outcome, string $fr, string $ar, array $inputs): array
    {
        return compact('outcome', 'inputs') + ['explanation_fr' => $fr, 'explanation_ar' => $ar];
    }
}
