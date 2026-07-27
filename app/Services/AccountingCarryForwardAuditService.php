<?php

namespace App\Services;

use App\Models\AccountingOpeningBatch;
use Illuminate\Support\Facades\DB;

class AccountingCarryForwardAuditService
{
    public function audit(array $filters = []): array
    {
        $violations = [];
        $batches = AccountingOpeningBatch::query()
            ->with(['lines'])
            ->where('origin_type', 'carry_forward')
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->where('residence_id', $id))
            ->when($filters['book'] ?? null, fn ($q, $id) => $q->where('accounting_book_id', $id))
            ->get();
        foreach ($batches as $batch) {
            $package = DB::table('accounting_closing_packages')->where('id', $batch->closing_package_id)->first();
            if (! $package
                || (int) $package->organization_id !== (int) $batch->organization_id
                || (int) $package->residence_id !== (int) $batch->residence_id
                || (int) $package->accounting_book_id !== (int) $batch->accounting_book_id) {
                $violations[] = $this->violation('carry_forward_scope_mismatch', $batch->id);

                continue;
            }
            if (! in_array($package->state, ['closed', 'carry_forward_completed'], true)
                || ! $package->closing_entry_id) {
                $violations[] = $this->violation('carry_forward_before_closing', $batch->id);
            }
            if ((int) $batch->lines->sum('debit_minor') <= 0
                || (int) $batch->lines->sum('debit_minor') !== (int) $batch->lines->sum('credit_minor')) {
                $violations[] = $this->violation('carry_forward_total_mismatch', $batch->id);
            }
            $temporary = DB::table('accounting_closing_account_classifications')
                ->where('accounting_closing_configuration_id', $package->accounting_closing_configuration_id)
                ->whereIn('closing_role', ['temporary_income', 'temporary_expense'])
                ->pluck('ledger_account_id');
            if ($batch->lines->whereIn('ledger_account_id', $temporary)->isNotEmpty()) {
                $violations[] = $this->violation('temporary_account_carried_forward', $batch->id);
            }
        }
        $duplicates = AccountingOpeningBatch::query()->where('origin_type', 'carry_forward')
            ->selectRaw('accounting_book_id, financial_exercise_id, COUNT(*) count')
            ->groupBy('accounting_book_id', 'financial_exercise_id')
            ->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicates as $duplicate) {
            $violations[] = [
                'classification' => 'duplicate_carry_forward',
                'accounting_book_id' => $duplicate->accounting_book_id,
                'financial_exercise_id' => $duplicate->financial_exercise_id,
                'count' => (int) $duplicate->count,
            ];
        }

        return [
            'ok' => $violations === [],
            'filters' => $filters,
            'checked' => ['carry_forward_batches' => $batches->count()],
            'violation_count' => count($violations),
            'classifications' => collect($violations)->countBy('classification')->sortKeys()->all(),
            'violations' => $violations,
        ];
    }

    private function violation(string $classification, int $batch): array
    {
        return ['classification' => $classification, 'opening_batch_id' => $batch];
    }
}
