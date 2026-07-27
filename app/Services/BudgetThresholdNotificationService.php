<?php

namespace App\Services;

use App\Models\Budget;

class BudgetThresholdNotificationService
{
    public function __construct(private BudgetService $budgets, private ManagerNotificationService $notifications) {}

    public function dispatch(array $filters = [], bool $apply = false): array
    {
        $query = Budget::query()->whereIn('status', ['approved', 'locked'])->with(['organization', 'residence', 'exercise']);
        foreach (['organization_id' => 'organization', 'residence_id' => 'residence', 'financial_exercise_id' => 'exercise'] as $column => $key) {
            if ($filters[$key] ?? null) {
                $query->where($column, $filters[$key]);
            }
        }
        $events = 0;
        $deliveries = 0;
        foreach ($query->get() as $budget) {
            foreach ($this->budgets->metrics($budget) as $key => $metric) {
                if (! is_int($key) || (int) $metric['planned_cents'] <= 0) {
                    continue;
                }
                $actualPercent = (float) $metric['actual_cents'] * 100 / (int) $metric['planned_cents'];
                $projectedPercent = (float) $metric['projected_cents'] * 100 / (int) $metric['planned_cents'];
                foreach ([[80, '80'], [100, '100'], [100.000001, 'over']] as [$cutoff, $threshold]) {
                    $basis = $actualPercent >= $cutoff ? 'actual' : ($projectedPercent >= $cutoff ? 'projected' : null);
                    if (! $basis) {
                        continue;
                    }
                    $events++;
                    $deliveries += $this->notifications->dispatch($budget->organization, $budget->residence, 'budget_threshold', "budget:{$budget->id}:line:{$metric['budget_line_id']}:{$threshold}", [
                        'title' => 'Alerte de consommation budgétaire',
                        'message' => 'La catégorie :category a atteint le seuil :threshold (:basis).',
                        'parameters' => ['category' => $metric['category'], 'threshold' => $threshold === 'over' ? __('dépassé') : $threshold.'%', 'basis' => $basis === 'actual' ? __('réel') : __('projeté')],
                        'data' => ['budget_id' => $budget->id, 'budget_line_id' => $metric['budget_line_id'], 'threshold' => $threshold, 'basis' => $basis],
                    ], route('budgets.show', $budget), $apply);
                }
            }
        }

        return compact('events', 'deliveries');
    }
}
