<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\AccountingClosingPackage;
use App\Models\FinancialExercise;
use Illuminate\Support\Facades\DB;

class AccountingClosingAuditService
{
    public function audit(array $filters = []): array
    {
        $violations = [];
        $packages = AccountingClosingPackage::query()
            ->with(['configuration', 'exercise', 'transitions'])
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->where('residence_id', $id))
            ->when($filters['book'] ?? null, fn ($q, $id) => $q->where('accounting_book_id', $id))
            ->when($filters['exercise'] ?? null, fn ($q, $id) => $q->where('financial_exercise_id', $id))
            ->when($filters['package'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($packages as $package) {
            if (in_array($package->state, ['approved', 'executing'], true)) {
                $snapshot = app(AccountingClosingReadinessService::class)->snapshot(
                    $package->configuration
                        ? AccountingBook::findOrFail($package->accounting_book_id)
                        : AccountingBook::findOrFail($package->accounting_book_id),
                    $package->exercise,
                );
                if (! hash_equals(
                    app(AccountingClosingReadinessService::class)->fingerprint($package->snapshot_data),
                    app(AccountingClosingReadinessService::class)->fingerprint($snapshot)
                )) {
                    $violations[] = $this->violation('approved_package_stale', $package);
                }
            }
            if ($package->closing_entry_id) {
                $entry = DB::table('journal_entries')->where('id', $package->closing_entry_id)->first();
                $totals = DB::table('journal_entry_lines')->where('journal_entry_id', $package->closing_entry_id)
                    ->selectRaw('COALESCE(SUM(debit_minor),0) debit, COALESCE(SUM(credit_minor),0) credit')->first();
                if (! $entry
                    || (int) $entry->organization_id !== (int) $package->organization_id
                    || (int) $entry->residence_id !== (int) $package->residence_id
                    || (int) $entry->accounting_book_id !== (int) $package->accounting_book_id) {
                    $violations[] = $this->violation('closing_entry_scope_mismatch', $package);
                }
                if (! $totals || (int) $totals->debit <= 0 || (int) $totals->debit !== (int) $totals->credit) {
                    $violations[] = $this->violation('unbalanced_closing_entry', $package);
                }
            }
            if ($package->approved_at && $package->configuration
                && $package->configuration->updated_at->gt($package->approved_at)) {
                $violations[] = $this->violation('configuration_modified_after_approval', $package);
            }
            if (! $this->validTransitions($package->transitions->pluck('to_state')->all())) {
                $violations[] = $this->violation('invalid_state_transition_history', $package);
            }
        }

        $closedYears = FinancialExercise::query()->where('status', 'closed')
            ->whereNotNull('accounting_book_id')
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->where('residence_id', $id))
            ->when($filters['book'] ?? null, fn ($q, $id) => $q->where('accounting_book_id', $id))
            ->when($filters['exercise'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get();
        foreach ($closedYears as $exercise) {
            if (! $packages->contains(fn ($package) => (int) $package->financial_exercise_id === (int) $exercise->id
                && in_array($package->state, ['closed', 'carry_forward_completed', 'reopened'], true))) {
                $violations[] = ['classification' => 'closed_year_without_package', 'financial_exercise_id' => $exercise->id];
            }
        }

        $periodViolations = DB::table('accounting_periods as p')
            ->join('journal_entries as e', 'e.accounting_period_id', '=', 'p.id')
            ->where('p.status', 'locked')
            ->whereNotNull('p.locked_at')
            ->whereColumn('e.posted_at', '>', 'p.locked_at')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->where('p.organization_id', $id))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->where('p.residence_id', $id))
            ->get(['p.id as period_id', 'e.id as entry_id']);
        foreach ($periodViolations as $row) {
            $violations[] = ['classification' => 'posting_after_period_close', 'period_id' => $row->period_id, 'entry_id' => $row->entry_id];
        }

        $duplicates = DB::table('accounting_closing_packages')
            ->whereNotNull('closing_entry_id')
            ->selectRaw('accounting_book_id, financial_exercise_id, COUNT(*) count')
            ->groupBy('accounting_book_id', 'financial_exercise_id')
            ->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicates as $duplicate) {
            $violations[] = [
                'classification' => 'duplicate_closing_execution',
                'accounting_book_id' => $duplicate->accounting_book_id,
                'financial_exercise_id' => $duplicate->financial_exercise_id,
                'count' => (int) $duplicate->count,
            ];
        }

        return $this->result($filters, $packages->count(), $violations);
    }

    private function validTransitions(array $states): bool
    {
        $allowed = [
            null => ['draft', 'blocked', 'ready_for_review'],
            'draft' => ['blocked', 'ready_for_review', 'superseded'],
            'blocked' => ['superseded'],
            'ready_for_review' => ['reviewed', 'blocked', 'superseded'],
            'reviewed' => ['approved', 'blocked', 'superseded'],
            'approved' => ['executing', 'closed', 'blocked'],
            'executing' => ['closed', 'blocked'],
            'closed' => ['carry_forward_completed', 'reopened'],
            'carry_forward_completed' => ['reopened'],
        ];
        $previous = null;
        foreach ($states as $state) {
            if (! in_array($state, $allowed[$previous] ?? [], true)) {
                return false;
            }
            $previous = $state;
        }

        return true;
    }

    private function violation(string $classification, AccountingClosingPackage $package): array
    {
        return ['classification' => $classification, 'package_id' => $package->id];
    }

    private function result(array $filters, int $checked, array $violations): array
    {
        return [
            'ok' => $violations === [],
            'filters' => $filters,
            'checked' => ['packages' => $checked],
            'violation_count' => count($violations),
            'classifications' => collect($violations)->countBy('classification')->sortKeys()->all(),
            'violations' => $violations,
        ];
    }
}
