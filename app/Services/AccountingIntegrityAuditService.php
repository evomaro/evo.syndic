<?php

namespace App\Services;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AccountingIntegrityAuditService
{
    public function run(array $filters = []): array
    {
        $violations = [];
        $entries = JournalEntry::query()->with(['lines.account', 'period', 'journal'])
            ->whereIn('status', ['posted', 'reversed'])
            ->when($filters['organization'] ?? null, fn (Builder $q, $id) => $q->where('organization_id', $id))
            ->when($filters['residence'] ?? null, fn (Builder $q, $id) => $q->where('residence_id', $id))->get();
        foreach ($entries as $entry) {
            $debit = $entry->lines->sum('debit_minor');
            $credit = $entry->lines->sum('credit_minor');
            if ($debit !== $credit || $debit <= 0) {
                $this->add($violations, 'unbalanced_posted_entry', $entry->id);
            }
            foreach ($entry->lines as $line) {
                if (! (($line->debit_minor > 0) xor ($line->credit_minor > 0))) {
                    $this->add($violations, 'invalid_line_shape', $entry->id, $line->id);
                }
                if (! $line->account || $line->account->organization_id !== $entry->organization_id || $line->account->residence_id !== $entry->residence_id) {
                    $this->add($violations, 'cross_tenant_line', $entry->id, $line->id);
                }
                if ($line->account && ! $line->account->posting_allowed) {
                    $this->add($violations, 'non_posting_account', $entry->id, $line->id);
                }
            }
            if (! $entry->period || $entry->entry_date->lt($entry->period->starts_on) || $entry->entry_date->gt($entry->period->ends_on)) {
                $this->add($violations, 'period_date_mismatch', $entry->id);
            }
            if (! $entry->journal || $entry->journal->residence_id !== $entry->residence_id) {
                $this->add($violations, 'journal_book_mismatch', $entry->id);
            }
            if ($entry->reversal_of_id && ! $entry->reversalOf?->reversed_by_id) {
                $this->add($violations, 'invalid_reversal_chain', $entry->id);
            }
            if ($entry->posting_fingerprint && $entry->posting_fingerprint !== app(AccountingPostingService::class)->fingerprint($entry, $entry->entry_number, $entry->lines)) {
                $this->add($violations, 'fingerprint_mismatch', $entry->id);
            }
        }
        $duplicates = DB::table('journal_entries')->select('accounting_journal_id', 'financial_exercise_id', 'entry_number', DB::raw('COUNT(*) total'))
            ->whereNotNull('entry_number')->groupBy('accounting_journal_id', 'financial_exercise_id', 'entry_number')->having('total', '>', 1)->get();
        foreach ($duplicates as $duplicate) {
            $this->add($violations, 'duplicate_entry_number', $duplicate->entry_number);
        }

        $counts = collect($violations)->countBy('classification')->sortKeys()->all();

        return ['ok' => $violations === [], 'checked' => ['posted_entries' => $entries->count()], 'counts' => $counts, 'violations' => $violations];
    }

    private function add(array &$violations, string $classification, mixed $entry, mixed $line = null): void
    {
        $violations[] = array_filter(['classification' => $classification, 'entry_id' => $entry, 'line_id' => $line], fn ($v) => $v !== null);
    }
}
