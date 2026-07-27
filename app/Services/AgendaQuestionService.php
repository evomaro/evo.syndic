<?php

namespace App\Services;

use App\Models\AgendaQuestionSubmission;
use App\Models\Assembly;
use App\Models\AssemblyElectorate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgendaQuestionService
{
    public function __construct(private GovernanceNotificationService $notifications) {}

    public function submit(Assembly $assembly, AssemblyElectorate $electorate, User $user, string $fr, ?string $ar): AgendaQuestionSubmission
    {
        abort_unless($electorate->assembly_id === $assembly->id && $electorate->contact->users()->whereKey($user->id)->exists(), 404);
        $deadline = $assembly->meeting_date->copy()->startOfDay()->setTimeFromTimeString($assembly->starts_at)->subHours(config('governance.agenda_question_hours'));
        if (now()->gt($deadline)) {
            throw ValidationException::withMessages(['question' => __('Le délai de soumission est dépassé.')]);
        }

        return AgendaQuestionSubmission::create(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'assembly_id' => $assembly->id, 'electorate_id' => $electorate->id, 'submitted_by' => $user->id, 'question_fr' => $fr, 'question_ar' => $ar, 'submission_deadline_at' => $deadline]);
    }

    public function decide(AgendaQuestionSubmission $question, string $status, User $actor, ?string $reason): AgendaQuestionSubmission
    {
        return DB::transaction(function () use ($question, $status, $actor, $reason) {
            $question = AgendaQuestionSubmission::query()->whereKey($question->id)->with('electorate')->lockForUpdate()->firstOrFail();
            if ($question->status !== 'submitted' || ! in_array($status, ['accepted', 'rejected'], true) || ($status === 'rejected' && mb_strlen(trim((string) $reason)) < 10)) {
                throw ValidationException::withMessages(['question' => __('Décision invalide; un motif détaillé est requis pour le refus.')]);
            }$question->update(['status' => $status, 'decision_reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now('UTC')]);
            activity()->performedOn($question)->causedBy($actor)->withProperties(['organization_id' => $question->organization_id, 'residence_id' => $question->residence_id, 'status' => $status, 'reason' => $reason])->log('governance.agenda_question_decided');
            $this->notifications->electorateEvent($question->electorate, 'agenda_question_'.$status, "agenda-question:{$question->id}:{$status}", ['title' => $status === 'accepted' ? 'Question acceptée' : 'Question refusée', 'message' => $status === 'accepted' ? 'Votre question a été acceptée.' : 'Votre question a été examinée; consultez la décision.'], route('owner-governance.show', $question->assembly_id));

            return $question->fresh();
        });
    }
}
