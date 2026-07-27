<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBatch;
use App\Models\AccountingPeriod;
use App\Models\FinancialExercise;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    public function __construct(private readonly AccountingPostingService $posting) {}

    public function review(AccountingOpeningBatch $batch, User $actor): AccountingOpeningBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $batch = AccountingOpeningBatch::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'reviewed') {
                return $batch;
            }
            if ($batch->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('Seul un solde d’ouverture brouillon peut être revu.')]);
            }
            $this->validate($batch);
            $batch->update(['status' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $this->event($batch, 'opening_balance_reviewed', $actor);

            return $batch->fresh('lines');
        });
    }

    public function post(AccountingOpeningBatch $batch, User $actor): AccountingOpeningBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $batch = AccountingOpeningBatch::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'posted') {
                return $batch;
            }
            if ($batch->status !== 'reviewed') {
                throw ValidationException::withMessages(['status' => __('Le solde d’ouverture doit être revu avant comptabilisation.')]);
            }
            $this->validate($batch);
            $period = AccountingPeriod::query()
                ->where('financial_exercise_id', $batch->financial_exercise_id)
                ->where('status', 'open')
                ->whereDate('starts_on', '<=', $batch->opening_date)
                ->whereDate('ends_on', '>=', $batch->opening_date)
                ->lockForUpdate()
                ->firstOrFail();

            $entry = JournalEntry::create([
                'organization_id' => $batch->organization_id,
                'residence_id' => $batch->residence_id,
                'accounting_book_id' => $batch->accounting_book_id,
                'financial_exercise_id' => $batch->financial_exercise_id,
                'accounting_period_id' => $period->id,
                'accounting_journal_id' => $batch->accounting_journal_id,
                'entry_date' => $batch->opening_date,
                'reference' => $batch->reference,
                'description_fr' => __('Soldes d’ouverture initiaux'),
                'status' => 'draft',
                'source_type' => 'opening_balance',
                'source_id' => (string) $batch->id,
                'posting_key' => hash('sha256', "opening|{$batch->accounting_book_id}|{$batch->financial_exercise_id}|{$batch->id}"),
                'metadata' => ['kind' => 'initial_migration_opening', 'supporting_document_reference' => $batch->supporting_document_reference],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            foreach ($batch->lines as $line) {
                $entry->lines()->create([
                    'sequence' => $line->sequence,
                    'ledger_account_id' => $line->ledger_account_id,
                    'label' => $line->label,
                    'debit_minor' => $line->debit_minor,
                    'credit_minor' => $line->credit_minor,
                ]);
            }
            $entry = $this->posting->post($entry, $actor);
            $batch->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now(), 'journal_entry_id' => $entry->id]);
            $this->event($batch, 'opening_balance_posted', $actor, ['journal_entry_id' => $entry->id]);

            return $batch->fresh('lines');
        }, 5);
    }

    private function validate(AccountingOpeningBatch $batch): void
    {
        $exercise = FinancialExercise::findOrFail($batch->financial_exercise_id);
        $journal = AccountingJournal::findOrFail($batch->accounting_journal_id);
        if ((int) $exercise->organization_id !== (int) $batch->organization_id
            || (int) $exercise->residence_id !== (int) $batch->residence_id
            || (int) $exercise->accounting_book_id !== (int) $batch->accounting_book_id
            || (int) $journal->accounting_book_id !== (int) $batch->accounting_book_id
            || $journal->type !== 'opening') {
            throw ValidationException::withMessages(['scope' => __('Le livre, l’exercice et le journal d’ouverture doivent appartenir à la même comptabilité.')]);
        }
        if ($exercise->status !== 'open' || $batch->opening_date->lt($exercise->starts_on) || $batch->opening_date->gt($exercise->ends_on)) {
            throw ValidationException::withMessages(['opening_date' => __('La date d’ouverture doit appartenir à un exercice ouvert.')]);
        }
        if ($batch->lines->count() < 2) {
            throw ValidationException::withMessages(['lines' => __('Au moins deux lignes sont requises.')]);
        }
        $debit = 0;
        $credit = 0;
        foreach ($batch->lines as $line) {
            if (($line->debit_minor > 0) === ($line->credit_minor > 0)
                || ! $line->ledgerAccount()->where('accounting_book_id', $batch->accounting_book_id)->where('active', true)->where('posting_allowed', true)->exists()) {
                throw ValidationException::withMessages(['lines' => __('Chaque ligne doit contenir un seul montant positif sur un compte autorisé.')]);
            }
            $debit += (int) $line->debit_minor;
            $credit += (int) $line->credit_minor;
        }
        if ($debit <= 0 || $debit !== $credit) {
            throw ValidationException::withMessages(['lines' => __('Les soldes d’ouverture doivent être exactement équilibrés.')]);
        }
    }

    private function event(AccountingOpeningBatch $batch, string $action, User $actor, array $evidence = []): void
    {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $batch->organization_id,
            'residence_id' => $batch->residence_id,
            'record_type' => AccountingOpeningBatch::class,
            'record_id' => $batch->id,
            'action' => $action,
            'actor_id' => $actor->id,
            'after_evidence' => json_encode($evidence),
            'context' => app()->runningInConsole() ? 'command' : 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
