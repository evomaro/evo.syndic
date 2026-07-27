<?php

namespace App\Console\Commands;

use App\Models\AccountingBook;
use App\Models\FinancialExercise;
use App\Services\AccountingClosingReadinessService;
use Illuminate\Console\Command;

class AuditAccountingClosingReadiness extends Command
{
    protected $signature = 'accounting:audit-closing-readiness
        {--organization=} {--residence=} {--book=} {--exercise=} {--json}';

    protected $description = 'Read-only accounting closing-readiness audit';

    public function handle(AccountingClosingReadinessService $readiness): int
    {
        $results = [];
        $books = AccountingBook::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($this->option('residence'), fn ($q, $id) => $q->where('residence_id', $id))
            ->when($this->option('book'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($books as $book) {
            $exercises = FinancialExercise::where('accounting_book_id', $book->id)
                ->when($this->option('exercise'), fn ($q, $id) => $q->whereKey($id))->get();
            foreach ($exercises as $exercise) {
                $results[] = $readiness->evaluate($book, $exercise);
            }
        }
        $report = [
            'ok' => collect($results)->every('execution_ready'),
            'checked' => ['books' => $books->count(), 'exercises' => count($results)],
            'results' => $results,
        ];
        $this->output($report);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function output(array $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line("Exercises checked: {$report['checked']['exercises']}");
            $report['ok'] ? $this->info('Closing readiness passed.') : $this->error('Closing readiness blockers found.');
        }
    }
}
