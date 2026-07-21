<?php

namespace App\Services;

use App\Models\FundCallSchedule;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class FundCallScheduleService
{
    public function generate(FundCallSchedule $schedule, CarbonImmutable $date, User $actor, bool $apply): array
    {
        if ($schedule->active === false || $date->lt($schedule->next_generation_on) || ($schedule->ends_on && $date->gt($schedule->ends_on))) {
            return ['status' => 'not_due'];
        }
        if ($schedule->generations()->whereDate('generation_date', $schedule->next_generation_on)->exists()) {
            return ['status' => 'duplicate'];
        }
        $exercise = $schedule->residence->financialExercises()->where('starts_on', '<=', $schedule->next_generation_on)->where('ends_on', '>=', $schedule->next_generation_on)->whereIn('status', ['draft', 'open'])->first();
        if (! $exercise) {
            throw ValidationException::withMessages(['schedule' => __('Aucun exercice correspondant.')]);
        }
        if (! $apply) {
            return ['status' => 'dry-run', 'schedule' => $schedule->name, 'generation_date' => $schedule->next_generation_on->toDateString()];
        }

        try {
            return DB::transaction(function () use ($schedule, $actor) {
                $schedule = FundCallSchedule::whereKey($schedule->id)->lockForUpdate()->with('residence')->firstOrFail();
                if (! $schedule->active || $schedule->generations()->whereDate('generation_date', $schedule->next_generation_on)->exists()) {
                    return ['status' => 'duplicate'];
                }
                $exercise = $schedule->residence->financialExercises()->where('starts_on', '<=', $schedule->next_generation_on)->where('ends_on', '>=', $schedule->next_generation_on)->whereIn('status', ['draft', 'open'])->lockForUpdate()->first();
                if (! $exercise) {
                    throw ValidationException::withMessages(['schedule' => __('Aucun exercice correspondant.')]);
                }
                $template = $schedule->template;
                $issue = $schedule->next_generation_on;
                $call = $schedule->residence->fundCalls()->create(['organization_id' => $schedule->organization_id, 'financial_exercise_id' => $exercise->id, 'title' => $template['title'], 'description' => $template['description'] ?? null, 'issue_date' => $issue, 'due_date' => $issue->copy()->addDays($schedule->due_offset_days)]);
                foreach ($template['lines'] as $i => $line) {
                    $call->lines()->create(collect($line)->except(['amount', 'fixed_amount'])->all() + ['amount_cents' => Money::cents($line['amount']), 'fixed_amount_cents' => isset($line['fixed_amount']) ? Money::cents($line['fixed_amount']) : null, 'sort_order' => $i]);
                }
                $schedule->generations()->create(['generation_date' => $issue, 'fund_call_id' => $call->id, 'template_snapshot' => $template]);
                $next = $this->nextDate($issue->toImmutable(), $schedule);
                $schedule->update(['next_generation_on' => $next, 'last_generated_at' => now(), 'last_failed_at' => null, 'last_error' => null, 'active' => ! $schedule->ends_on || $next->lte($schedule->ends_on)]);
                if ($schedule->auto_validate) {
                    app(FundCallWorkflow::class)->validate($call, $actor);
                }
                activity()->performedOn($schedule)->causedBy($actor)->withProperties(['organization_id' => $schedule->organization_id, 'residence_id' => $schedule->residence_id, 'fund_call_id' => $call->id])->log('fund_call_schedule.generated');

                return ['status' => 'generated', 'fund_call_id' => $call->id];
            });
        } catch (Throwable $exception) {
            FundCallSchedule::whereKey($schedule->id)->update(['last_failed_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    private function nextDate(CarbonImmutable $date, FundCallSchedule $schedule): CarbonImmutable
    {
        $months = match ($schedule->frequency) {
            'monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12,
            'custom' => (int) $schedule->custom_interval_months,
            default => 1,
        };
        $targetMonth = $date->startOfMonth()->addMonths($months);

        return $targetMonth->day(min((int) $schedule->generation_day, $targetMonth->daysInMonth));
    }
}
