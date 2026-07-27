<?php

namespace App\Services;

use App\Models\ComplianceEscalationOccurrence;
use App\Models\ComplianceObligation;
use App\Models\ComplianceObligationAssignment;
use App\Models\ComplianceReminderOccurrence;
use App\Models\ComplianceReminderPolicy;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\PortalNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ComplianceReminderService
{
    private const TERMINAL = ['accepted', 'completed_internally', 'waived', 'not_applicable', 'cancelled', 'superseded'];

    public function generate(CarbonImmutable $date, int $limit = 500): array
    {
        $generated = 0;
        $skipped = 0;
        ComplianceObligation::query()->whereNotIn('operational_status', self::TERMINAL)->orderBy('id')->limit($limit)->get()
            ->each(function (ComplianceObligation $obligation) use ($date, &$generated, &$skipped) {
                DB::transaction(function () use ($obligation, $date, &$generated, &$skipped) {
                    $obligation = ComplianceObligation::query()->lockForUpdate()->find($obligation->id);
                    if (! $obligation || in_array($obligation->operational_status, self::TERMINAL, true)) {
                        return;
                    }
                    ComplianceReminderPolicy::query()->where('organization_id', $obligation->organization_id)
                        ->where(fn ($q) => $q->whereNull('residence_id')->orWhere('residence_id', $obligation->residence_id))
                        ->where(fn ($q) => $q->whereNull('template_id')->orWhere('template_id', $obligation->template_id))
                        ->where('active', true)->orderBy('id')->get()->each(function (ComplianceReminderPolicy $policy) use ($obligation, $date, &$generated, &$skipped) {
                            foreach ($policy->triggers as $trigger) {
                                if (! $this->matches($obligation, $trigger, $date)) {
                                    continue;
                                }
                                foreach ($this->recipients($obligation, $policy) as $user) {
                                    foreach (array_filter([$policy->database_enabled ? 'database' : null, $policy->email_enabled ? 'mail' : null]) as $channel) {
                                        $key = hash('sha256', implode('|', [$obligation->id, $policy->id, $user->id, $trigger['type'], $trigger['days'] ?? 0, $channel, $date->toDateString()]));
                                        $created = ComplianceReminderOccurrence::query()->firstOrCreate(['idempotency_key' => $key], [
                                            'organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id,
                                            'obligation_id' => $obligation->id, 'policy_id' => $policy->id,
                                            'recipient_user_id' => $user->id, 'trigger' => $trigger['type'],
                                            'triggered_for_on' => $date->toDateString(), 'channel' => $channel,
                                            'scheduled_at' => now('UTC'), 'status' => 'pending',
                                        ])->wasRecentlyCreated;
                                        $created ? $generated++ : $skipped++;
                                    }
                                }
                            }
                        });
                }, 3);
            });

        return compact('generated', 'skipped');
    }

    public function dispatch(int $limit = 200): array
    {
        $delivered = 0;
        $failed = 0;
        ComplianceReminderOccurrence::query()->where('status', 'pending')->where('scheduled_at', '<=', now('UTC'))->orderBy('id')->limit($limit)->get()
            ->each(function (ComplianceReminderOccurrence $occurrence) use (&$delivered, &$failed) {
                DB::transaction(function () use ($occurrence, &$delivered, &$failed) {
                    $occurrence = ComplianceReminderOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->first();
                    if (! $occurrence || $occurrence->status !== 'pending') {
                        return;
                    }
                    $policy = ComplianceReminderPolicy::query()->find($occurrence->policy_id);
                    if ($policy?->digest) {
                        $this->dispatchDigest($occurrence, $policy, $delivered, $failed);

                        return;
                    }
                    $obligation = ComplianceObligation::query()->lockForUpdate()->find($occurrence->obligation_id);
                    $organization = Organization::query()->find($occurrence->organization_id);
                    $user = User::query()->find($occurrence->recipient_user_id);
                    if (! $obligation || ! $organization || ! $user || in_array($obligation->operational_status, self::TERMINAL, true)
                        || ! $this->stillMatches($obligation, $policy, $occurrence)
                        || ! $user->belongsToOrganization($organization) || ! $this->authorizedForResidence($user, $organization, $obligation->residence_id)) {
                        $occurrence->update(['status' => 'failed', 'failed_at' => now('UTC'), 'failure_code' => 'stale_or_unauthorized_scope', 'attempts' => $occurrence->attempts + 1]);
                        $failed++;

                        return;
                    }
                    $preference = NotificationPreference::firstOrCreate(['user_id' => $user->id, 'organization_id' => $organization->id]);
                    if (($occurrence->channel === 'mail' && (! $organization->compliance_email_enabled || ! $preference->email_enabled))
                        || ($occurrence->channel === 'database' && ! $preference->database_enabled)) {
                        $occurrence->update(['status' => 'failed', 'failed_at' => now('UTC'), 'failure_code' => 'channel_disabled', 'attempts' => $occurrence->attempts + 1]);
                        $failed++;

                        return;
                    }
                    try {
                        $user->notify(new PortalNotification([
                            'type' => 'compliance.reminder', 'organization_id' => $organization->id,
                            'residence_id' => $obligation->residence_id, 'title' => __('Rappel de conformité'),
                            'message' => __('Une obligation nécessite votre attention. Ceci ne constitue pas un conseil juridique ou fiscal.'),
                            'url' => route('compliance.obligations.show', $obligation),
                        ], [$occurrence->channel], 'compliance.reminder'));
                        $occurrence->update(['status' => 'delivered', 'delivered_at' => now('UTC'), 'attempts' => $occurrence->attempts + 1]);
                        $delivered++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $occurrence->update(['status' => 'failed', 'failed_at' => now('UTC'), 'failure_code' => 'delivery_failed', 'attempts' => $occurrence->attempts + 1]);
                        $failed++;
                    }
                }, 3);
            });

        return compact('delivered', 'failed');
    }

    private function dispatchDigest(
        ComplianceReminderOccurrence $occurrence,
        ComplianceReminderPolicy $policy,
        int &$delivered,
        int &$failed,
    ): void {
        $organization = Organization::query()->find($occurrence->organization_id);
        $user = User::query()->find($occurrence->recipient_user_id);
        $group = ComplianceReminderOccurrence::query()
            ->where('organization_id', $occurrence->organization_id)
            ->where('policy_id', $policy->id)
            ->where('recipient_user_id', $occurrence->recipient_user_id)
            ->where('channel', $occurrence->channel)
            ->where('status', 'pending')
            ->whereDate('scheduled_at', $occurrence->scheduled_at->toDateString())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $valid = collect();
        foreach ($group as $candidate) {
            $obligation = ComplianceObligation::query()->lockForUpdate()->find($candidate->obligation_id);
            if (! $obligation || ! $organization || ! $user
                || in_array($obligation->operational_status, self::TERMINAL, true)
                || ! $this->stillMatches($obligation, $policy, $candidate)
                || ! $user->belongsToOrganization($organization)
                || ! $this->authorizedForResidence($user, $organization, $obligation->residence_id)) {
                $candidate->update([
                    'status' => 'failed',
                    'failed_at' => now('UTC'),
                    'failure_code' => 'stale_or_unauthorized_scope',
                    'attempts' => $candidate->attempts + 1,
                ]);
                $failed++;
            } else {
                $valid->push($candidate);
            }
        }
        if ($valid->isEmpty() || ! $organization || ! $user) {
            return;
        }
        $preference = NotificationPreference::firstOrCreate([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);
        if (($occurrence->channel === 'mail' && (! $organization->compliance_email_enabled || ! $preference->email_enabled))
            || ($occurrence->channel === 'database' && ! $preference->database_enabled)) {
            foreach ($valid as $candidate) {
                $candidate->update([
                    'status' => 'failed',
                    'failed_at' => now('UTC'),
                    'failure_code' => 'channel_disabled',
                    'attempts' => $candidate->attempts + 1,
                ]);
                $failed++;
            }

            return;
        }
        try {
            $count = $valid->count();
            $firstObligation = ComplianceObligation::query()->findOrFail($valid->first()->obligation_id);
            $user->notify(new PortalNotification([
                'type' => 'compliance.reminder_digest',
                'organization_id' => $organization->id,
                'residence_id' => null,
                'title' => __('Synthèse des rappels de conformité'),
                'message' => trans_choice(
                    '{1} Une obligation nécessite votre attention.|[2,*] :count obligations nécessitent votre attention.',
                    $count,
                    ['count' => $count],
                ).' '.__('Ceci ne constitue pas un conseil juridique ou fiscal.'),
                'url' => route('compliance.index'),
                'first_obligation_id' => $firstObligation->id,
                'occurrence_count' => $count,
            ], [$occurrence->channel], 'compliance.reminder_digest'));
            foreach ($valid as $candidate) {
                $candidate->update([
                    'status' => 'delivered',
                    'delivered_at' => now('UTC'),
                    'attempts' => $candidate->attempts + 1,
                ]);
                $delivered++;
            }
        } catch (Throwable $exception) {
            report($exception);
            foreach ($valid as $candidate) {
                $candidate->update([
                    'status' => 'failed',
                    'failed_at' => now('UTC'),
                    'failure_code' => 'delivery_failed',
                    'attempts' => $candidate->attempts + 1,
                ]);
                $failed++;
            }
        }
    }

    public function generateEscalations(CarbonImmutable $date, int $limit = 500): array
    {
        $generated = 0;
        $skipped = 0;
        ComplianceObligation::query()->whereNotIn('operational_status', self::TERMINAL)->orderBy('id')->limit($limit)->get()
            ->each(function (ComplianceObligation $obligation) use ($date, &$generated, &$skipped) {
                DB::transaction(function () use ($obligation, $date, &$generated, &$skipped) {
                    $obligation = ComplianceObligation::query()->lockForUpdate()->find($obligation->id);
                    if (! $obligation || in_array($obligation->operational_status, self::TERMINAL, true)) {
                        return;
                    }
                    ComplianceReminderPolicy::query()->where('organization_id', $obligation->organization_id)->where('active', true)
                        ->where(fn ($q) => $q->whereNull('residence_id')->orWhere('residence_id', $obligation->residence_id))->orderBy('id')->get()
                        ->each(function (ComplianceReminderPolicy $policy) use ($obligation, $date, &$generated, &$skipped) {
                            foreach ($policy->triggers as $trigger) {
                                if (! in_array($trigger['type'], ['overdue', 'missing_assignment', 'rejection', 'correction_required'], true) || ! $this->matches($obligation, $trigger, $date)) {
                                    continue;
                                }
                                $recipientId = ComplianceObligationAssignment::where('obligation_id', $obligation->id)->where('assignment_type', 'escalation')->whereNull('ended_at')->value('user_id');
                                $key = hash('sha256', implode('|', [$obligation->id, $policy->id, $trigger['type'], $date->toDateString(), 'escalation']));
                                $created = ComplianceEscalationOccurrence::firstOrCreate(['idempotency_key' => $key], [
                                    'organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id,
                                    'obligation_id' => $obligation->id, 'policy_id' => $policy->id, 'trigger' => $trigger['type'],
                                    'recipient_user_id' => $recipientId, 'status' => $recipientId ? 'pending' : 'unassigned',
                                    'generated_at' => now('UTC'),
                                ])->wasRecentlyCreated;
                                $created ? $generated++ : $skipped++;
                            }
                        });
                }, 3);
            });

        return compact('generated', 'skipped');
    }

    private function matches(ComplianceObligation $obligation, array $trigger, CarbonImmutable $date): bool
    {
        if (in_array($trigger['type'], ['rejection', 'correction_required'], true)) {
            return $obligation->operational_status === ($trigger['type'] === 'rejection' ? 'rejected' : 'correction_required');
        }
        if ($trigger['type'] === 'missing_assignment') {
            return ! ComplianceObligationAssignment::where('obligation_id', $obligation->id)->where('assignment_type', 'responsible')->whereNull('ended_at')->exists();
        }
        if (! $obligation->current_due_on) {
            return false;
        }
        $today = $date->setTimezone($obligation->timezone)->startOfDay();
        $due = CarbonImmutable::parse($obligation->current_due_on->toDateString(), $obligation->timezone)->startOfDay();
        $days = (int) ($trigger['days'] ?? ($trigger['type'] === 'overdue' ? 1 : 0));

        return match ($trigger['type']) {
            'before_due' => $today->addDays($days)->isSameDay($due),
            'due_date' => $today->isSameDay($due),
            'overdue' => $today->subDays($days)->isSameDay($due),
            default => false,
        };
    }

    private function stillMatches(
        ComplianceObligation $obligation,
        ComplianceReminderPolicy $policy,
        ComplianceReminderOccurrence $occurrence,
    ): bool {
        $date = CarbonImmutable::parse(
            $occurrence->triggered_for_on?->toDateString() ?? $occurrence->scheduled_at,
            $obligation->timezone,
        );

        return collect($policy->triggers)->contains(
            fn (array $trigger) => ($trigger['type'] ?? null) === $occurrence->trigger
                && $this->matches($obligation, $trigger, $date)
        );
    }

    private function recipients(ComplianceObligation $obligation, ComplianceReminderPolicy $policy)
    {
        $assignments = ComplianceObligationAssignment::query()->where('obligation_id', $obligation->id)->whereNull('ended_at')
            ->whereIn('assignment_type', $policy->recipient_types)->get();
        $ids = $assignments->pluck('user_id')->filter()->unique();

        return User::query()->whereIn('id', $ids)->whereHas('organizations', function ($query) use ($obligation) {
            $query->where('organizations.id', $obligation->organization_id);
        })->get();
    }

    private function authorizedForResidence(User $user, Organization $organization, ?int $residenceId): bool
    {
        if (! $residenceId) {
            return true;
        }
        $membership = $user->organizations()->whereKey($organization->id)->first()?->pivot;

        return (bool) $membership?->all_residences || $user->residences()->whereKey($residenceId)->exists();
    }
}
