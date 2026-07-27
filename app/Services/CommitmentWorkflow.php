<?php

namespace App\Services;

use App\Models\ExpenseCommitment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitmentWorkflow
{
    public function __construct(private DocumentNumberService $numbers) {}

    public function transition(ExpenseCommitment $commitment, User $actor, string $action, ?string $reason = null): ExpenseCommitment
    {
        return DB::transaction(function () use ($commitment, $actor, $action, $reason) {
            $commitment = ExpenseCommitment::query()->whereKey($commitment->id)->with(['residence', 'exercise'])->lockForUpdate()->firstOrFail();
            if ($commitment->exercise->status === 'closed') {
                throw ValidationException::withMessages(['exercise' => __('L’exercice financier est clôturé.')]);
            }
            $allowed = ['submit' => ['draft'], 'approve' => ['submitted'], 'reject' => ['submitted'], 'cancel' => ['draft', 'submitted', 'approved']];
            if (! in_array($commitment->status, $allowed[$action] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Transition d’engagement invalide.')]);
            }
            if (in_array($action, ['reject', 'cancel'], true) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => __('Le motif est obligatoire.')]);
            }
            $attributes = match ($action) {
                'submit' => ['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id],
                'approve' => ['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id],
                'reject' => ['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $actor->id, 'decision_reason' => $reason],
                default => ['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'decision_reason' => $reason],
            };
            if (! $commitment->number) {
                $attributes['number'] = $this->numbers->next($commitment->residence, 'CMT', (int) $commitment->committed_on->format('Y'));
            }
            $commitment->update($attributes);
            activity()->performedOn($commitment)->causedBy($actor)->withProperties(['organization_id' => $commitment->organization_id, 'residence_id' => $commitment->residence_id, 'action' => $action, 'reason' => $reason])->log("commitment.$action");

            return $commitment->fresh();
        });
    }
}
