<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\GovernanceMandate;
use App\Models\ResolutionExecutionAction;
use App\Models\User;
use Carbon\CarbonImmutable;

class GovernanceDeadlineService
{
    public function evaluate(?CarbonImmutable $date = null, bool $apply = true, int $limit = 500): array
    {
        $date ??= CarbonImmutable::today();
        $limit = max(1, min($limit, 500));
        $counts = ['upcoming' => 0, 'execution_due' => 0, 'execution_overdue' => 0, 'mandate_expiring' => 0];
        Assembly::with('electorate')->whereIn('status', ['convocation_issued', 'scheduled'])->whereBetween('meeting_date', [$date->toDateString(), $date->addDays(3)->toDateString()])->orderBy('id')->limit($limit)->each(function ($a) use (&$counts, $apply) {
            foreach ($a->electorate as $e) {
                $counts['upcoming']++;
                if ($apply) {
                    app(GovernanceNotificationService::class)->electorateEvent($e, 'upcoming_assembly', "assembly:{$a->id}:upcoming:{$a->meeting_date->toDateString()}", ['title' => 'Assemblée à venir', 'message' => 'Votre assemblée approche; consultez la convocation et les documents.'], route('owner-governance.show', $a));
                }
            }
        });
        ResolutionExecutionAction::with('resolution.assembly')->whereIn('status', ['pending', 'in_progress'])->whereNotNull('responsible_user_id')->whereDate('due_on', '<=', $date->addDays(3))->orderBy('id')->limit($limit)->each(function ($x) use (&$counts, $date, $apply) {
            $type = $x->due_on->lt($date) ? 'execution_overdue' : 'execution_due';
            $counts[$type]++;
            if ($apply && $u = User::find($x->responsible_user_id)) {
                app(GovernanceNotificationService::class)->userEvent($u, $x->resolution->assembly, $type, "execution:{$x->id}:{$type}:{$date->toDateString()}", ['title' => $type === 'execution_overdue' ? 'Action en retard' : 'Échéance d’exécution proche', 'message' => 'Consultez le registre d’exécution de la décision.'], route('governance.show', $x->resolution->assembly));
            }
        });
        GovernanceMandate::with('residence')->where('status', 'active')->whereBetween('ends_on', [$date, $date->addDays(30)])->orderBy('id')->limit($limit)->each(function ($m) use (&$counts, $apply, $date) {
            $counts['mandate_expiring']++;
            if ($apply && $m->user_id && $u = User::find($m->user_id)) {
                app(GovernanceNotificationService::class)->userEvent($u, Assembly::where('residence_id', $m->residence_id)->latest('id')->first() ?? new Assembly(['organization_id' => $m->organization_id, 'residence_id' => $m->residence_id]), 'mandate_expiring', "mandate:{$m->id}:expiring:{$date->toDateString()}", ['title' => 'Échéance de mandat proche', 'message' => 'Un mandat de gouvernance approche de son échéance.'], route('governance.mandates.index'));
            }
        });

        return $counts + ['applied' => $apply, 'limit' => $limit];
    }
}
