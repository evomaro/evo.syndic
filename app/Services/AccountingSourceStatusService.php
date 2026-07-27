<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\AccountingSourcePosting;

class AccountingSourceStatusService
{
    public function get(string $sourceType, int $sourceId, int $organizationId, int $residenceId): array
    {
        $book = AccountingBook::query()->where('organization_id', $organizationId)->where('residence_id', $residenceId)->first();
        if (! $book) {
            return ['status' => 'not_applicable'];
        }
        if (! $book->automation()->where('status', 'active')->exists()) {
            return ['status' => 'not_activated'];
        }
        $posting = AccountingSourcePosting::query()
            ->where('accounting_book_id', $book->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->latest('id')
            ->with('entry')
            ->first();
        if (! $posting) {
            return ['status' => 'pending'];
        }

        return [
            'status' => $posting->status,
            'entry_id' => $posting->journal_entry_id,
            'entry_number' => $posting->entry?->entry_number,
            'posted_at' => $posting->posted_at,
            'failure_classification' => $posting->failure_classification,
        ];
    }
}
