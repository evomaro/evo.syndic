<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\FinancialExercise;
use App\Models\LedgerAccount;
use App\Models\LotCharge;
use App\Models\SupplierInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    public const TYPES = [
        'journal', 'general-ledger', 'account-ledger', 'trial-balance',
        'receivables', 'payables', 'budget-actual', 'reconciliation', 'period-summary',
    ];

    public function generate(AccountingBook $book, FinancialExercise $exercise, array $filters, int $page = 1, int $perPage = 50): array
    {
        $type = $filters['report'] ?? 'journal';
        $boundary = (int) ($filters['snapshot_entry_id'] ?? $this->snapshotBoundary($book));
        $from = $filters['date_from'] ?? $exercise->starts_on->toDateString();
        $to = $filters['date_to'] ?? $exercise->ends_on->toDateString();
        $filters['date_from'] = $from;
        $filters['date_to'] = $to;
        $filters['snapshot_entry_id'] = $boundary;

        return match ($type) {
            'journal' => $this->journal($book, $exercise, $filters, $page, $perPage),
            'general-ledger' => $this->generalLedger($book, $exercise, $filters),
            'account-ledger' => $this->accountLedger($book, $exercise, $filters, $page, $perPage),
            'trial-balance' => $this->trialBalance($book, $exercise, $filters),
            'receivables' => $this->operationalReceivables($book, $exercise, $filters),
            'payables' => $this->operationalPayables($book, $exercise, $filters),
            'budget-actual' => $this->budgetActual($book, $exercise, $filters),
            'reconciliation' => $this->reconciliation($book, $exercise, $filters),
            'period-summary' => $this->periodSummary($book, $exercise, $filters),
            default => throw new \InvalidArgumentException('Unsupported accounting report.'),
        };
    }

    public function exportRows(array $report): array
    {
        $rows = $report['rows'] ?? [];
        if (! $rows) {
            return [];
        }

        return array_map(fn ($row) => (array) $row, $rows);
    }

    private function snapshotBoundary(AccountingBook $book): int
    {
        return (int) DB::table('journal_entries')
            ->where('accounting_book_id', $book->id)
            ->whereIn('status', ['posted', 'reversed'])
            ->max('id');
    }

    private function entries(AccountingBook $book, FinancialExercise $exercise, array $filters): Builder
    {
        return DB::table('journal_entries as e')
            ->where('e.organization_id', $book->organization_id)
            ->where('e.residence_id', $book->residence_id)
            ->where('e.accounting_book_id', $book->id)
            ->where('e.financial_exercise_id', $exercise->id)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.id', '<=', $filters['snapshot_entry_id'])
            ->whereDate('e.entry_date', '>=', $filters['date_from'])
            ->whereDate('e.entry_date', '<=', $filters['date_to'])
            ->when($filters['accounting_journal_id'] ?? null, fn (Builder $q, $id) => $q->where('e.accounting_journal_id', $id))
            ->when($filters['source_type'] ?? null, fn (Builder $q, $type) => $q->where('e.source_type', $type))
            ->when($filters['entry_reference'] ?? null, fn (Builder $q, $reference) => $q->where('e.reference', 'like', '%'.$reference.'%'))
            ->when($filters['reversal_state'] ?? null, function (Builder $q, $state) {
                if ($state === 'original') {
                    $q->whereNull('e.reversal_of_id');
                } elseif ($state === 'reversal') {
                    $q->whereNotNull('e.reversal_of_id');
                }
            });
    }

    private function journal(AccountingBook $book, FinancialExercise $exercise, array $filters, int $page, int $perPage): array
    {
        $base = $this->entries($book, $exercise, $filters)
            ->join('accounting_journals as j', 'j.id', '=', 'e.accounting_journal_id')
            ->leftJoin('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->leftJoin('accounting_source_postings as sp', 'sp.journal_entry_id', '=', 'e.id')
            ->selectRaw('e.id, e.entry_date, e.entry_number, e.reference, e.description_fr, e.description_ar, e.source_type, e.source_id, e.status, e.reversal_of_id, e.reversed_by_id, e.posted_at, e.posted_by, j.code as journal_code, j.label_fr as journal_label_fr, j.label_ar as journal_label_ar, sp.id as source_posting_id, COALESCE(SUM(l.debit_minor), 0) as debit_minor, COALESCE(SUM(l.credit_minor), 0) as credit_minor')
            ->groupBy('e.id', 'e.entry_date', 'e.entry_number', 'e.reference', 'e.description_fr', 'e.description_ar', 'e.source_type', 'e.source_id', 'e.status', 'e.reversal_of_id', 'e.reversed_by_id', 'e.posted_at', 'e.posted_by', 'j.code', 'j.label_fr', 'j.label_ar', 'sp.id')
            ->orderBy('e.entry_date')->orderBy('j.code')->orderBy('e.entry_number')->orderBy('e.id');

        $summary = DB::query()
            ->fromSub((clone $base)->reorder(), 'journal_report')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(debit_minor), 0) as debit_minor, COALESCE(SUM(credit_minor), 0) as credit_minor')
            ->first();
        $total = (int) $summary->aggregate_count;
        $debit = (int) $summary->debit_minor;
        $credit = (int) $summary->credit_minor;
        $rows = (clone $base)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->all();

        return $this->result('journal', $filters, $rows, [
            'debit_minor' => $debit, 'credit_minor' => $credit, 'balanced' => $debit === $credit,
        ], $total, $page, $perPage);
    }

    private function generalLedger(AccountingBook $book, FinancialExercise $exercise, array $filters): array
    {
        $accounts = LedgerAccount::query()
            ->where('accounting_book_id', $book->id)
            ->when($filters['ledger_account_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['code_from'] ?? null, fn ($q, $code) => $q->where('code', '>=', $code))
            ->when($filters['code_to'] ?? null, fn ($q, $code) => $q->where('code', '<=', $code))
            ->orderBy('code')->get(['id', 'parent_id', 'code', 'label_fr', 'label_ar', 'posting_allowed']);

        $opening = $this->lineTotals($book, $exercise, $filters, null, $filters['date_from']);
        $period = $this->lineTotals($book, $exercise, $filters, $filters['date_from'], $filters['date_to']);
        $rows = $accounts->map(function (LedgerAccount $account) use ($opening, $period) {
            $o = $opening->get($account->id, ['debit' => 0, 'credit' => 0, 'count' => 0]);
            $p = $period->get($account->id, ['debit' => 0, 'credit' => 0, 'count' => 0]);
            $openingNet = (int) $o['debit'] - (int) $o['credit'];
            $closingNet = $openingNet + (int) $p['debit'] - (int) $p['credit'];

            return [
                'account_id' => $account->id, 'parent_id' => $account->parent_id, 'code' => $account->code,
                'label_fr' => $account->label_fr, 'label_ar' => $account->label_ar,
                'posting_allowed' => $account->posting_allowed,
                'opening_debit_minor' => max($openingNet, 0), 'opening_credit_minor' => max(-$openingNet, 0),
                'period_debit_minor' => (int) $p['debit'], 'period_credit_minor' => (int) $p['credit'],
                'closing_debit_minor' => max($closingNet, 0), 'closing_credit_minor' => max(-$closingNet, 0),
                'movement_count' => (int) $p['count'], 'aggregation' => 'direct',
            ];
        })->when($filters['hide_zero'] ?? false, fn (Collection $rows) => $rows->filter(
            fn (array $row) => $row['opening_debit_minor'] || $row['opening_credit_minor'] || $row['period_debit_minor'] || $row['period_credit_minor']
        ))->values();

        return $this->result('general-ledger', $filters, $rows->all(), $this->ledgerTotals($rows), $rows->count());
    }

    private function trialBalance(AccountingBook $book, FinancialExercise $exercise, array $filters): array
    {
        $ledger = $this->generalLedger($book, $exercise, $filters);
        $ledger['type'] = 'trial-balance';
        $ledger['totals']['balanced'] =
            $ledger['totals']['opening_debit_minor'] === $ledger['totals']['opening_credit_minor']
            && $ledger['totals']['period_debit_minor'] === $ledger['totals']['period_credit_minor']
            && $ledger['totals']['closing_debit_minor'] === $ledger['totals']['closing_credit_minor'];
        $ledger['integrity'] = $ledger['totals']['balanced'] ? 'balanced' : 'unbalanced_ledger';

        return $ledger;
    }

    private function accountLedger(AccountingBook $book, FinancialExercise $exercise, array $filters, int $page, int $perPage): array
    {
        $account = LedgerAccount::where('accounting_book_id', $book->id)->findOrFail($filters['ledger_account_id'] ?? 0);
        $opening = $this->lineTotals($book, $exercise, $filters, null, $filters['date_from'])->get($account->id, ['debit' => 0, 'credit' => 0]);
        $openingNet = (int) $opening['debit'] - (int) $opening['credit'];
        $movements = $this->movementQuery($book, $exercise, $filters)
            ->where('l.ledger_account_id', $account->id)
            ->reorder();
        $summary = DB::query()
            ->fromSub(clone $movements, 'account_movements')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(debit_minor), 0) as debit_minor, COALESCE(SUM(credit_minor), 0) as credit_minor')
            ->first();
        $total = (int) $summary->aggregate_count;
        $periodDebit = (int) $summary->debit_minor;
        $periodCredit = (int) $summary->credit_minor;
        $pageRows = DB::query()
            ->fromSub(clone $movements, 'account_movements')
            ->select('*')
            ->selectRaw('? + SUM(debit_minor - credit_minor) OVER (ORDER BY entry_date, journal_code, entry_number, entry_id, sequence, line_id ROWS UNBOUNDED PRECEDING) as running_net_minor', [$openingNet])
            ->orderBy('entry_date')->orderBy('journal_code')->orderBy('entry_number')
            ->orderBy('entry_id')->orderBy('sequence')->orderBy('line_id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($row) {
                $running = (int) $row->running_net_minor;
                unset($row->running_net_minor);
                $row->running_debit_minor = max($running, 0);
                $row->running_credit_minor = max(-$running, 0);

                return $row;
            });
        $closing = $openingNet + $periodDebit - $periodCredit;

        $result = $this->result('account-ledger', $filters, $pageRows->all(), [
            'opening_debit_minor' => max($openingNet, 0), 'opening_credit_minor' => max(-$openingNet, 0),
            'period_debit_minor' => $periodDebit, 'period_credit_minor' => $periodCredit,
            'closing_debit_minor' => max($closing, 0), 'closing_credit_minor' => max(-$closing, 0),
        ], $total, $page, $perPage);
        $result['account'] = $account->only(['id', 'parent_id', 'code', 'label_fr', 'label_ar']);

        return $result;
    }

    private function movementQuery(AccountingBook $book, FinancialExercise $exercise, array $filters): Builder
    {
        return $this->entries($book, $exercise, $filters)
            ->join('accounting_journals as j', 'j.id', '=', 'e.accounting_journal_id')
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->leftJoin('accounting_source_postings as sp', 'sp.journal_entry_id', '=', 'e.id')
            ->select('l.id as line_id', 'l.sequence', 'l.ledger_account_id', 'l.account_code_snapshot as code', 'l.account_label_snapshot as account_label', 'l.label', 'l.debit_minor', 'l.credit_minor', 'e.id as entry_id', 'e.entry_date', 'e.entry_number', 'e.reference', 'e.description_fr', 'e.description_ar', 'e.source_type', 'e.source_id', 'e.reversal_of_id', 'j.code as journal_code', 'sp.id as source_posting_id')
            ->orderBy('e.entry_date')->orderBy('j.code')->orderBy('e.entry_number')->orderBy('e.id')->orderBy('l.sequence');
    }

    private function lineTotals(AccountingBook $book, FinancialExercise $exercise, array $filters, ?string $from, string $to): Collection
    {
        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.organization_id', $book->organization_id)
            ->where('e.residence_id', $book->residence_id)
            ->where('e.accounting_book_id', $book->id)
            ->where('e.financial_exercise_id', $exercise->id)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.id', '<=', $filters['snapshot_entry_id'])
            ->when(
                $from,
                fn (Builder $q) => $q->whereDate('e.entry_date', '>=', $from)->whereDate('e.entry_date', '<=', $to),
                fn (Builder $q) => $q->whereDate('e.entry_date', '<', $to)
            )
            ->when($filters['accounting_journal_id'] ?? null, fn (Builder $q, $id) => $q->where('e.accounting_journal_id', $id))
            ->selectRaw('l.ledger_account_id, COALESCE(SUM(l.debit_minor), 0) as debit, COALESCE(SUM(l.credit_minor), 0) as credit, COUNT(*) as movement_count')
            ->groupBy('l.ledger_account_id')->get();

        return $query->mapWithKeys(fn ($row) => [$row->ledger_account_id => [
            'debit' => (int) $row->debit, 'credit' => (int) $row->credit, 'count' => (int) $row->movement_count,
        ]]);
    }

    private function sourceSubledger(AccountingBook $book, FinancialExercise $exercise, array $filters, array $domains, bool $partial): array
    {
        $rows = DB::table('accounting_source_postings as sp')
            ->join('journal_entries as e', 'e.id', '=', 'sp.journal_entry_id')
            ->leftJoin('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('sp.organization_id', $book->organization_id)->where('sp.residence_id', $book->residence_id)
            ->where('sp.accounting_book_id', $book->id)->whereIn('sp.source_type', $domains)
            ->where('e.financial_exercise_id', $exercise->id)->where('e.id', '<=', $filters['snapshot_entry_id'])
            ->whereDate('e.entry_date', '>=', $filters['date_from'])
            ->whereDate('e.entry_date', '<=', $filters['date_to'])
            ->selectRaw('sp.id as source_posting_id, sp.source_type, sp.source_id, sp.source_event, sp.status as posting_status, sp.reversal_entry_id, e.id as entry_id, e.entry_number, e.entry_date, e.reference, COALESCE(SUM(l.debit_minor), 0) as debit_minor, COALESCE(SUM(l.credit_minor), 0) as credit_minor')
            ->groupBy('sp.id', 'sp.source_type', 'sp.source_id', 'sp.source_event', 'sp.status', 'sp.reversal_entry_id', 'e.id', 'e.entry_number', 'e.entry_date', 'e.reference')
            ->orderBy('e.entry_date')->orderBy('e.id')->get();

        return $this->result($partial ? 'receivables' : 'payables', $filters, $rows->all(), [
            'debit_minor' => (int) $rows->sum('debit_minor'), 'credit_minor' => (int) $rows->sum('credit_minor'),
        ], $rows->count()) + [
            'classification' => $partial ? 'partial_owner_dimensions' : 'accounting_source_reconciliation',
            'notice_code' => $partial ? 'owner_dimensions_incomplete' : null,
        ];
    }

    private function operationalReceivables(AccountingBook $book, FinancialExercise $exercise, array $filters): array
    {
        $charges = LotCharge::query()
            ->where('organization_id', $book->organization_id)
            ->where('residence_id', $book->residence_id)
            ->where('financial_exercise_id', $exercise->id)
            ->whereNull('cancelled_at')
            ->whereDate('issue_date', '>=', $filters['date_from'])
            ->whereDate('issue_date', '<=', $filters['date_to'])
            ->when($filters['owner_contact_id'] ?? null, fn ($query, $id) => $query->where('billed_contact_id', $id))
            ->with([
                'lot:id,reference',
                'billedContact:id,type,first_name,last_name,company_name',
                'allocations' => fn ($query) => $query->whereNull('reversed_at'),
            ])
            ->orderBy('due_date')->orderBy('id')->get();
        $fundCallPostings = $this->postedSourceIds($book, $filters, 'fund_call', $charges->pluck('fund_call_id'));
        $allocations = $charges->pluck('allocations')->flatten();
        $paymentPostings = $this->postedSourceIds($book, $filters, 'payment', $allocations->pluck('payment_id'));
        $allocationPostings = $this->postedSourceIds($book, $filters, 'payment_allocation', $allocations->pluck('id'));
        $asOf = CarbonImmutable::parse($filters['as_of'] ?? $filters['date_to']);
        $rows = $charges->map(function (LotCharge $charge) use ($fundCallPostings, $paymentPostings, $allocationPostings, $asOf) {
            $operationalAllocated = (int) $charge->allocations->sum('amount_cents');
            $accountingRecognized = $fundCallPostings->has((string) $charge->fund_call_id) ? (int) $charge->amount_cents : 0;
            $accountingAllocated = (int) $charge->allocations->filter(
                fn ($allocation) => $paymentPostings->has((string) $allocation->payment_id)
                    || $allocationPostings->has((string) $allocation->id)
            )->sum('amount_cents');
            $operationalOutstanding = max(0, (int) $charge->amount_cents - $operationalAllocated);
            $accountingOutstanding = max(0, $accountingRecognized - $accountingAllocated);
            $days = $charge->due_date->lt($asOf) ? $charge->due_date->diffInDays($asOf) : 0;

            return [
                'charge_id' => $charge->id,
                'fund_call_id' => $charge->fund_call_id,
                'lot_id' => $charge->lot_id,
                'lot_reference' => $charge->lot_reference_snapshot ?: $charge->lot?->reference,
                'owner_contact_id' => $charge->billed_contact_id,
                'owner_name' => $charge->contact_name_snapshot ?: $charge->billedContact?->display_name,
                'issue_date' => $charge->issue_date->toDateString(),
                'due_date' => $charge->due_date->toDateString(),
                'aging' => $this->agingBucket($days),
                'operational_total_minor' => (int) $charge->amount_cents,
                'operational_allocated_minor' => $operationalAllocated,
                'operational_outstanding_minor' => $operationalOutstanding,
                'accounting_recognized_minor' => $accountingRecognized,
                'accounting_allocated_minor' => $accountingAllocated,
                'accounting_outstanding_minor' => $accountingOutstanding,
                'difference_minor' => $operationalOutstanding - $accountingOutstanding,
                'reconciliation_status' => $operationalOutstanding === $accountingOutstanding ? 'ok' : 'exception',
            ];
        })->when($filters['aging'] ?? null, fn (Collection $rows, string $aging) => $rows->where('aging', $aging))->values();

        return $this->result('receivables', $filters, $rows->all(), [
            'operational_outstanding_minor' => (int) $rows->sum('operational_outstanding_minor'),
            'accounting_outstanding_minor' => (int) $rows->sum('accounting_outstanding_minor'),
            'difference_minor' => (int) $rows->sum('difference_minor'),
            'exception_count' => $rows->where('reconciliation_status', 'exception')->count(),
        ], $rows->count()) + ['classification' => 'owner_operational_accounting_reconciliation'];
    }

    private function operationalPayables(AccountingBook $book, FinancialExercise $exercise, array $filters): array
    {
        $invoices = SupplierInvoice::query()
            ->where('organization_id', $book->organization_id)
            ->whereIn('status', ['validated', 'partial'])
            ->whereDate('invoice_date', '>=', $filters['date_from'])
            ->whereDate('invoice_date', '<=', $filters['date_to'])
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->whereHas('lines', fn ($query) => $query
                ->where('residence_id', $book->residence_id)
                ->where('financial_exercise_id', $exercise->id))
            ->with([
                'supplier:id,legal_name',
                'lines' => fn ($query) => $query
                    ->where('residence_id', $book->residence_id)
                    ->where('financial_exercise_id', $exercise->id),
                'settlementAllocations' => fn ($query) => $query->whereNull('reversed_at')
                    ->whereHas('line', fn ($line) => $line->where('residence_id', $book->residence_id)),
                'creditAllocations' => fn ($query) => $query->whereNull('reversed_at')
                    ->where('residence_id', $book->residence_id),
            ])
            ->orderBy('due_date')->orderBy('id')->get();
        $invoicePostings = $this->postedSourceAmounts($book, $filters, 'supplier_invoice', $invoices->pluck('id'));
        $settlements = $invoices->pluck('settlementAllocations')->flatten();
        $credits = $invoices->pluck('creditAllocations')->flatten();
        $settlementPostings = $this->postedSourceIds($book, $filters, 'supplier_settlement', $settlements->pluck('supplier_settlement_id'));
        $creditPostings = $this->postedSourceIds($book, $filters, 'supplier_credit_note', $credits->pluck('supplier_credit_note_id'));
        $asOf = CarbonImmutable::parse($filters['as_of'] ?? $filters['date_to']);
        $rows = $invoices->map(function (SupplierInvoice $invoice) use ($invoicePostings, $settlementPostings, $creditPostings, $asOf) {
            $total = (int) $invoice->lines->sum('total_cents');
            $paid = (int) $invoice->settlementAllocations->sum('amount_cents');
            $credited = (int) $invoice->creditAllocations->sum('amount_cents');
            $operationalOutstanding = max(0, $total - $paid - $credited);
            $recognized = min($total, (int) $invoicePostings->get((string) $invoice->id, 0));
            $accountingPaid = (int) $invoice->settlementAllocations
                ->filter(fn ($allocation) => $settlementPostings->has((string) $allocation->supplier_settlement_id))
                ->sum('amount_cents');
            $accountingCredited = (int) $invoice->creditAllocations
                ->filter(fn ($allocation) => $creditPostings->has((string) $allocation->supplier_credit_note_id))
                ->sum('amount_cents');
            $accountingOutstanding = max(0, $recognized - $accountingPaid - $accountingCredited);
            $days = $invoice->due_date->lt($asOf) ? $invoice->due_date->diffInDays($asOf) : 0;

            return [
                'invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'supplier_name' => $invoice->supplier?->legal_name,
                'invoice_number' => $invoice->number,
                'supplier_invoice_number' => $invoice->supplier_invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'aging' => $this->agingBucket($days),
                'operational_total_minor' => $total,
                'operational_paid_minor' => $paid,
                'operational_credited_minor' => $credited,
                'operational_outstanding_minor' => $operationalOutstanding,
                'accounting_recognized_minor' => $recognized,
                'accounting_paid_minor' => $accountingPaid,
                'accounting_credited_minor' => $accountingCredited,
                'accounting_outstanding_minor' => $accountingOutstanding,
                'difference_minor' => $operationalOutstanding - $accountingOutstanding,
                'reconciliation_status' => $operationalOutstanding === $accountingOutstanding ? 'ok' : 'exception',
            ];
        })->when($filters['aging'] ?? null, fn (Collection $rows, string $aging) => $rows->where('aging', $aging))->values();

        return $this->result('payables', $filters, $rows->all(), [
            'operational_outstanding_minor' => (int) $rows->sum('operational_outstanding_minor'),
            'accounting_outstanding_minor' => (int) $rows->sum('accounting_outstanding_minor'),
            'difference_minor' => (int) $rows->sum('difference_minor'),
            'exception_count' => $rows->where('reconciliation_status', 'exception')->count(),
        ], $rows->count()) + ['classification' => 'supplier_operational_accounting_reconciliation'];
    }

    private function postedSourceIds(AccountingBook $book, array $filters, string $sourceType, Collection $ids): Collection
    {
        if ($ids->filter()->isEmpty()) {
            return collect();
        }

        return DB::table('accounting_source_postings as source')
            ->join('journal_entries as entry', 'entry.id', '=', 'source.journal_entry_id')
            ->where('source.accounting_book_id', $book->id)
            ->where('source.source_type', $sourceType)
            ->where('source.status', 'posted')
            ->where('entry.id', '<=', $filters['snapshot_entry_id'])
            ->whereIn('source.source_id', $ids->filter()->map(fn ($id) => (string) $id)->unique())
            ->pluck('source.source_id')->mapWithKeys(fn ($id) => [(string) $id => true]);
    }

    private function postedSourceAmounts(AccountingBook $book, array $filters, string $sourceType, Collection $ids): Collection
    {
        if ($ids->filter()->isEmpty()) {
            return collect();
        }

        return DB::table('accounting_source_postings as source')
            ->join('journal_entries as entry', 'entry.id', '=', 'source.journal_entry_id')
            ->join('journal_entry_lines as line', 'line.journal_entry_id', '=', 'entry.id')
            ->where('source.accounting_book_id', $book->id)
            ->where('source.source_type', $sourceType)
            ->where('source.status', 'posted')
            ->where('entry.id', '<=', $filters['snapshot_entry_id'])
            ->whereIn('source.source_id', $ids->filter()->map(fn ($id) => (string) $id)->unique())
            ->groupBy('source.source_id')
            ->selectRaw('source.source_id, SUM(line.debit_minor) amount_minor')
            ->pluck('amount_minor', 'source.source_id')
            ->mapWithKeys(fn ($amount, $id) => [(string) $id => (int) $amount]);
    }

    private function agingBucket(int $days): string
    {
        return $days === 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '>90')));
    }

    private function budgetActual(AccountingBook $book, FinancialExercise $exercise, array $filters): array
    {
        $mappings = DB::table('accounting_source_mappings')
            ->where('accounting_book_id', $book->id)->where('mapping_type', 'expense_category')
            ->where('review_status', 'approved')->whereNull('superseded_by_id')
            ->get()->groupBy('source_id');
        $accountUse = $mappings->flatten()->groupBy('ledger_account_id');
        $periodTotals = $this->lineTotals($book, $exercise, $filters, $filters['date_from'], $filters['date_to']);
        $lines = DB::table('budget_lines as bl')->join('budgets as b', 'b.id', '=', 'bl.budget_id')
            ->join('expense_categories as c', 'c.id', '=', 'bl.expense_category_id')
            ->where('b.organization_id', $book->organization_id)->where('b.residence_id', $book->residence_id)
            ->where('b.financial_exercise_id', $exercise->id)->whereIn('b.status', ['approved', 'locked'])
            ->select('bl.expense_category_id', 'bl.planned_cents', 'c.code', 'c.name', 'b.id as budget_id')->orderBy('c.code')->get();
        $rows = $lines->map(function ($line) use ($mappings, $accountUse, $periodTotals) {
            $mapping = $mappings->get($line->expense_category_id)?->first();
            $ambiguous = $mapping && ($accountUse->get($mapping->ledger_account_id)?->count() ?? 0) > 1;
            $actual = $mapping && ! $ambiguous ? (int) ($periodTotals->get($mapping->ledger_account_id)['debit'] ?? 0) : null;
            $remaining = $actual === null ? null : (int) $line->planned_cents - $actual;

            return [
                'budget_id' => $line->budget_id, 'category_id' => $line->expense_category_id, 'code' => $line->code,
                'label' => $line->name, 'budget_minor' => (int) $line->planned_cents,
                'actual_minor' => $actual, 'remaining_minor' => $remaining,
                'variance_percent' => $actual !== null && (int) $line->planned_cents !== 0 ? round(($actual - (int) $line->planned_cents) * 100 / (int) $line->planned_cents, 2) : null,
                'mapping_status' => ! $mapping ? 'unmapped' : ($ambiguous ? 'shared_account_ambiguous' : 'mapped'),
                'ledger_account_id' => $mapping?->ledger_account_id,
            ];
        });

        return $this->result('budget-actual', $filters, $rows->all(), [
            'budget_minor' => (int) $rows->sum('budget_minor'),
            'actual_minor' => (int) $rows->whereNotNull('actual_minor')->sum('actual_minor'),
            'ambiguous_count' => $rows->where('mapping_status', 'shared_account_ambiguous')->count(),
            'unmapped_count' => $rows->where('mapping_status', 'unmapped')->count(),
        ], $rows->count());
    }

    private function reconciliation(AccountingBook $book, FinancialExercise $exercise, array $filters): array
    {
        $rows = DB::table('accounting_source_postings as sp')
            ->leftJoin('journal_entries as e', 'e.id', '=', 'sp.journal_entry_id')
            ->leftJoin('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('sp.organization_id', $book->organization_id)->where('sp.residence_id', $book->residence_id)
            ->where('sp.accounting_book_id', $book->id)
            ->when($filters['source_type'] ?? null, fn (Builder $q, $type) => $q->where('sp.source_type', $type))
            ->where(function (Builder $q) use ($exercise) {
                $q->whereNull('e.financial_exercise_id')->orWhere('e.financial_exercise_id', $exercise->id);
            })
            ->selectRaw('sp.source_type, COUNT(DISTINCT sp.id) as source_count, COUNT(DISTINCT CASE WHEN sp.status = \'posted\' THEN sp.id END) as posted_count, COUNT(DISTINCT CASE WHEN sp.status = \'pending\' THEN sp.id END) as pending_count, COUNT(DISTINCT CASE WHEN sp.status = \'failed\' THEN sp.id END) as failed_count, COUNT(DISTINCT CASE WHEN sp.status = \'reversed\' THEN sp.id END) as reversed_count, COALESCE(SUM(l.debit_minor), 0) as posted_debit_minor, COALESCE(SUM(l.credit_minor), 0) as posted_credit_minor')
            ->groupBy('sp.source_type')->orderBy('sp.source_type')->get()
            ->map(function ($row) {
                $row->missing_count = 0;
                $row->difference_minor = (int) $row->posted_debit_minor - (int) $row->posted_credit_minor;
                $row->integrity = $row->difference_minor === 0 && (int) $row->failed_count === 0 ? 'ok' : 'exception';

                return $row;
            });

        return $this->result('reconciliation', $filters, $rows->all(), [
            'source_count' => (int) $rows->sum('source_count'), 'posted_count' => (int) $rows->sum('posted_count'),
            'pending_count' => (int) $rows->sum('pending_count'), 'failed_count' => (int) $rows->sum('failed_count'),
            'reversed_count' => (int) $rows->sum('reversed_count'),
            'difference_minor' => (int) $rows->sum('difference_minor'),
        ], $rows->count());
    }

    private function periodSummary(AccountingBook $book, FinancialExercise $exercise, array $filters): array
    {
        $rows = DB::table('accounting_periods as p')
            ->leftJoin('journal_entries as e', function ($join) use ($filters) {
                $join->on('e.accounting_period_id', '=', 'p.id')
                    ->whereIn('e.status', ['posted', 'reversed'])->where('e.id', '<=', $filters['snapshot_entry_id']);
            })
            ->leftJoin('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('p.organization_id', $book->organization_id)->where('p.residence_id', $book->residence_id)
            ->where('p.financial_exercise_id', $exercise->id)
            ->selectRaw('p.id, p.sequence, p.label, p.starts_on, p.ends_on, p.status, COUNT(DISTINCT e.id) as entry_count, COUNT(DISTINCT CASE WHEN e.reversal_of_id IS NOT NULL THEN e.id END) as reversal_count, COUNT(DISTINCT e.accounting_journal_id) as journals_used, COALESCE(SUM(l.debit_minor), 0) as debit_minor, COALESCE(SUM(l.credit_minor), 0) as credit_minor, MAX(e.entry_date) as last_posting_date, MAX(e.entry_number) as last_entry_number')
            ->groupBy('p.id', 'p.sequence', 'p.label', 'p.starts_on', 'p.ends_on', 'p.status')->orderBy('p.sequence')->get();

        return $this->result('period-summary', $filters, $rows->all(), [
            'entry_count' => (int) $rows->sum('entry_count'), 'debit_minor' => (int) $rows->sum('debit_minor'),
            'credit_minor' => (int) $rows->sum('credit_minor'),
        ], $rows->count());
    }

    private function ledgerTotals(Collection $rows): array
    {
        return collect(['opening_debit_minor', 'opening_credit_minor', 'period_debit_minor', 'period_credit_minor', 'closing_debit_minor', 'closing_credit_minor'])
            ->mapWithKeys(fn (string $key) => [$key => (int) $rows->sum($key)])->all();
    }

    private function result(string $type, array $filters, array $rows, array $totals, ?int $total = null, int $page = 1, int $perPage = 50): array
    {
        return [
            'type' => $type, 'filters' => $filters, 'snapshot_entry_id' => $filters['snapshot_entry_id'],
            'generated_at' => now()->toIso8601String(), 'currency' => 'MAD',
            'rows' => $rows, 'totals' => $totals,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total ?? count($rows), 'last_page' => max(1, (int) ceil(($total ?? count($rows)) / $perPage))],
        ];
    }
}
