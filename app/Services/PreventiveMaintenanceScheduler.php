<?php

namespace App\Services;

use App\Models\PreventiveMaintenancePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PreventiveMaintenanceScheduler
{
    public function generate(CarbonImmutable $through, bool $apply = true, ?int $residenceId = null): array
    {
        $result = ['eligible' => 0, 'created' => 0, 'skipped' => 0];
        $query = PreventiveMaintenancePlan::query()->where('active', true)->whereDate('next_intervention_on', '<=', $through->toDateString())
            ->where(fn ($q) => $q->whereNull('equipment_id')->orWhereHas('equipment', fn ($equipment) => $equipment->where('status', 'active')));
        if ($residenceId) {
            $query->where('residence_id', $residenceId);
        }
        foreach ($query->orderBy('id')->cursor() as $plan) {
            $result['eligible']++;
            if (! $apply) {
                continue;
            }
            DB::transaction(function () use ($plan, $through, &$result) {
                $plan = PreventiveMaintenancePlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
                if (! $plan->active || $plan->next_intervention_on->gt($through) || ($plan->equipment_id && $plan->equipment?->status !== 'active')) {
                    $result['skipped']++;

                    return;
                }
                $due = CarbonImmutable::parse($plan->next_intervention_on);
                $key = $plan->id.':'.$due->format('Y-m-d');
                $intervention = $plan->interventions()->firstOrCreate(['occurrence_key' => $key], [
                    'organization_id' => $plan->organization_id, 'residence_id' => $plan->residence_id, 'due_on' => $due,
                    'status' => 'due', 'schedule_snapshot' => $plan->only(['frequency_type', 'frequency_interval', 'reminder_days', 'responsible_user_id', 'supplier_id', 'supplier_contract_id']),
                    'checklist_snapshot' => $plan->checklist,
                ]);
                $created = $intervention->wasRecentlyCreated;
                $created ? $result['created']++ : $result['skipped']++;
                $plan->update(['last_generated_on' => $due, 'next_intervention_on' => $this->nextDate($due, $plan->frequency_type, $plan->frequency_interval)]);
                if ($created) {
                    app(MaintenanceNotificationService::class)->scopeEvent($plan->organization_id, $plan->residence_id, 'preventive_due', $key, '/maintenance/preventive');
                }
            }, 3);
        }

        return $result;
    }

    private function nextDate(CarbonImmutable $date, string $type, int $interval): CarbonImmutable
    {
        return match ($type) {
            'daily' => $date->addDays($interval), 'weekly' => $date->addWeeks($interval), 'quarterly' => $date->addMonthsNoOverflow(3 * $interval),
            'semiannual' => $date->addMonthsNoOverflow(6 * $interval), 'annual' => $date->addYearsNoOverflow($interval),
            'custom' => $date->addDays($interval), default => $date->addMonthsNoOverflow($interval),
        };
    }
}
