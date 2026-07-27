<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPostingService
{
    public function __construct(private readonly AccountingMutationGuard $guard) {}

    public function post(JournalEntry $entry, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actor) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if (in_array($entry->status, ['posted', 'reversed'], true)) {
                return $entry->load('lines');
            }

            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($entry->accounting_period_id);
            $exercise = DB::table('financial_exercises')->where('id', $entry->financial_exercise_id)->lockForUpdate()->first();
            $journal = AccountingJournal::query()->lockForUpdate()->findOrFail($entry->accounting_journal_id);
            $lines = $entry->lines()->with('account')->lockForUpdate()->get();
            $this->validate($entry, $period, $exercise, $journal, $lines);

            DB::table('accounting_journal_sequences')->insertOrIgnore([
                'accounting_journal_id' => $journal->id, 'financial_exercise_id' => $entry->financial_exercise_id,
                'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $sequence = DB::table('accounting_journal_sequences')
                ->where('accounting_journal_id', $journal->id)
                ->where('financial_exercise_id', $entry->financial_exercise_id)
                ->lockForUpdate()->first();
            $number = sprintf('%s-%s-%06d', $journal->code, $exercise->reference ?: $exercise->id, $sequence->next_value);
            DB::table('accounting_journal_sequences')->where('id', $sequence->id)->update(['next_value' => $sequence->next_value + 1, 'updated_at' => now()]);

            foreach ($lines as $line) {
                $line->forceFill([
                    'account_code_snapshot' => $line->account->code,
                    'account_label_snapshot' => $line->account->label_fr,
                ])->save();
            }
            $fingerprint = $this->fingerprint($entry, $number, $lines);
            $this->guard->run(fn () => $entry->forceFill([
                'entry_number' => $number, 'status' => 'posted', 'posted_by' => $actor->id,
                'posted_at' => now(), 'posting_fingerprint' => $fingerprint,
            ])->save());
            $this->event($entry, 'posted', $actor, null, ['entry_number' => $number, 'fingerprint' => $fingerprint]);

            return $entry->fresh('lines');
        }, 5);
    }

    public function reverse(JournalEntry $original, AccountingPeriod $period, User $actor, string $reason): JournalEntry
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Le motif est obligatoire.')]);
        }

        return DB::transaction(function () use ($original, $period, $actor, $reason) {
            $original = JournalEntry::query()->lockForUpdate()->with('lines')->findOrFail($original->id);
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($original->status !== 'posted' || $original->reversed_by_id) {
                throw ValidationException::withMessages(['entry' => __('Cette écriture ne peut pas être contre-passée.')]);
            }
            if ($period->status !== 'open' || $period->organization_id !== $original->organization_id || $period->residence_id !== $original->residence_id) {
                throw ValidationException::withMessages(['period' => __('La période de contre-passation doit être ouverte dans la même résidence.')]);
            }
            $reversal = JournalEntry::create([
                'organization_id' => $original->organization_id, 'residence_id' => $original->residence_id,
                'accounting_book_id' => $original->accounting_book_id, 'financial_exercise_id' => $period->financial_exercise_id,
                'accounting_period_id' => $period->id, 'accounting_journal_id' => $original->accounting_journal_id,
                'entry_date' => now()->between($period->starts_on, $period->ends_on)
                    ? now()->toDateString()
                    : $period->starts_on->toDateString(),
                'reference' => 'REV-'.$original->entry_number, 'description_fr' => 'Contre-passation : '.$original->description_fr,
                'description_ar' => $original->description_ar, 'status' => 'draft', 'reversal_of_id' => $original->id,
                'reversal_reason' => $reason, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                'posting_key' => 'reversal:'.$original->id,
            ]);
            foreach ($original->lines as $line) {
                $reversal->lines()->create([
                    'sequence' => $line->sequence, 'ledger_account_id' => $line->ledger_account_id,
                    'label' => $line->label, 'debit_minor' => $line->credit_minor, 'credit_minor' => $line->debit_minor,
                ]);
            }
            $reversal = $this->post($reversal, $actor);
            $this->guard->run(fn () => $original->forceFill([
                'status' => 'reversed', 'reversed_by_id' => $reversal->id,
                'reversed_by_actor' => $actor->id, 'reversed_at' => now(),
            ])->save());
            $this->event($original, 'reversed', $actor, $reason, ['reversal_id' => $reversal->id]);

            return $reversal;
        }, 5);
    }

    private function validate($entry, $period, $exercise, $journal, $lines): void
    {
        $errors = [];
        if (! $exercise || $exercise->status !== 'open') {
            $errors['financial_exercise_id'] = __('L’exercice comptable doit être ouvert.');
        }
        if ($period->status !== 'open') {
            $errors['accounting_period_id'] = __('La période comptable est verrouillée.');
        }
        if (! $journal->active) {
            $errors['accounting_journal_id'] = __('Le journal est inactif.');
        }
        if ($lines->count() < 2) {
            $errors['lines'] = __('Une écriture doit comporter au moins deux lignes.');
        }
        if ($entry->entry_date->lt($period->starts_on) || $entry->entry_date->gt($period->ends_on)) {
            $errors['entry_date'] = __('La date ne correspond pas à la période.');
        }
        if (! $exercise
            || (int) $period->financial_exercise_id !== (int) $entry->financial_exercise_id
            || (int) $exercise->accounting_book_id !== (int) $entry->accounting_book_id
            || (int) $journal->accounting_book_id !== (int) $entry->accounting_book_id) {
            $errors['accounting_book_id'] = __('Les exercice, période et journal doivent appartenir à la même comptabilité.');
        }
        if ($exercise && ($entry->entry_date->lt($exercise->starts_on) || $entry->entry_date->gt($exercise->ends_on))) {
            $errors['entry_date'] = __('La date doit appartenir à l’exercice comptable.');
        }
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $shape = ($line->debit_minor > 0) !== ($line->credit_minor > 0);
            if (! $shape) {
                $errors['lines'] = __('Chaque ligne doit contenir un débit ou un crédit positif, jamais les deux.');
            }
            if (! $line->account || ! $line->account->active || ! $line->account->posting_allowed) {
                $errors['lines'] = __('Un compte est inactif ou non mouvementable.');
            }
            if ($line->account && ($line->account->organization_id !== $entry->organization_id || $line->account->residence_id !== $entry->residence_id || $line->account->accounting_book_id !== $entry->accounting_book_id)) {
                $errors['lines'] = __('Un compte appartient à une autre comptabilité.');
            }
            $debit += (int) $line->debit_minor;
            $credit += (int) $line->credit_minor;
        }
        if ($debit <= 0 || $debit !== $credit) {
            $errors['lines'] = __('Les totaux débit et crédit doivent être strictement égaux.');
        }
        foreach (['organization_id', 'residence_id'] as $field) {
            if ((int) $period->{$field} !== (int) $entry->{$field}
                || (int) $journal->{$field} !== (int) $entry->{$field}
                || ! $exercise
                || (int) $exercise->{$field} !== (int) $entry->{$field}) {
                $errors[$field] = __('Relation comptable hors périmètre.');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function fingerprint(JournalEntry $entry, string $number, $lines): string
    {
        $payload = [
            'tenant' => [$entry->organization_id, $entry->residence_id, $entry->accounting_book_id],
            'scope' => [$entry->financial_exercise_id, $entry->accounting_period_id, $entry->accounting_journal_id],
            'number' => $number, 'date' => $entry->entry_date->toDateString(), 'reference' => $entry->reference,
            'descriptions' => [$entry->description_fr, $entry->description_ar],
            'source' => [$entry->source_type, $entry->source_id, $entry->posting_key],
            'lines' => $lines->sortBy('sequence')->map(fn ($line) => [
                (int) $line->sequence, (int) $line->ledger_account_id, $line->account?->code,
                $line->label, (int) $line->debit_minor, (int) $line->credit_minor,
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function event(JournalEntry $entry, string $action, User $actor, ?string $reason, array $after): void
    {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $entry->organization_id, 'residence_id' => $entry->residence_id,
            'record_type' => JournalEntry::class, 'record_id' => $entry->id, 'action' => $action,
            'actor_id' => $actor->id, 'reason' => $reason, 'after_evidence' => json_encode($after),
            'context' => app()->runningInConsole() ? 'command' : 'http', 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
