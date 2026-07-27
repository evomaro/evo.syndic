<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\ExpenseCommitment;
use App\Models\SupplierInvoiceLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetService
{
    public function approve(Budget $budget, User $actor): Budget
    {
        return DB::transaction(function () use ($budget, $actor) {
            $budget = Budget::query()->whereKey($budget->id)->with(['exercise', 'lines.category', 'residence.organization'])->lockForUpdate()->firstOrFail();
            if ($budget->status !== 'draft' || $budget->lines->isEmpty() || $budget->exercise->status === 'closed') {
                throw ValidationException::withMessages(['status' => __('Ce budget ne peut pas être approuvé.')]);
            }
            foreach ($budget->lines as $line) {
                if ($line->category->organization_id !== $budget->organization_id || ($line->category->residence_id && $line->category->residence_id !== $budget->residence_id)) {
                    throw ValidationException::withMessages(['lines' => __('Une catégorie budgétaire ne correspond pas à la résidence.')]);
                }
            }

            $current = Budget::query()->where('residence_id', $budget->residence_id)
                ->where('financial_exercise_id', $budget->financial_exercise_id)
                ->whereIn('status', ['approved', 'locked'])->whereKeyNot($budget->id)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($current as $previous) {
                $previous->update(['status' => 'archived', 'archived_at' => now(), 'archived_by' => $actor->id]);
            }

            $total = (int) $budget->lines->sum('planned_cents');
            $budget->update([
                'status' => 'approved',
                'total_budget_cents' => $total,
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'locked_at' => null,
                'locked_by' => null,
            ]);
            activity()->performedOn($budget)->causedBy($actor)->withProperties([
                'organization_id' => $budget->organization_id,
                'residence_id' => $budget->residence_id,
                'version' => $budget->version,
                'total_budget_cents' => $total,
            ])->log('budget.approved');

            return $budget->fresh('lines');
        });
    }

    public function lock(Budget $budget, User $actor): Budget
    {
        return DB::transaction(function () use ($budget, $actor) {
            $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            if ($budget->status !== 'approved') {
                throw ValidationException::withMessages(['status' => __('Seul un budget approuvé peut être verrouillé.')]);
            }
            $budget->update(['status' => 'locked', 'locked_at' => now(), 'locked_by' => $actor->id]);
            activity()->performedOn($budget)->causedBy($actor)->withProperties($this->auditScope($budget))->log('budget.locked');

            return $budget;
        });
    }

    public function unlock(Budget $budget, User $actor, string $reason): Budget
    {
        return DB::transaction(function () use ($budget, $actor, $reason) {
            $budget = Budget::query()->whereKey($budget->id)->with('residence.organization')->lockForUpdate()->firstOrFail();
            if ($budget->status !== 'locked' || ! $actor->canInOrganization('manage_organization', $budget->residence->organization)) {
                throw ValidationException::withMessages(['status' => __('Le déverrouillage exige une autorisation élevée.')]);
            }
            if (mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['reason' => __('Le motif de déverrouillage doit comporter au moins 10 caractères.')]);
            }
            $budget->update(['status' => 'approved', 'unlocked_at' => now(), 'unlocked_by' => $actor->id, 'unlock_reason' => trim($reason)]);
            activity()->performedOn($budget)->causedBy($actor)->withProperties($this->auditScope($budget) + ['reason' => trim($reason)])->log('budget.unlocked');

            return $budget;
        });
    }

    public function archive(Budget $budget, User $actor): Budget
    {
        return DB::transaction(function () use ($budget, $actor) {
            $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            if (! in_array($budget->status, ['approved', 'locked'], true)) {
                throw ValidationException::withMessages(['status' => __('Seul un budget actif peut être archivé.')]);
            }
            $budget->update(['status' => 'archived', 'archived_at' => now(), 'archived_by' => $actor->id]);
            activity()->performedOn($budget)->causedBy($actor)->withProperties($this->auditScope($budget))->log('budget.archived');

            return $budget;
        });
    }

    public function revise(Budget $budget, User $actor, string $reason): Budget
    {
        return DB::transaction(function () use ($budget, $actor, $reason) {
            $budget = Budget::query()->whereKey($budget->id)->with('lines')->lockForUpdate()->firstOrFail();
            if ($budget->status !== 'approved') {
                throw ValidationException::withMessages(['status' => __('Seul un budget approuvé et non verrouillé peut être révisé.')]);
            }
            if (mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['reason' => __('Le motif de révision doit comporter au moins 10 caractères.')]);
            }
            $version = (int) Budget::query()->where('residence_id', $budget->residence_id)
                ->where('financial_exercise_id', $budget->financial_exercise_id)->lockForUpdate()->max('version') + 1;
            $copy = Budget::create([
                'organization_id' => $budget->organization_id,
                'residence_id' => $budget->residence_id,
                'financial_exercise_id' => $budget->financial_exercise_id,
                'version' => $version,
                'status' => 'draft',
                'title' => $budget->title.' — v'.$version,
                'notes' => $budget->notes,
                'revision_reason' => trim($reason),
                'supersedes_id' => $budget->id,
            ]);
            foreach ($budget->lines as $line) {
                $copy->lines()->create($line->only(['expense_category_id', 'planned_cents', 'description', 'sort_order', 'notes']));
            }
            activity()->performedOn($copy)->causedBy($actor)->withProperties($this->auditScope($copy) + ['supersedes_id' => $budget->id, 'reason' => trim($reason)])->log('budget.revised');

            return $copy->fresh('lines');
        });
    }

    public function metrics(Budget $budget): array
    {
        $budget->load('lines.category');
        $categoryIds = $budget->lines->pluck('expense_category_id');

        $grossActuals = SupplierInvoiceLine::query()
            ->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_lines.supplier_invoice_id')
            ->where('supplier_invoice_lines.residence_id', $budget->residence_id)
            ->where('supplier_invoice_lines.financial_exercise_id', $budget->financial_exercise_id)
            ->whereIn('supplier_invoice_lines.expense_category_id', $categoryIds)
            ->whereIn('supplier_invoices.status', ['validated', 'partial', 'paid'])
            ->groupBy('supplier_invoice_lines.expense_category_id')
            ->selectRaw('supplier_invoice_lines.expense_category_id, SUM(supplier_invoice_lines.total_cents) amount')
            ->pluck('amount', 'supplier_invoice_lines.expense_category_id');
        $credits = DB::table('supplier_credit_note_allocations as ca')
            ->join('supplier_credit_notes as cn', 'cn.id', '=', 'ca.supplier_credit_note_id')
            ->join('supplier_invoice_lines as il', 'il.id', '=', 'ca.supplier_invoice_line_id')
            ->where('il.residence_id', $budget->residence_id)->where('il.financial_exercise_id', $budget->financial_exercise_id)
            ->whereIn('il.expense_category_id', $categoryIds)->where('cn.status', 'validated')->whereNull('ca.reversed_at')
            ->groupBy('il.expense_category_id')->selectRaw('il.expense_category_id, SUM(ca.amount_cents) amount')
            ->pluck('amount', 'il.expense_category_id');
        $paid = DB::table('supplier_settlement_allocations as sa')
            ->join('supplier_settlements as ss', 'ss.id', '=', 'sa.supplier_settlement_id')
            ->join('supplier_invoice_lines as il', 'il.id', '=', 'sa.supplier_invoice_line_id')
            ->where('il.residence_id', $budget->residence_id)->where('il.financial_exercise_id', $budget->financial_exercise_id)
            ->whereIn('il.expense_category_id', $categoryIds)->where('ss.status', 'validated')->whereNull('sa.reversed_at')
            ->groupBy('il.expense_category_id')->selectRaw('il.expense_category_id, SUM(sa.amount_cents) amount')
            ->pluck('amount', 'il.expense_category_id');

        $commitments = ExpenseCommitment::query()->where('residence_id', $budget->residence_id)
            ->where('financial_exercise_id', $budget->financial_exercise_id)
            ->whereIn('status', ['approved', 'partially_invoiced', 'fully_invoiced'])
            ->withSum(['invoices as validated_invoice_cents' => fn ($query) => $query->whereIn('status', ['validated', 'partial', 'paid'])], 'total_cents')
            ->get()->groupBy('expense_category_id')->map(fn ($rows) => $rows->sum(fn ($commitment) => max(0, (int) $commitment->amount_cents - (int) $commitment->validated_invoice_cents)));

        $rows = $budget->lines->map(function ($line) use ($grossActuals, $credits, $paid, $commitments) {
            $actual = max(0, (int) ($grossActuals[$line->expense_category_id] ?? 0) - (int) ($credits[$line->expense_category_id] ?? 0));
            $committed = (int) ($commitments[$line->expense_category_id] ?? 0);
            $paidCents = (int) ($paid[$line->expense_category_id] ?? 0);
            $projected = $actual + $committed;
            $available = (int) $line->planned_cents - $projected;

            return [
                'budget_line_id' => $line->id,
                'category' => $line->category->name,
                'planned_cents' => (int) $line->planned_cents,
                'committed_remaining_cents' => $committed,
                'actual_cents' => $actual,
                'paid_cents' => $paidCents,
                'projected_cents' => $projected,
                'available_cents' => $available,
                'variance_cents' => $available,
                'cash_remaining_cents' => (int) $line->planned_cents - $paidCents,
                'variance_percent' => $line->planned_cents > 0 ? round($available * 100 / $line->planned_cents, 1) : null,
                'consumption_percent' => $line->planned_cents > 0 ? round($projected * 100 / $line->planned_cents, 1) : null,
                'overspent' => $available < 0,
            ];
        });

        // Preserve the original numeric row collection consumed by Phase 03 UI
        // while adding reconciled totals as named metadata.
        $result = $rows->values()->all();
        $result['totals'] = collect(['planned_cents', 'committed_remaining_cents', 'actual_cents', 'paid_cents', 'projected_cents', 'available_cents', 'cash_remaining_cents'])
            ->mapWithKeys(fn ($key) => [$key => (int) $rows->sum($key)])->all();
        $result['overspent'] = $rows->contains('overspent', true);

        return $result;
    }

    private function auditScope(Budget $budget): array
    {
        return ['organization_id' => $budget->organization_id, 'residence_id' => $budget->residence_id, 'version' => $budget->version];
    }
}
