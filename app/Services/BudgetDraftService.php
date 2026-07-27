<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\FundCall;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BudgetDraftService
{
    public function create(array $data, Organization $organization, Residence $residence): Budget
    {
        return DB::transaction(function () use ($data, $organization, $residence) {
            $lines = $data['lines'];
            unset($data['lines']);
            $version = (int) Budget::query()->where('residence_id', $residence->id)->where('financial_exercise_id', $data['financial_exercise_id'])->lockForUpdate()->max('version') + 1;
            $budget = Budget::create($data + ['organization_id' => $organization->id, 'residence_id' => $residence->id, 'version' => $version]);
            foreach ($lines as $order => $line) {
                $budget->lines()->create($line + ['sort_order' => $order]);
            }

            return $budget->fresh('lines');
        });
    }

    public function prepareFundCall(Budget $budget, array $data, User $actor): FundCall
    {
        return DB::transaction(function () use ($budget, $data, $actor) {
            $sourceKey = hash('sha256', json_encode([$budget->id, $data['issue_date'], $data['due_date'], collect($data['lines'])->sortBy('budget_line_id')->values()->all()]));
            $call = FundCall::firstOrCreate(['budget_id' => $budget->id, 'budget_source_key' => $sourceKey], ['organization_id' => $budget->organization_id, 'residence_id' => $budget->residence_id, 'financial_exercise_id' => $budget->financial_exercise_id, 'title' => $data['title'], 'description' => __('Brouillon préparé depuis le budget :version', ['version' => $budget->version]), 'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'], 'status' => 'draft']);
            if ($call->lines()->exists()) {
                return $call;
            }
            foreach ($data['lines'] as $order => $row) {
                $budgetLine = $budget->lines()->whereKey($row['budget_line_id'])->with('category')->firstOrFail();
                $call->lines()->create(['charge_category_id' => $row['charge_category_id'], 'label' => $budgetLine->category->name, 'distribution_method' => $row['distribution_method'], 'allocation_key_id' => $row['allocation_key_id'] ?? null, 'target_type' => 'all', 'amount_cents' => $budgetLine->planned_cents, 'sort_order' => $order]);
            }
            $call->update(['total_cents' => $call->lines()->sum('amount_cents')]);
            activity()->performedOn($call)->causedBy($actor)->withProperties(['organization_id' => $call->organization_id, 'residence_id' => $call->residence_id, 'budget_id' => $budget->id])->log('budget.fund_call_draft_prepared');

            return $call;
        });
    }
}
