<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\AccountingFramework;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AccountingRegimeAssessment;
use App\Models\FinancialExercise;
use App\Models\LedgerAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingConfigurationService
{
    private const REGIMES = ['full', 'simplified', 'minimal'];

    public function adopt(int $organizationId, int $residenceId, AccountingFramework $framework, string $regime, string $effectiveDate, User $actor): AccountingBook
    {
        if ($framework->status !== 'active') {
            throw ValidationException::withMessages(['framework' => __('Le référentiel doit être publié.')]);
        }

        return DB::transaction(function () use ($organizationId, $residenceId, $framework, $regime, $effectiveDate, $actor) {
            $book = AccountingBook::create([
                'organization_id' => $organizationId, 'residence_id' => $residenceId,
                'accounting_framework_id' => $framework->id, 'selected_regime' => $regime,
                'effective_date' => $effectiveDate, 'review_status' => 'pending_professional_review',
                'confirmed_by' => $actor->id, 'confirmed_at' => now(),
            ]);
            foreach ($framework->templates()->orderBy('sort_order')->get() as $template) {
                $book->accounts()->create([
                    'organization_id' => $organizationId, 'residence_id' => $residenceId,
                    'accounting_framework_id' => $framework->id, 'template_account_id' => $template->id,
                    'code' => $template->code, 'label_fr' => $template->label_fr, 'label_ar' => $template->label_ar,
                    'normal_balance' => $template->normal_balance, 'account_class' => $template->account_class,
                    'posting_allowed' => $template->posting_allowed, 'effective_from' => $effectiveDate,
                    'sort_order' => $template->sort_order, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
            }
            foreach ([['OD', 'Opérations diverses', 'عمليات متنوعة', 'general'], ['BQ', 'Banque', 'البنك', 'bank'], ['CA', 'Caisse', 'الصندوق', 'cash'], ['AC', 'Appels et encaissements', 'النداءات والتحصيلات', 'collections'], ['HA', 'Achats et dépenses', 'المشتريات والمصاريف', 'purchases']] as [$code, $fr, $ar, $type]) {
                $book->journals()->create([
                    'organization_id' => $organizationId, 'residence_id' => $residenceId, 'code' => $code,
                    'label_fr' => $fr, 'label_ar' => $ar, 'type' => $type, 'effective_from' => $effectiveDate,
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
            }
            DB::table('accounting_activity_events')->insert([
                'organization_id' => $organizationId, 'residence_id' => $residenceId,
                'record_type' => AccountingBook::class, 'record_id' => $book->id, 'action' => 'framework_adopted',
                'actor_id' => $actor->id, 'after_evidence' => json_encode(['framework_id' => $framework->id, 'regime' => $regime]),
                'context' => 'http', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $book;
        });
    }

    public function configureExercise(FinancialExercise $exercise, AccountingBook $book, User $actor): FinancialExercise
    {
        return DB::transaction(function () use ($exercise, $book) {
            FinancialExercise::query()->where('residence_id', $exercise->residence_id)->lockForUpdate()->get();
            if ($exercise->organization_id !== $book->organization_id || $exercise->residence_id !== $book->residence_id) {
                abort(404);
            }
            if (FinancialExercise::query()
                ->where('organization_id', $exercise->organization_id)
                ->where('residence_id', $exercise->residence_id)
                ->whereKeyNot($exercise->id)
                ->whereDate('starts_on', '<=', $exercise->ends_on)
                ->whereDate('ends_on', '>=', $exercise->starts_on)
                ->exists()) {
                throw ValidationException::withMessages(['starts_on' => __('Les exercices comptables ne peuvent pas se chevaucher.')]);
            }
            $reference = $exercise->reference ?: $exercise->starts_on->format('Y');
            if (FinancialExercise::where('residence_id', $exercise->residence_id)->whereKeyNot($exercise->id)->where('reference', $reference)->exists()) {
                throw ValidationException::withMessages(['reference' => __('Cette référence d’exercice existe déjà pour la résidence.')]);
            }
            $exercise->update([
                'reference' => $reference,
                'accounting_book_id' => $book->id, 'accounting_framework_id' => $book->accounting_framework_id,
                'accounting_regime' => $book->selected_regime,
            ]);
            if ($exercise->accountingPeriods()->exists()) {
                return $exercise->fresh();
            }
            $cursor = CarbonImmutable::parse($exercise->starts_on)->startOfMonth();
            $end = CarbonImmutable::parse($exercise->ends_on);
            $sequence = 1;
            while ($cursor->lte($end)) {
                $from = $cursor->max(CarbonImmutable::parse($exercise->starts_on));
                $to = $cursor->endOfMonth()->min($end);
                AccountingPeriod::create([
                    'organization_id' => $exercise->organization_id, 'residence_id' => $exercise->residence_id,
                    'financial_exercise_id' => $exercise->id, 'sequence' => $sequence++,
                    'label' => $from->locale('fr')->translatedFormat('F Y'), 'starts_on' => $from, 'ends_on' => $to,
                ]);
                $cursor = $cursor->addMonth();
            }

            return $exercise->fresh();
        });
    }

    public function recordRegimeAssessment(
        AccountingBook $book,
        ?FinancialExercise $exercise,
        array $data,
        User $actor,
    ): AccountingRegimeAssessment {
        if (! in_array($data['recommended_regime'] ?? null, self::REGIMES, true)) {
            throw ValidationException::withMessages(['recommended_regime' => __('Le régime explicitement proposé est invalide.')]);
        }
        if (empty($data['inputs']) || empty($data['reason_codes']) || trim((string) ($data['rule_version'] ?? '')) === '') {
            throw ValidationException::withMessages(['assessment' => __('Les faits, motifs et la version de règle explicites sont obligatoires.')]);
        }
        if ($exercise && ((int) $exercise->organization_id !== (int) $book->organization_id
            || (int) $exercise->residence_id !== (int) $book->residence_id)) {
            abort(404);
        }

        return DB::transaction(function () use ($book, $exercise, $data, $actor) {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($book->id);
            $assessment = AccountingRegimeAssessment::create([
                'accounting_book_id' => $book->id,
                'financial_exercise_id' => $exercise?->id,
                'recommended_regime' => $data['recommended_regime'],
                'inputs' => $data['inputs'],
                'reason_codes' => array_values(array_unique($data['reason_codes'])),
                'rule_version' => $data['rule_version'],
                'explanation_fr' => $data['explanation_fr'] ?? [],
                'explanation_ar' => $data['explanation_ar'] ?? [],
                'review_status' => 'pending_professional_review',
                'assessed_at' => now(),
                'assessed_by' => $actor->id,
            ]);
            if ($exercise) {
                $exercise->update(['accounting_regime_assessment_id' => $assessment->id]);
            }
            $this->event($book, 'regime_assessment_recorded', $actor, null, [
                'assessment_id' => $assessment->id,
                'financial_exercise_id' => $exercise?->id,
                'review_status' => $assessment->review_status,
            ]);

            return $assessment;
        }, 5);
    }

    public function createFrameworkSuccessor(AccountingFramework $framework, array $data, User $actor): AccountingFramework
    {
        if ($framework->status === 'draft' || $framework->superseded_by_id) {
            throw ValidationException::withMessages(['framework' => __('Seul un référentiel publié sans successeur peut être amendé.')]);
        }

        return DB::transaction(function () use ($framework, $data, $actor) {
            $framework = AccountingFramework::query()->lockForUpdate()->findOrFail($framework->id);
            if ($framework->status === 'draft' || $framework->superseded_by_id) {
                throw ValidationException::withMessages(['framework' => __('Un successeur existe déjà ou la version source est encore un brouillon.')]);
            }
            $successor = AccountingFramework::create([
                'stable_code' => $framework->stable_code,
                'version' => $data['version'],
                'name_fr' => $data['name_fr'] ?? $framework->name_fr,
                'name_ar' => $data['name_ar'] ?? $framework->name_ar,
                'description' => $data['description'] ?? $framework->description,
                'status' => 'draft',
                'official_title' => $data['official_title'] ?? $framework->official_title,
                'issuing_authority' => $data['issuing_authority'] ?? $framework->issuing_authority,
                'publication_reference' => $data['publication_reference'] ?? $framework->publication_reference,
                'publication_date' => $data['publication_date'] ?? $framework->publication_date,
                'effective_date' => $data['effective_date'] ?? $framework->effective_date,
                'source_url' => $data['source_url'] ?? $framework->source_url,
                'import_notes' => $data['import_notes'] ?? null,
                'review_status' => 'pending_professional_review',
            ]);
            foreach ($framework->templates()->orderBy('sort_order')->get() as $template) {
                $successor->templates()->create($template->only([
                    'code', 'label_fr', 'label_ar', 'normal_balance', 'account_class',
                    'posting_allowed', 'tenant_subaccounts_allowed', 'sort_order',
                ]));
            }
            DB::table('accounting_frameworks')->where('id', $framework->id)->update([
                'superseded_by_id' => $successor->id,
                'updated_at' => now(),
            ]);
            activity()->causedBy($actor)->withProperties([
                'framework_id' => $framework->id,
                'successor_id' => $successor->id,
            ])->log('accounting.framework_successor_created');

            return $successor->fresh('templates');
        }, 5);
    }

    public function createSubaccount(AccountingBook $book, array $data, User $actor): LedgerAccount
    {
        return DB::transaction(function () use ($book, $data, $actor) {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($book->id);
            $parent = $book->accounts()->lockForUpdate()->findOrFail($data['parent_id']);
            if ($parent->template_account_id) {
                $allowed = DB::table('accounting_account_templates')
                    ->where('id', $parent->template_account_id)
                    ->value('tenant_subaccounts_allowed');
                if (! $allowed) {
                    throw ValidationException::withMessages(['parent_id' => __('Ce compte officiel n’autorise pas de sous-comptes locataires.')]);
                }
            }
            $account = $book->accounts()->create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_framework_id' => $book->accounting_framework_id,
                'parent_id' => $parent->id,
                'code' => $data['code'],
                'label_fr' => $data['label_fr'],
                'label_ar' => $data['label_ar'] ?? null,
                'normal_balance' => $data['normal_balance'] ?? $parent->normal_balance,
                'account_class' => $data['account_class'] ?? $parent->account_class,
                'posting_allowed' => $data['posting_allowed'] ?? true,
                'reconciliation_required' => $data['reconciliation_required'] ?? false,
                'active' => true,
                'effective_from' => $data['effective_from'] ?? $book->effective_date,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->event($book, 'tenant_subaccount_created', $actor, null, ['ledger_account_id' => $account->id]);

            return $account;
        }, 5);
    }

    public function updateSubaccount(LedgerAccount $account, AccountingBook $book, array $data, User $actor): LedgerAccount
    {
        if ((int) $account->accounting_book_id !== (int) $book->id || $account->template_account_id) {
            abort(404);
        }
        $before = $account->only(['code', 'label_fr', 'label_ar', 'parent_id', 'active']);
        $account->update(collect($data)->only([
            'code', 'label_fr', 'label_ar', 'parent_id', 'posting_allowed',
            'reconciliation_required', 'active', 'effective_to',
        ])->all() + ['updated_by' => $actor->id]);
        $this->event($book, 'tenant_subaccount_updated', $actor, null, [
            'ledger_account_id' => $account->id, 'before' => $before,
        ]);

        return $account->fresh();
    }

    public function createJournal(AccountingBook $book, array $data, User $actor): AccountingJournal
    {
        $journal = $book->journals()->create([
            'organization_id' => $book->organization_id,
            'residence_id' => $book->residence_id,
            'code' => $data['code'],
            'label_fr' => $data['label_fr'],
            'label_ar' => $data['label_ar'] ?? null,
            'type' => $data['type'],
            'active' => true,
            'effective_from' => $data['effective_from'] ?? $book->effective_date,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->event($book, 'accounting_journal_created', $actor, null, ['accounting_journal_id' => $journal->id]);

        return $journal;
    }

    public function updateJournal(AccountingJournal $journal, AccountingBook $book, array $data, User $actor): AccountingJournal
    {
        if ((int) $journal->accounting_book_id !== (int) $book->id) {
            abort(404);
        }
        if ($journal->entries()->exists() && array_key_exists('code', $data) && $data['code'] !== $journal->code) {
            throw ValidationException::withMessages(['code' => __('Le code d’un journal utilisé est immuable.')]);
        }
        $before = $journal->only(['code', 'label_fr', 'label_ar', 'type', 'active']);
        $journal->update(collect($data)->only([
            'code', 'label_fr', 'label_ar', 'type', 'active', 'effective_to',
        ])->all() + ['updated_by' => $actor->id]);
        $this->event($book, 'accounting_journal_updated', $actor, null, [
            'accounting_journal_id' => $journal->id, 'before' => $before,
        ]);

        return $journal->fresh();
    }

    public function lock(AccountingPeriod $period, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Le motif est obligatoire.')]);
        }
        DB::transaction(function () use ($period, $actor, $reason) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status !== 'open') {
                return;
            }
            if (DB::table('journal_entries')->where('accounting_period_id', $period->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['period' => __('Des écritures brouillon restent à traiter.')]);
            }
            $period->update(['status' => 'locked', 'lock_reason' => $reason, 'locked_by' => $actor->id, 'locked_at' => now()]);
            $this->periodEvent($period, 'locked', $actor, $reason);
        }, 5);
    }

    public function reopen(AccountingPeriod $period, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Le motif est obligatoire.')]);
        }
        DB::transaction(function () use ($period, $actor, $reason) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $exercise = FinancialExercise::query()->lockForUpdate()->findOrFail($period->financial_exercise_id);
            if ($exercise->status === 'closed') {
                throw ValidationException::withMessages(['period' => __('Un exercice clôturé ne peut pas être rouvert.')]);
            }
            $period->update(['status' => 'open', 'reopen_reason' => $reason, 'reopened_by' => $actor->id, 'reopened_at' => now()]);
            $this->periodEvent($period, 'reopened', $actor, $reason);
        }, 5);
    }

    private function periodEvent(AccountingPeriod $period, string $action, User $actor, string $reason): void
    {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $period->organization_id, 'residence_id' => $period->residence_id,
            'record_type' => AccountingPeriod::class, 'record_id' => $period->id, 'action' => $action,
            'actor_id' => $actor->id, 'reason' => $reason, 'context' => app()->runningInConsole() ? 'command' : 'http',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function event(
        AccountingBook $book,
        string $action,
        User $actor,
        ?string $reason = null,
        array $evidence = [],
    ): void {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $book->organization_id,
            'residence_id' => $book->residence_id,
            'record_type' => AccountingBook::class,
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
