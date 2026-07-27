<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\FinancialExercise;

class AccountingReportIntegrityAuditService
{
    public function __construct(
        private AccountingReportService $reports,
        private AccountingIntegrityAuditService $accountingAudit,
        private SourcePostingIntegrityAuditService $sourceAudit,
    ) {}

    public function audit(array $filters = []): array
    {
        $accounting = $this->accountingAudit->run($filters);
        $sources = $this->sourceAudit->audit($filters);
        $violations = [];
        $checkedReports = 0;
        $books = AccountingBook::query()
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->where('residence_id', $id))
            ->get();

        foreach ($books as $book) {
            $exercises = FinancialExercise::where('accounting_book_id', $book->id)
                ->when($filters['exercise'] ?? null, fn ($q, $id) => $q->whereKey($id))->get();
            foreach ($exercises as $exercise) {
                $trial = $this->reports->generate($book, $exercise, [
                    'report' => 'trial-balance',
                    'date_from' => $exercise->starts_on->toDateString(),
                    'date_to' => $exercise->ends_on->toDateString(),
                ]);
                $checkedReports++;
                if (! $trial['totals']['balanced']) {
                    $violations[] = [
                        'classification' => 'trial_balance_unbalanced',
                        'accounting_book_id' => $book->id,
                        'financial_exercise_id' => $exercise->id,
                        'totals' => $trial['totals'],
                    ];
                }
            }
        }

        foreach ($accounting['violations'] as $violation) {
            $violations[] = ['classification' => 'accounting_integrity', 'detail' => $violation];
        }
        foreach ($sources['violations'] as $violation) {
            $violations[] = ['classification' => 'source_posting_integrity', 'detail' => $violation];
        }

        return [
            'ok' => $violations === [],
            'filters' => $filters,
            'checked' => [
                'books' => $books->count(),
                'reports' => $checkedReports,
                'posted_entries' => $accounting['checked']['posted_entries'] ?? 0,
                'source_postings' => $sources['checked']['source_postings'] ?? 0,
            ],
            'violation_count' => count($violations),
            'classifications' => collect($violations)->countBy('classification')->sortKeys()->all(),
            'violations' => $violations,
        ];
    }
}
