<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\AccountingClosingPackage;
use App\Models\AccountingClosingPeriodSnapshot;
use App\Models\AccountingClosingTransition;
use App\Models\AccountingOpeningBatch;
use App\Models\AccountingPeriod;
use App\Models\FinancialExercise;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingClosingWorkflowService
{
    public function __construct(
        private readonly AccountingClosingReadinessService $readiness,
        private readonly AccountingReportService $reports,
        private readonly AccountingPostingService $posting,
        private readonly OpeningBalanceService $openingBalances,
    ) {}

    public function prepare(AccountingBook $book, FinancialExercise $exercise, User $actor): AccountingClosingPackage
    {
        return DB::transaction(function () use ($book, $exercise, $actor) {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($book->id);
            $exercise = FinancialExercise::query()->lockForUpdate()->findOrFail($exercise->id);
            $this->scope($book, $exercise);
            $evaluation = $this->readiness->evaluate($book, $exercise);
            $latest = AccountingClosingPackage::query()
                ->where('accounting_book_id', $book->id)
                ->where('financial_exercise_id', $exercise->id)
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->first();

            if ($latest
                && ! in_array($latest->state, ['closed', 'carry_forward_completed', 'reopened', 'superseded'], true)
                && hash_equals($latest->integrity_fingerprint, $this->packageFingerprint($evaluation))) {
                return $latest->fresh(['configuration', 'periodSnapshots', 'transitions']);
            }
            if ($latest && ! in_array($latest->state, ['closed', 'carry_forward_completed', 'reopened', 'superseded'], true)) {
                $from = $latest->state;
                $latest->update([
                    'state' => 'superseded',
                    'stale_at' => now(),
                    'stale_reason_code' => 'snapshot_changed',
                ]);
                $this->transition($latest, $from, 'superseded', 'invalidate', $actor, null, [
                    'current_snapshot_fingerprint' => $evaluation['snapshot_fingerprint'],
                ]);
            }

            $package = AccountingClosingPackage::create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_book_id' => $book->id,
                'financial_exercise_id' => $exercise->id,
                'accounting_closing_configuration_id' => $evaluation['configuration_id'],
                'supersedes_id' => $latest?->id,
                'generation' => ($latest?->generation ?? 0) + 1,
                'state' => $evaluation['technical_ready'] ? 'ready_for_review' : 'blocked',
                'currency' => 'MAD',
                'snapshot_entry_id' => $evaluation['snapshot']['snapshot_entry_id'],
                'snapshot_data' => $evaluation['snapshot'],
                'readiness_results' => $evaluation,
                'trial_balance_totals' => $evaluation['trial_balance_totals'],
                'integrity_fingerprint' => $this->packageFingerprint($evaluation),
                'prepared_by' => $actor->id,
                'prepared_at' => now(),
            ]);
            $this->transition($package, null, $package->state, 'prepare', $actor, null, [
                'snapshot_entry_id' => $package->snapshot_entry_id,
                'technical_ready' => $evaluation['technical_ready'],
            ]);
            $this->activity($package, 'closing_package_prepared', $actor, null, [
                'state' => $package->state,
                'generation' => $package->generation,
                'snapshot_entry_id' => $package->snapshot_entry_id,
            ]);

            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }, 5);
    }

    public function review(AccountingClosingPackage $package, User $actor): AccountingClosingPackage
    {
        if ($package->fresh()->state === 'reviewed') {
            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }
        $this->invalidateIfStale($package, $actor);

        return DB::transaction(function () use ($package, $actor) {
            $package = AccountingClosingPackage::query()->lockForUpdate()->findOrFail($package->id);
            if ($package->state === 'reviewed') {
                return $package;
            }
            if ($package->state !== 'ready_for_review') {
                throw ValidationException::withMessages(['state' => __('Le dossier n’est pas prêt pour la revue.')]);
            }
            if ((int) $package->prepared_by === (int) $actor->id) {
                throw ValidationException::withMessages(['actor' => __('Le préparateur ne peut pas valider sa propre revue.')]);
            }
            $evaluation = $this->freshEvaluation($package);
            $this->assertFresh($package, $evaluation);
            if (! $evaluation['technical_ready']) {
                throw ValidationException::withMessages(['readiness' => __('Les contrôles techniques doivent être corrigés avant la revue.')]);
            }
            $package->update(['state' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $this->transition($package, 'ready_for_review', 'reviewed', 'review', $actor);
            $this->activity($package, 'closing_package_reviewed', $actor);

            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }, 5);
    }

    public function approve(AccountingClosingPackage $package, User $actor): AccountingClosingPackage
    {
        if ($package->fresh()->state === 'approved') {
            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }
        $this->invalidateIfStale($package, $actor);

        return DB::transaction(function () use ($package, $actor) {
            $package = AccountingClosingPackage::query()->lockForUpdate()->findOrFail($package->id);
            if ($package->state === 'approved') {
                return $package;
            }
            if ($package->state !== 'reviewed') {
                throw ValidationException::withMessages(['state' => __('Le dossier doit être revu avant approbation.')]);
            }
            if ((int) $package->prepared_by === (int) $actor->id || (int) $package->reviewed_by === (int) $actor->id) {
                throw ValidationException::withMessages(['actor' => __('L’approbateur doit être distinct du préparateur et du réviseur.')]);
            }
            $evaluation = $this->freshEvaluation($package);
            $this->assertFresh($package, $evaluation);
            if (! $evaluation['approval_ready']) {
                throw ValidationException::withMessages(['readiness' => __('Les décisions professionnelles requises ne sont pas approuvées.')]);
            }
            $package->update(['state' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->transition($package, 'reviewed', 'approved', 'approve', $actor);
            $this->activity($package, 'closing_package_approved', $actor);

            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }, 5);
    }

    public function closePeriod(
        AccountingClosingPackage $package,
        AccountingPeriod $period,
        User $actor,
        string $reason,
    ): AccountingPeriod {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Un motif est obligatoire.')]);
        }

        return DB::transaction(function () use ($package, $period, $actor, $reason) {
            $package = AccountingClosingPackage::query()->lockForUpdate()->findOrFail($package->id);
            $exercise = FinancialExercise::query()->lockForUpdate()->findOrFail($package->financial_exercise_id);
            $periods = AccountingPeriod::query()
                ->where('financial_exercise_id', $exercise->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();
            $period = $periods->firstWhere('id', $period->id) ?? throw ValidationException::withMessages([
                'period' => __('La période n’appartient pas à ce dossier de clôture.'),
            ]);
            if ($period->status === 'locked') {
                return $period;
            }
            if ($period->status !== 'open') {
                throw ValidationException::withMessages(['period' => __('La période ne peut pas être clôturée.')]);
            }
            if ($periods->where('sequence', '<', $period->sequence)->contains(fn ($item) => $item->status !== 'locked')) {
                throw ValidationException::withMessages(['period' => __('Les périodes antérieures doivent être clôturées en premier.')]);
            }
            if (DB::table('journal_entries')->where('accounting_period_id', $period->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['period' => __('Des écritures brouillon restent dans cette période.')]);
            }
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($package->accounting_book_id);
            $snapshot = $this->readiness->snapshot($book, $exercise);
            $fingerprint = $this->readiness->fingerprint($snapshot);
            AccountingClosingPeriodSnapshot::firstOrCreate(
                ['accounting_closing_package_id' => $package->id, 'accounting_period_id' => $period->id],
                [
                    'status_before' => $period->status,
                    'snapshot_entry_id' => $snapshot['snapshot_entry_id'],
                    'snapshot_fingerprint' => $fingerprint,
                    'readiness_results' => [
                        'draft_entries' => 0,
                        'earlier_periods_locked' => true,
                        'evaluated_at' => now()->toIso8601String(),
                    ],
                    'closed_by' => $actor->id,
                    'closed_at' => now(),
                ]
            );
            $period->update([
                'status' => 'locked',
                'lock_reason' => $reason,
                'locked_by' => $actor->id,
                'locked_at' => now(),
            ]);
            $this->activity($package, 'accounting_period_closed', $actor, $reason, [
                'accounting_period_id' => $period->id,
                'snapshot_entry_id' => $snapshot['snapshot_entry_id'],
            ]);

            return $period->fresh();
        }, 5);
    }

    public function executeClosing(AccountingClosingPackage $package, User $actor): AccountingClosingPackage
    {
        if (in_array($package->fresh()->state, ['closed', 'carry_forward_pending', 'carry_forward_completed'], true)) {
            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }
        $this->invalidateIfStale($package, $actor);

        return DB::transaction(function () use ($package, $actor) {
            $package = AccountingClosingPackage::query()->lockForUpdate()->findOrFail($package->id);
            if (in_array($package->state, ['closed', 'carry_forward_pending', 'carry_forward_completed'], true)) {
                return $package;
            }
            if ($package->state !== 'approved') {
                throw ValidationException::withMessages(['state' => __('Le dossier doit être approuvé avant exécution.')]);
            }
            if ((int) $package->approved_by === (int) $actor->id) {
                throw ValidationException::withMessages(['actor' => __('L’exécutant doit être distinct de l’approbateur.')]);
            }
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($package->accounting_book_id);
            $exercise = FinancialExercise::query()->lockForUpdate()->findOrFail($package->financial_exercise_id);
            $periods = AccountingPeriod::query()->where('financial_exercise_id', $exercise->id)
                ->orderBy('sequence')->lockForUpdate()->get();
            $finalPeriod = $periods->last();
            if (! $finalPeriod || $finalPeriod->status !== 'open'
                || $periods->slice(0, -1)->contains(fn ($period) => $period->status !== 'locked')) {
                throw ValidationException::withMessages(['periods' => __('Toutes les périodes antérieures doivent être clôturées et la dernière période doit rester ouverte pour l’écriture de clôture.')]);
            }
            $evaluation = $this->readiness->evaluate($book, $exercise, $package);
            $this->assertFresh($package, $evaluation);
            if (! $evaluation['execution_ready']) {
                throw ValidationException::withMessages(['readiness' => __('La clôture reste bloquée par des contrôles techniques ou professionnels.')]);
            }
            $configuration = $package->configuration()->with(['classifications.account', 'closingJournal'])->lockForUpdate()->firstOrFail();
            if (! $configuration->closingJournal || $configuration->closingJournal->type !== 'closing') {
                throw ValidationException::withMessages(['configuration' => __('Un journal de clôture dédié et approuvé est requis.')]);
            }

            $lines = $this->closingLines($book, $exercise, $package);
            if (count($lines) < 2) {
                throw ValidationException::withMessages(['closing' => __('Aucun mouvement de clôture déterministe ne peut être généré.')]);
            }
            $entry = JournalEntry::firstOrCreate(
                ['accounting_book_id' => $book->id, 'posting_key' => 'closing-package:'.$package->id],
                [
                    'organization_id' => $book->organization_id,
                    'residence_id' => $book->residence_id,
                    'financial_exercise_id' => $exercise->id,
                    'accounting_period_id' => $finalPeriod->id,
                    'accounting_journal_id' => $configuration->closing_journal_id,
                    'entry_date' => $exercise->ends_on,
                    'reference' => 'CLOSE-'.$exercise->reference,
                    'description_fr' => 'Clôture contrôlée de l’exercice '.$exercise->reference,
                    'description_ar' => 'إقفال مراقب للسنة المالية '.$exercise->reference,
                    'status' => 'draft',
                    'source_type' => 'accounting_closing_package',
                    'source_id' => (string) $package->id,
                    'metadata' => [
                        'closing_configuration_id' => $configuration->id,
                        'snapshot_entry_id' => $package->snapshot_entry_id,
                    ],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]
            );
            if ($entry->status === 'draft' && ! $entry->lines()->exists()) {
                foreach ($lines as $sequence => $line) {
                    $entry->lines()->create($line + ['sequence' => $sequence + 1]);
                }
            }
            $entry = $this->posting->post($entry, $actor);
            $finalPeriod->update([
                'status' => 'locked',
                'lock_reason' => 'Clôture annuelle package #'.$package->id,
                'locked_by' => $actor->id,
                'locked_at' => now(),
            ]);
            $exercise->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'locked_at' => now(),
                'locked_by' => $actor->id,
            ]);
            $package->update([
                'state' => 'closed',
                'executed_by' => $actor->id,
                'executed_at' => now(),
                'closing_entry_id' => $entry->id,
            ]);
            $this->transition($package, 'approved', 'closed', 'execute_closing', $actor, null, [
                'closing_entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
            ]);
            $this->activity($package, 'fiscal_year_closing_executed', $actor, null, [
                'closing_entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
            ]);

            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }, 5);
    }

    public function executeCarryForward(AccountingClosingPackage $package, User $actor): AccountingClosingPackage
    {
        return DB::transaction(function () use ($package, $actor) {
            $package = AccountingClosingPackage::query()->lockForUpdate()->findOrFail($package->id);
            if ($package->state === 'carry_forward_completed') {
                return $package;
            }
            if ($package->state !== 'closed' || ! $package->closing_entry_id) {
                throw ValidationException::withMessages(['state' => __('Le report à nouveau exige une clôture exécutée.')]);
            }
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($package->accounting_book_id);
            $exercise = FinancialExercise::query()->lockForUpdate()->findOrFail($package->financial_exercise_id);
            $next = FinancialExercise::query()
                ->where('accounting_book_id', $book->id)
                ->whereDate('starts_on', $exercise->ends_on->copy()->addDay())
                ->lockForUpdate()
                ->first();
            if (! $next || $next->status !== 'open') {
                throw ValidationException::withMessages(['next_exercise' => __('L’exercice suivant ouvert et contigu est requis.')]);
            }
            if (AccountingOpeningBatch::where('accounting_book_id', $book->id)->where('financial_exercise_id', $next->id)->exists()) {
                throw ValidationException::withMessages(['opening' => __('Un solde d’ouverture existe déjà pour l’exercice suivant.')]);
            }
            $configuration = $package->configuration()->with(['classifications.account', 'openingJournal'])->firstOrFail();
            if (! $configuration->openingJournal || $configuration->openingJournal->type !== 'opening') {
                throw ValidationException::withMessages(['configuration' => __('Un journal d’ouverture dédié et approuvé est requis.')]);
            }
            if ($configuration->classifications->where('review_status', 'approved')
                ->filter(fn ($item) => $item->carry_forward_eligible
                    && ($item->requires_third_party_dimensions || $item->requires_analytical_dimensions))->isNotEmpty()) {
                throw ValidationException::withMessages(['dimensions' => __('Les dimensions requises ne permettent pas un report à nouveau déterministe.')]);
            }
            $ledger = $this->reports->generate($book, $exercise, [
                'report' => 'general-ledger',
                'date_from' => $exercise->starts_on->toDateString(),
                'date_to' => $exercise->ends_on->toDateString(),
            ]);
            $eligible = $configuration->classifications->where('review_status', 'approved')
                ->where('carry_forward_eligible', true)
                ->keyBy('ledger_account_id');
            $lines = collect($ledger['rows'])->filter(fn ($row) => $eligible->has($row['account_id'])
                && ($row['closing_debit_minor'] || $row['closing_credit_minor']))
                ->values()
                ->map(fn ($row, $index) => [
                    'sequence' => $index + 1,
                    'ledger_account_id' => $row['account_id'],
                    'label' => 'Report à nouveau '.$row['code'],
                    'debit_minor' => $row['closing_debit_minor'],
                    'credit_minor' => $row['closing_credit_minor'],
                ]);
            if ($lines->count() < 2 || (int) $lines->sum('debit_minor') !== (int) $lines->sum('credit_minor')) {
                throw ValidationException::withMessages(['carry_forward' => __(
                    'Le report à nouveau déterministe n’est pas équilibré. Lignes: :count, débit: :debit, crédit: :credit.',
                    [
                        'count' => $lines->count(),
                        'debit' => (int) $lines->sum('debit_minor'),
                        'credit' => (int) $lines->sum('credit_minor'),
                    ]
                )]);
            }
            $batch = AccountingOpeningBatch::create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_book_id' => $book->id,
                'financial_exercise_id' => $next->id,
                'accounting_journal_id' => $configuration->opening_journal_id,
                'opening_date' => $next->starts_on,
                'reference' => 'CF-'.$exercise->reference,
                'notes' => 'Report à nouveau contrôlé du package #'.$package->id,
                'status' => 'draft',
                'origin_type' => 'carry_forward',
                'closing_package_id' => $package->id,
                'created_by' => $actor->id,
            ]);
            foreach ($lines as $line) {
                $batch->lines()->create($line);
            }
            $batch = $this->openingBalances->review($batch, $actor);
            $batch = $this->openingBalances->post($batch, $actor);
            $package->update(['state' => 'carry_forward_completed', 'carry_forward_batch_id' => $batch->id]);
            $this->transition($package, 'closed', 'carry_forward_completed', 'execute_carry_forward', $actor, null, [
                'opening_batch_id' => $batch->id,
                'journal_entry_id' => $batch->journal_entry_id,
            ]);
            $this->activity($package, 'carry_forward_executed', $actor, null, [
                'opening_batch_id' => $batch->id,
                'journal_entry_id' => $batch->journal_entry_id,
            ]);

            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }, 5);
    }

    public function reopeningDiagnostics(AccountingClosingPackage $package): array
    {
        $package->refresh();
        $laterEntries = DB::table('journal_entries')
            ->where('accounting_book_id', $package->accounting_book_id)
            ->where('financial_exercise_id', '!=', $package->financial_exercise_id)
            ->whereIn('status', ['posted', 'reversed'])
            ->where('id', '>', $package->snapshot_entry_id)
            ->count();
        $anotherOpenExercise = FinancialExercise::query()
            ->where('accounting_book_id', $package->accounting_book_id)
            ->whereKeyNot($package->financial_exercise_id)
            ->where('status', 'open')
            ->exists();
        $closingEntryIsReversible = $package->closing_entry_id
            && JournalEntry::query()->whereKey($package->closing_entry_id)
                ->where('status', 'posted')->whereNull('reversed_by_id')->exists();
        $issues = array_values(array_filter([
            $package->state !== 'closed' ? 'package_not_closed' : null,
            ! $closingEntryIsReversible ? 'closing_entry_not_reversible' : null,
            $package->carry_forward_batch_id ? 'active_carry_forward_dependency' : null,
            $laterEntries > 0 ? 'later_year_postings_exist' : null,
            $anotherOpenExercise ? 'another_open_exercise_exists' : null,
        ]));

        return [
            'executable' => $issues === [],
            'package_id' => $package->id,
            'issues' => $issues,
            'later_entry_count' => $laterEntries,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    public function reopen(AccountingClosingPackage $package, User $actor, string $reason): AccountingClosingPackage
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Un motif documenté est obligatoire.')]);
        }

        return DB::transaction(function () use ($package, $actor, $reason) {
            $package = AccountingClosingPackage::query()->lockForUpdate()->findOrFail($package->id);
            if ($package->state === 'reopened') {
                return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
            }
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($package->accounting_book_id);
            $exercises = FinancialExercise::query()
                ->where('accounting_book_id', $book->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $exercise = $exercises->firstWhere('id', $package->financial_exercise_id)
                ?? throw ValidationException::withMessages(['exercise' => __('L’exercice comptable est introuvable.')]);
            $diagnostics = $this->reopeningDiagnostics($package);
            if (! $diagnostics['executable']) {
                throw ValidationException::withMessages([
                    'reopening' => __('La réouverture est bloquée par des dépendances : :issues', [
                        'issues' => implode(', ', $diagnostics['issues']),
                    ]),
                ]);
            }
            $periods = AccountingPeriod::query()->where('financial_exercise_id', $exercise->id)
                ->orderBy('sequence')->lockForUpdate()->get();
            $finalPeriod = $periods->last() ?? throw ValidationException::withMessages([
                'period' => __('La dernière période comptable est introuvable.'),
            ]);
            $closingEntry = JournalEntry::query()->lockForUpdate()->findOrFail($package->closing_entry_id);

            $exercise->update([
                'status' => 'open',
                'closed_at' => null,
                'closed_by' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);
            $finalPeriod->update([
                'status' => 'open',
                'reopen_reason' => $reason,
                'reopened_by' => $actor->id,
                'reopened_at' => now(),
            ]);
            $reversal = $this->posting->reverse($closingEntry, $finalPeriod, $actor, $reason);
            $package->update(['state' => 'reopened']);
            $this->transition($package, 'closed', 'reopened', 'reopen', $actor, $reason, [
                'closing_entry_id' => $closingEntry->id,
                'reversal_entry_id' => $reversal->id,
                'final_period_id' => $finalPeriod->id,
            ]);
            $this->activity($package, 'fiscal_year_reopened', $actor, $reason, [
                'closing_entry_id' => $closingEntry->id,
                'reversal_entry_id' => $reversal->id,
                'locked_periods_preserved' => $periods->slice(0, -1)->pluck('id')->all(),
            ]);

            return $package->fresh(['configuration', 'periodSnapshots', 'transitions']);
        }, 5);
    }

    private function closingLines(AccountingBook $book, FinancialExercise $exercise, AccountingClosingPackage $package): array
    {
        $ledger = $this->reports->generate($book, $exercise, [
            'report' => 'general-ledger',
            'date_from' => $exercise->starts_on->toDateString(),
            'date_to' => $exercise->ends_on->toDateString(),
            'snapshot_entry_id' => $package->snapshot_entry_id,
        ]);
        $configuration = $package->configuration;
        $temporary = $configuration->classifications->where('review_status', 'approved')
            ->whereIn('closing_role', ['temporary_income', 'temporary_expense'])
            ->keyBy('ledger_account_id');
        $lines = [];
        foreach ($ledger['rows'] as $row) {
            if (! $temporary->has($row['account_id'])) {
                continue;
            }
            $net = (int) $row['closing_debit_minor'] - (int) $row['closing_credit_minor'];
            if ($net === 0) {
                continue;
            }
            $lines[] = [
                'ledger_account_id' => $row['account_id'],
                'label' => 'Solde de clôture '.$row['code'],
                'debit_minor' => max(-$net, 0),
                'credit_minor' => max($net, 0),
            ];
        }
        $debit = (int) collect($lines)->sum('debit_minor');
        $credit = (int) collect($lines)->sum('credit_minor');
        $difference = $debit - $credit;
        if ($difference !== 0) {
            $lines[] = [
                'ledger_account_id' => $configuration->result_transfer_account_id,
                'label' => 'Transfert du résultat '.$exercise->reference,
                'debit_minor' => max(-$difference, 0),
                'credit_minor' => max($difference, 0),
            ];
        }

        return $lines;
    }

    private function freshEvaluation(AccountingClosingPackage $package): array
    {
        $book = AccountingBook::findOrFail($package->accounting_book_id);
        $exercise = FinancialExercise::findOrFail($package->financial_exercise_id);

        return $this->readiness->evaluate($book, $exercise, $package);
    }

    private function assertFresh(AccountingClosingPackage $package, array $evaluation): void
    {
        if (! hash_equals($package->integrity_fingerprint, $this->packageFingerprint($evaluation))) {
            throw ValidationException::withMessages(['snapshot' => __('Le dossier est devenu obsolète; une nouvelle préparation est obligatoire.')]);
        }
    }

    private function invalidateIfStale(AccountingClosingPackage $package, User $actor): void
    {
        $package = $package->fresh();
        $evaluation = $this->freshEvaluation($package);
        if (hash_equals($package->integrity_fingerprint, $this->packageFingerprint($evaluation))) {
            return;
        }
        DB::transaction(function () use ($package, $actor, $evaluation) {
            $locked = AccountingClosingPackage::query()->lockForUpdate()->findOrFail($package->id);
            $from = $locked->state;
            $locked->update([
                'state' => 'blocked',
                'stale_at' => now(),
                'stale_reason_code' => 'snapshot_changed',
            ]);
            $this->transition($locked, $from, 'blocked', 'invalidate', $actor, null, [
                'current_snapshot_fingerprint' => $evaluation['snapshot_fingerprint'],
            ]);
        }, 5);
        throw ValidationException::withMessages(['snapshot' => __('Le dossier est devenu obsolète; une nouvelle préparation est obligatoire.')]);
    }

    private function packageFingerprint(array $evaluation): string
    {
        return hash('sha256', json_encode([
            'snapshot' => $evaluation['snapshot'],
            'configuration_id' => $evaluation['configuration_id'],
            'trial_balance_totals' => $evaluation['trial_balance_totals'],
            'blocking_checks' => collect($evaluation['checks'])
                ->filter(fn ($check) => $check['blocks_approval'] || $check['blocks_execution'])
                ->map(fn ($check) => [$check['code'], $check['result'], $check['evidence']])
                ->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function scope(AccountingBook $book, FinancialExercise $exercise): void
    {
        if ((int) $book->id !== (int) $exercise->accounting_book_id
            || (int) $book->organization_id !== (int) $exercise->organization_id
            || (int) $book->residence_id !== (int) $exercise->residence_id) {
            throw ValidationException::withMessages(['scope' => __('Le livre et l’exercice doivent appartenir au même périmètre comptable.')]);
        }
    }

    private function transition(
        AccountingClosingPackage $package,
        ?string $from,
        string $to,
        string $action,
        ?User $actor,
        ?string $reason = null,
        array $evidence = [],
    ): void {
        AccountingClosingTransition::create([
            'accounting_closing_package_id' => $package->id,
            'from_state' => $from,
            'to_state' => $to,
            'action' => $action,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'evidence' => $evidence ?: null,
            'occurred_at' => now(),
        ]);
    }

    private function activity(
        AccountingClosingPackage $package,
        string $action,
        User $actor,
        ?string $reason = null,
        array $evidence = [],
    ): void {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $package->organization_id,
            'residence_id' => $package->residence_id,
            'record_type' => AccountingClosingPackage::class,
            'record_id' => $package->id,
            'action' => $action,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'after_evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'context' => app()->runningInConsole() ? 'command' : 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
