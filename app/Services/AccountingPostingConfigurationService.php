<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\AccountingPostingRule;
use App\Models\AccountingSourceMapping;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPostingConfigurationService
{
    public const RESOLUTIONS = [
        'fixed_account',
        'financial_account',
        'expense_category',
        'charge_category',
        'payment_split',
        'receivable_control',
        'advance_control',
        'supplier_payable',
    ];

    public function activateRule(AccountingPostingRule $rule, User $actor): AccountingPostingRule
    {
        return DB::transaction(function () use ($rule, $actor) {
            $rule = AccountingPostingRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->status === 'active') {
                return $rule;
            }
            if ($rule->status !== 'draft' || $rule->professional_review_status !== 'approved') {
                throw ValidationException::withMessages(['rule' => __('La règle doit être un brouillon approuvé par un professionnel.')]);
            }
            if (! in_array($rule->debit_resolution, self::RESOLUTIONS, true) || ! in_array($rule->credit_resolution, self::RESOLUTIONS, true)) {
                throw ValidationException::withMessages(['rule' => __('Le mode de résolution comptable est invalide.')]);
            }
            $this->assertRuleScope($rule);
            AccountingPostingRule::query()
                ->where('accounting_book_id', $rule->accounting_book_id)
                ->where('source_domain', $rule->source_domain)
                ->where('source_event', $rule->source_event)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists()
                && throw ValidationException::withMessages(['rule' => __('Une règle active existe déjà pour cet événement.')]);

            $rule->update([
                'status' => 'active',
                'reviewed_by' => $rule->reviewed_by ?: $actor->id,
                'reviewed_at' => $rule->reviewed_at ?: now(),
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ]);
            $this->event($rule->fresh(), 'posting_rule_activated', $actor);

            return $rule->fresh();
        });
    }

    public function supersede(AccountingPostingRule $current, AccountingPostingRule $successor, User $actor): AccountingPostingRule
    {
        return DB::transaction(function () use ($current, $successor, $actor) {
            $current = AccountingPostingRule::query()->lockForUpdate()->findOrFail($current->id);
            $successor = AccountingPostingRule::query()->lockForUpdate()->findOrFail($successor->id);
            if ($current->status !== 'active' || $successor->status !== 'draft'
                || $current->stable_code !== $successor->stable_code
                || $current->accounting_book_id !== $successor->accounting_book_id
                || ! $successor->effective_from->gt($current->effective_from)) {
                throw ValidationException::withMessages(['rule' => __('La succession de règle est invalide.')]);
            }
            DB::table('accounting_posting_rules')->where('id', $current->id)->update([
                'status' => 'superseded',
                'effective_to' => $successor->effective_from->subDay(),
                'superseded_by_id' => $successor->id,
                'superseded_by_actor' => $actor->id,
                'superseded_at' => now(),
                'updated_at' => now(),
            ]);
            $this->activateRule($successor, $actor);

            return $successor->fresh();
        });
    }

    public function map(AccountingBook $book, string $type, int $sourceId, LedgerAccount $account, string $effectiveFrom, User $actor, string $reviewStatus = 'pending_professional_review'): AccountingSourceMapping
    {
        if ((int) $account->accounting_book_id !== (int) $book->id
            || (int) $account->organization_id !== (int) $book->organization_id
            || (int) $account->residence_id !== (int) $book->residence_id
            || ! $account->active
            || ! $account->posting_allowed) {
            throw ValidationException::withMessages(['ledger_account_id' => __('Le compte doit être actif, mouvementable et appartenir à la même comptabilité.')]);
        }

        return DB::transaction(function () use ($book, $type, $sourceId, $account, $effectiveFrom, $actor, $reviewStatus) {
            $existing = AccountingSourceMapping::query()
                ->where('accounting_book_id', $book->id)
                ->where('mapping_type', $type)
                ->where('source_id', $sourceId)
                ->whereDate('effective_from', $effectiveFrom)
                ->lockForUpdate()
                ->first();
            if ($existing?->review_status === 'approved') {
                throw ValidationException::withMessages(['mapping' => __('Une correspondance approuvée est immuable; créez une version à une date ultérieure.')]);
            }

            $latestApproved = AccountingSourceMapping::query()
                ->where('accounting_book_id', $book->id)
                ->where('mapping_type', $type)
                ->where('source_id', $sourceId)
                ->where('review_status', 'approved')
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();
            if ($latestApproved && $latestApproved->effective_from->gte($effectiveFrom)) {
                throw ValidationException::withMessages(['effective_from' => __('La correction doit prendre effet après la version approuvée actuelle.')]);
            }

            $values = [
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'ledger_account_id' => $account->id,
                'effective_from' => $effectiveFrom,
                'review_status' => $reviewStatus,
                'created_by' => $actor->id,
                'reviewed_by' => $reviewStatus === 'approved' ? $actor->id : null,
                'reviewed_at' => $reviewStatus === 'approved' ? now() : null,
            ];

            return $existing
                ? tap($existing)->update($values)
                : AccountingSourceMapping::create($values + [
                    'accounting_book_id' => $book->id,
                    'mapping_type' => $type,
                    'source_id' => $sourceId,
                ]);
        });
    }

    public function reviewMapping(AccountingSourceMapping $mapping, User $actor): AccountingSourceMapping
    {
        return DB::transaction(function () use ($mapping, $actor) {
            $mapping = AccountingSourceMapping::query()->lockForUpdate()->findOrFail($mapping->id);
            if ($mapping->review_status === 'approved') {
                return $mapping;
            }
            $predecessor = AccountingSourceMapping::query()
                ->where('accounting_book_id', $mapping->accounting_book_id)
                ->where('mapping_type', $mapping->mapping_type)
                ->where('source_id', $mapping->source_id)
                ->where('review_status', 'approved')
                ->whereDate('effective_from', '<', $mapping->effective_from)
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();

            $mapping->update([
                'review_status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            if ($predecessor) {
                DB::table('accounting_source_mappings')->where('id', $predecessor->id)->update([
                    'effective_to' => $mapping->effective_from->copy()->subDay(),
                    'superseded_by_id' => $mapping->id,
                    'updated_at' => now(),
                ]);
            }

            return $mapping->fresh();
        });
    }

    private function assertRuleScope(AccountingPostingRule $rule): void
    {
        $book = AccountingBook::findOrFail($rule->accounting_book_id);
        if ((int) $rule->organization_id !== (int) $book->organization_id
            || (int) $rule->residence_id !== (int) $book->residence_id
            || (int) $rule->accounting_framework_id !== (int) $book->accounting_framework_id
            || (int) $rule->journal()->value('accounting_book_id') !== (int) $book->id) {
            throw ValidationException::withMessages(['rule' => __('La règle, le journal et le référentiel doivent appartenir à la même comptabilité.')]);
        }
        foreach ([$rule->debit_ledger_account_id, $rule->credit_ledger_account_id] as $accountId) {
            if ($accountId && ! LedgerAccount::query()->whereKey($accountId)->where('accounting_book_id', $book->id)->where('active', true)->where('posting_allowed', true)->exists()) {
                throw ValidationException::withMessages(['rule' => __('Le compte fixe de la règle est invalide.')]);
            }
        }
    }

    private function event(AccountingPostingRule $rule, string $action, User $actor): void
    {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $rule->organization_id,
            'residence_id' => $rule->residence_id,
            'record_type' => AccountingPostingRule::class,
            'record_id' => $rule->id,
            'action' => $action,
            'actor_id' => $actor->id,
            'after_evidence' => json_encode(['stable_code' => $rule->stable_code, 'version' => $rule->version]),
            'context' => app()->runningInConsole() ? 'command' : 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
