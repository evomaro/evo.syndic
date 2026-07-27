<?php

namespace App\Services;

use App\Models\AccountingAutomation;
use App\Models\AccountingBook;
use App\Models\AccountingPostingRule;
use App\Models\AccountingSourceMapping;
use App\Models\ChargeCategory;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingAutomationService
{
    public function readiness(AccountingBook $book, string $effectiveDate): array
    {
        $date = CarbonImmutable::parse($effectiveDate);
        $issues = [];
        $book->loadMissing('framework');

        if ($book->framework?->status !== 'active') {
            $issues[] = 'framework_not_active';
        }
        if ($book->review_status !== 'approved') {
            $issues[] = 'book_professional_review_missing';
        }

        $exercise = FinancialExercise::query()
            ->where('accounting_book_id', $book->id)
            ->where('status', 'open')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
        if (! $exercise || ! $exercise->accounting_regime) {
            $issues[] = 'confirmed_open_exercise_missing';
        } elseif (! $exercise->accountingPeriods()->where('status', 'open')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists()) {
            $issues[] = 'open_period_missing';
        }

        $rules = AccountingPostingRule::query()->where('accounting_book_id', $book->id)->where('status', 'active')->get();
        if ($rules->isEmpty()) {
            $issues[] = 'active_rules_missing';
        }
        foreach ($rules as $rule) {
            if ($rule->professional_review_status !== 'approved') {
                $issues[] = "rule_review_missing:{$rule->stable_code}";
            }
            if ($rule->effective_from->gt($date)) {
                $issues[] = "rule_not_effective:{$rule->stable_code}";
            }
            if ((int) $rule->accounting_framework_id !== (int) $book->accounting_framework_id
                || (int) $rule->organization_id !== (int) $book->organization_id
                || (int) $rule->residence_id !== (int) $book->residence_id
                || (int) $rule->journal?->accounting_book_id !== (int) $book->id) {
                $issues[] = "rule_scope_invalid:{$rule->stable_code}";
            }
            foreach ([$rule->debit_ledger_account_id, $rule->credit_ledger_account_id] as $accountId) {
                if ($accountId && ! $book->accounts()->whereKey($accountId)->where('active', true)->where('posting_allowed', true)->exists()) {
                    $issues[] = "rule_fixed_account_invalid:{$rule->stable_code}:{$accountId}";
                }
            }
            foreach ([$rule->debit_resolution, $rule->credit_resolution] as $resolution) {
                $issues = array_merge($issues, $this->mappingIssues($book, $resolution, $date->toDateString()));
            }
        }
        $issues = array_merge($issues, $this->unpostedFinalizedSourceIssues($book, $date->toDateString()));

        return [
            'ready' => $issues === [],
            'effective_date' => $date->toDateString(),
            'rule_set_version' => hash('sha256', $rules->sortBy('id')->map->only(['stable_code', 'version', 'updated_at'])->toJson()),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    public function activate(AccountingBook $book, string $effectiveDate, User $actor): AccountingAutomation
    {
        return DB::transaction(function () use ($book, $effectiveDate, $actor) {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($book->id);
            $readiness = $this->readiness($book, $effectiveDate);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['readiness' => $readiness['issues']]);
            }

            $automation = AccountingAutomation::query()->where('accounting_book_id', $book->id)->lockForUpdate()->first();
            if ($automation?->status === 'active') {
                return $automation;
            }
            if ($automation) {
                throw ValidationException::withMessages(['activation' => __('Une activation désactivée ne peut pas être réutilisée sans une procédure de succession.')]);
            }

            $automation = AccountingAutomation::create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_book_id' => $book->id,
                'effective_from' => $effectiveDate,
                'status' => 'active',
                'rule_set_version' => $readiness['rule_set_version'],
                'readiness_result' => 'ready',
                'readiness_evidence' => $readiness,
                'professional_review_status' => 'approved',
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ]);
            $this->event($book, 'automation_activated', $actor, null, $readiness);

            return $automation;
        });
    }

    public function deactivate(AccountingBook $book, string $effectiveDate, User $actor, string $reason): AccountingAutomation
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Le motif est obligatoire.')]);
        }

        return DB::transaction(function () use ($book, $effectiveDate, $actor, $reason) {
            $automation = AccountingAutomation::query()->where('accounting_book_id', $book->id)->lockForUpdate()->firstOrFail();
            if ($automation->status !== 'active') {
                return $automation;
            }
            if (DB::table('accounting_source_postings')->where('accounting_book_id', $book->id)->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages(['activation' => __('Une automatisation ayant produit des écritures ne peut pas être désactivée par la procédure ordinaire.')]);
            }
            $automation->update([
                'status' => 'inactive',
                'deactivated_from' => $effectiveDate,
                'deactivated_by' => $actor->id,
                'deactivated_at' => now(),
                'deactivation_reason' => $reason,
            ]);
            $this->event($book, 'automation_deactivated', $actor, $reason, ['effective_date' => $effectiveDate]);

            return $automation;
        });
    }

    private function mappingIssues(AccountingBook $book, string $resolution, string $date): array
    {
        $types = match ($resolution) {
            'financial_account' => ['financial_account' => FinancialAccount::query()->where('residence_id', $book->residence_id)->where('active', true)->pluck('id')],
            'expense_category' => ['expense_category' => ExpenseCategory::query()->where('residence_id', $book->residence_id)->where('active', true)->pluck('id')],
            'charge_category' => ['charge_category' => ChargeCategory::query()->where('residence_id', $book->residence_id)->where('active', true)->pluck('id')],
            'payment_split' => [
                'receivable_control' => collect([0]),
                'advance_control' => collect([0]),
            ],
            'receivable_control', 'advance_control', 'supplier_payable' => [$resolution => collect([0])],
            'fixed_account' => [],
            default => ['unsupported_resolution' => collect([0])],
        };

        $issues = [];
        foreach ($types as $type => $ids) {
            $mapped = AccountingSourceMapping::query()
                ->where('accounting_book_id', $book->id)
                ->where('mapping_type', $type)
                ->whereIn('source_id', $ids)
                ->where('review_status', 'approved')
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->pluck('source_id');
            foreach ($ids->diff($mapped) as $id) {
                $issues[] = "mapping_missing:{$type}:{$id}";
            }
        }

        return $issues;
    }

    private function unpostedFinalizedSourceIssues(AccountingBook $book, string $effectiveDate): array
    {
        $sources = [
            ['table' => 'fund_calls', 'type' => 'fund_call', 'date' => 'issue_date', 'statuses' => ['validated']],
            ['table' => 'payments', 'type' => 'payment', 'date' => 'payment_date', 'statuses' => ['validated']],
            ['table' => 'supplier_invoices', 'type' => 'supplier_invoice', 'date' => 'invoice_date', 'statuses' => ['validated', 'partial', 'paid']],
            ['table' => 'supplier_settlements', 'type' => 'supplier_settlement', 'date' => 'settlement_date', 'statuses' => ['validated']],
            ['table' => 'supplier_credit_notes', 'type' => 'supplier_credit_note', 'date' => 'credit_date', 'statuses' => ['validated']],
        ];
        $issues = [];
        foreach ($sources as $source) {
            $exists = DB::table($source['table'].' as source')
                ->where('source.organization_id', $book->organization_id)
                ->when(
                    in_array($source['table'], ['supplier_invoices'], true),
                    fn ($query) => $query->whereExists(fn ($lines) => $lines->selectRaw('1')
                        ->from('supplier_invoice_lines')
                        ->whereColumn('supplier_invoice_lines.supplier_invoice_id', 'source.id')
                        ->where('supplier_invoice_lines.residence_id', $book->residence_id)),
                    fn ($query) => $query->where('source.residence_id', $book->residence_id),
                )
                ->whereIn('source.status', $source['statuses'])
                ->whereDate('source.'.$source['date'], '>=', $effectiveDate)
                ->whereNotExists(fn ($postings) => $postings->selectRaw('1')
                    ->from('accounting_source_postings')
                    ->where('accounting_source_postings.accounting_book_id', $book->id)
                    ->where('accounting_source_postings.source_type', $source['type'])
                    ->whereColumn('accounting_source_postings.source_id', 'source.id')
                    ->whereIn('accounting_source_postings.status', ['posted', 'reversed']))
                ->exists();
            if ($exists) {
                $issues[] = 'finalized_source_without_posting:'.$source['type'];
            }
        }

        return $issues;
    }

    private function event(AccountingBook $book, string $action, User $actor, ?string $reason, array $evidence): void
    {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $book->organization_id,
            'residence_id' => $book->residence_id,
            'record_type' => AccountingAutomation::class,
            'record_id' => $book->id,
            'action' => $action,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'after_evidence' => json_encode($evidence),
            'context' => app()->runningInConsole() ? 'command' : 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
