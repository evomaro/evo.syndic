<?php

namespace App\Services;

use App\Models\ComplianceDeadlineOverride;
use App\Models\ComplianceEvidence;
use App\Models\ComplianceObligation;
use App\Models\ComplianceObligationAssignment;
use App\Models\ComplianceObligationTransition;
use App\Models\ComplianceSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplianceObligationWorkflow
{
    private const TRANSITIONS = [
        'upcoming' => ['in_preparation', 'waived', 'not_applicable', 'cancelled'],
        'in_preparation' => ['ready_for_review', 'cancelled'],
        'ready_for_review' => ['ready_for_submission', 'correction_required'],
        'ready_for_submission' => ['submitted', 'correction_required'],
        'submitted' => ['acknowledged', 'rejected', 'correction_required', 'completed_internally'],
        'acknowledged' => ['accepted', 'rejected', 'correction_required'],
        'rejected' => ['correction_required'],
        'correction_required' => ['in_preparation', 'ready_for_review'],
    ];

    public function assign(ComplianceObligation $obligation, ?User $user, ?string $role, string $type, User $actor): ComplianceObligationAssignment
    {
        if ($user && ! $user->belongsToOrganization($obligation->organization_id)) {
            throw ValidationException::withMessages(['user_id' => __('Le destinataire n’appartient pas à l’organisation.')]);
        }
        if ($user && $obligation->residence_id) {
            $membership = $user->organizations()->whereKey($obligation->organization_id)->first()?->pivot;
            if (! $membership?->all_residences && ! $user->residences()->whereKey($obligation->residence_id)->exists()) {
                throw ValidationException::withMessages(['user_id' => __('Le destinataire n’est pas autorisé pour cette résidence.')]);
            }
        }

        return DB::transaction(function () use ($obligation, $user, $role, $type, $actor) {
            $obligation = ComplianceObligation::query()->lockForUpdate()->findOrFail($obligation->id);
            ComplianceObligationAssignment::where('obligation_id', $obligation->id)
                ->where('assignment_type', $type)
                ->whereNull('ended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->update(['ended_at' => now('UTC'), 'ended_by' => $actor->id]);
            $assignment = ComplianceObligationAssignment::create([
                'obligation_id' => $obligation->id, 'user_id' => $user?->id, 'role' => $role,
                'assignment_type' => $type, 'assigned_by' => $actor->id, 'assigned_at' => now('UTC'),
            ]);
            activity()->performedOn($obligation)->causedBy($actor)->withProperties(['organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id, 'assignment_id' => $assignment->id, 'assignment_type' => $type])->log('compliance.obligation_assigned');

            return $assignment;
        }, 3);
    }

    public function transition(ComplianceObligation $obligation, string $to, User $actor, ?string $reason = null, ?ComplianceEvidence $evidence = null): ComplianceObligation
    {
        return DB::transaction(function () use ($obligation, $to, $actor, $reason, $evidence) {
            $obligation = ComplianceObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            $from = $obligation->operational_status;
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Transition de conformité invalide.')]);
            }
            if (in_array($to, ['rejected', 'correction_required', 'waived', 'not_applicable'], true) && ! trim((string) $reason)) {
                throw ValidationException::withMessages(['reason' => __('Un motif est requis.')]);
            }
            if ($to === 'submitted' && ! ComplianceSubmission::where('obligation_id', $obligation->id)->exists()) {
                throw ValidationException::withMessages(['submission' => __('Un enregistrement de soumission est requis.')]);
            }
            if (in_array($to, ['acknowledged', 'accepted'], true) && (! $evidence || ! in_array($evidence->type, ['authority_acknowledgement', 'approval_record'], true))) {
                throw ValidationException::withMessages(['evidence' => __('Une preuve explicite d’accusé ou d’acceptation externe est requise.')]);
            }
            $maker = ComplianceObligationAssignment::where('obligation_id', $obligation->id)->where('assignment_type', 'responsible')->whereNull('ended_at')->value('user_id');
            if ($to === 'ready_for_submission' && $maker && (int) $maker === (int) $actor->id) {
                throw ValidationException::withMessages(['actor' => __('La séparation préparation/contrôle est requise.')]);
            }
            $obligation->update(['operational_status' => $to]);
            ComplianceObligationTransition::create([
                'obligation_id' => $obligation->id, 'from_status' => $from, 'to_status' => $to,
                'reason' => $reason, 'evidence_id' => $evidence?->id, 'actor_id' => $actor->id,
                'transitioned_at' => now('UTC'), 'created_at' => now('UTC'),
            ]);
            activity()->performedOn($obligation)->causedBy($actor)->withProperties(['organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id, 'from' => $from, 'to' => $to, 'evidence_id' => $evidence?->id])->log('compliance.obligation_transitioned');

            return $obligation->fresh();
        }, 3);
    }

    public function submit(ComplianceObligation $obligation, array $data, User $actor): ComplianceSubmission
    {
        return DB::transaction(function () use ($obligation, $data, $actor) {
            $obligation = ComplianceObligation::query()->lockForUpdate()->findOrFail($obligation->id);
            $attempt = (int) ComplianceSubmission::where('obligation_id', $obligation->id)->max('attempt') + 1;
            $submission = ComplianceSubmission::create($data + ['obligation_id' => $obligation->id, 'attempt' => $attempt, 'submitted_by' => $actor->id, 'recorded_at' => now('UTC')]);
            activity()->performedOn($obligation)->causedBy($actor)->withProperties(['organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id, 'submission_id' => $submission->id, 'attempt' => $attempt])->log('compliance.submission_recorded');

            return $submission;
        }, 3);
    }

    public function overrideDeadline(ComplianceObligation $obligation, string $newDueOn, string $reason, string $evidenceReference, User $actor): ComplianceObligation
    {
        if (trim($reason) === '' || trim($evidenceReference) === '') {
            throw ValidationException::withMessages(['reason' => __('Un motif et une référence de preuve sont requis.')]);
        }

        return DB::transaction(function () use ($obligation, $newDueOn, $reason, $evidenceReference, $actor) {
            $obligation = ComplianceObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            ComplianceDeadlineOverride::create([
                'obligation_id' => $obligation->id, 'previous_due_on' => $obligation->current_due_on,
                'new_due_on' => $newDueOn, 'reason' => trim($reason), 'evidence_reference' => trim($evidenceReference),
                'overridden_by' => $actor->id, 'overridden_at' => now('UTC'),
            ]);
            $obligation->update(['current_due_on' => $newDueOn]);
            activity()->performedOn($obligation)->causedBy($actor)->withProperties(['organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id, 'original_due_on' => $obligation->original_due_on?->toDateString(), 'new_due_on' => $newDueOn, 'evidence_reference' => $evidenceReference])->log('compliance.deadline_overridden');

            return $obligation->fresh();
        }, 3);
    }
}
