<?php

namespace App\Services;

use App\Models\AccountingAutomation;
use App\Models\AccountingBook;
use App\Models\AccountingSourcePosting;
use Illuminate\Support\Facades\DB;

class SourcePostingIntegrityAuditService
{
    public function __construct(private readonly AccountingAutomationService $automation) {}

    public function audit(array $filters = []): array
    {
        $violations = [];
        $query = AccountingSourcePosting::query()->with(['entry', 'rule']);
        foreach (['organization_id' => 'organization', 'residence_id' => 'residence'] as $column => $filter) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['source_domain'])) {
            $query->where('source_type', $filters['source_domain']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $registries = $query->get();
        foreach ($registries as $registry) {
            $table = $this->sourceTable($registry->source_type);
            if (! $table || ! DB::table($table)->where('id', $registry->source_id)->exists()) {
                $violations[] = $this->violation('source_missing', $registry);
            }
            if (! $registry->entry || (int) $registry->entry->organization_id !== (int) $registry->organization_id
                || (int) $registry->entry->residence_id !== (int) $registry->residence_id
                || (int) $registry->entry->accounting_book_id !== (int) $registry->accounting_book_id) {
                $violations[] = $this->violation('entry_scope_mismatch', $registry);
            }
            $activation = AccountingAutomation::where('accounting_book_id', $registry->accounting_book_id)->first();
            if (! $activation || $registry->entry?->entry_date?->lt($activation->effective_from)) {
                $violations[] = $this->violation('posting_before_activation', $registry);
            }
            if (! $registry->rule
                || $registry->entry?->entry_date?->lt($registry->rule->effective_from)
                || ($registry->rule->effective_to && $registry->entry?->entry_date?->gt($registry->rule->effective_to))) {
                $violations[] = $this->violation('rule_effective_date_mismatch', $registry);
            }
            if ($registry->status === 'reversed' && ! $registry->reversal_entry_id) {
                $violations[] = $this->violation('reversal_missing', $registry);
            }
            if ($table && ($sourceStatus = DB::table($table)->where('id', $registry->source_id)->value('status'))) {
                $cancelled = in_array($sourceStatus, ['cancelled', 'reversed'], true);
                if ($cancelled && $registry->status === 'posted') {
                    $violations[] = $this->violation('reversed_source_without_accounting_reversal', $registry);
                }
                if (! $cancelled && $registry->status === 'reversed') {
                    $violations[] = $this->violation('accounting_reversal_without_source_correction', $registry);
                }
            }
            $expected = $this->expectedAmount($registry);
            if ($expected !== null && $registry->entry && (int) $registry->entry->lines()->sum('debit_minor') !== $expected) {
                $violations[] = $this->violation('source_entry_amount_mismatch', $registry) + ['expected_minor' => $expected];
            }
        }

        $duplicates = AccountingSourcePosting::query()
            ->selectRaw('accounting_book_id, source_type, source_id, source_event, source_version, COUNT(*) count')
            ->groupBy('accounting_book_id', 'source_type', 'source_id', 'source_event', 'source_version')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicates as $duplicate) {
            $violations[] = [
                'classification' => 'duplicate_source_posting',
                'source_type' => $duplicate->source_type,
                'source_id' => (int) $duplicate->source_id,
                'count' => (int) $duplicate->count,
            ];
        }

        $overallocated = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNull('payment_allocations.reversed_at')
            ->groupBy('payments.id', 'payments.amount_cents')
            ->havingRaw('SUM(payment_allocations.amount_cents) > payments.amount_cents')
            ->pluck('payments.id');
        foreach ($overallocated as $id) {
            $violations[] = ['classification' => 'payment_overallocated', 'source_type' => 'payment', 'source_id' => (int) $id];
        }

        $oversettled = DB::table('supplier_settlement_allocations')
            ->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_settlement_allocations.supplier_invoice_id')
            ->whereNull('supplier_settlement_allocations.reversed_at')
            ->groupBy('supplier_invoices.id', 'supplier_invoices.total_cents', 'supplier_invoices.credited_cents')
            ->havingRaw('SUM(supplier_settlement_allocations.amount_cents) > supplier_invoices.total_cents - supplier_invoices.credited_cents')
            ->pluck('supplier_invoices.id');
        foreach ($oversettled as $id) {
            $violations[] = ['classification' => 'supplier_invoice_oversettled', 'source_type' => 'supplier_invoice', 'source_id' => (int) $id];
        }

        $books = AccountingBook::query()
            ->whereHas('automation', fn ($q) => $q->where('status', 'active'))
            ->when($filters['organization'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['residence'] ?? null, fn ($q, $id) => $q->where('residence_id', $id))
            ->get();
        foreach ($books as $book) {
            $readiness = $this->automation->readiness($book, $book->automation->effective_from->toDateString());
            foreach ($readiness['issues'] as $issue) {
                $violations[] = [
                    'classification' => 'activation_readiness_regression',
                    'accounting_book_id' => $book->id,
                    'detail' => $issue,
                ];
            }
            $violations = array_merge($violations, $this->missingFinalizedSources($book, $filters));
        }

        $counts = collect($violations)->countBy('classification')->sortKeys()->all();

        return [
            'ok' => $violations === [],
            'filters' => $filters,
            'checked' => ['source_postings' => $registries->count(), 'active_books' => $books->count()],
            'violation_count' => count($violations),
            'classifications' => $counts,
            'violations' => $violations,
        ];
    }

    private function missingFinalizedSources(AccountingBook $book, array $filters): array
    {
        $activation = $book->automation;
        $definitions = [
            ['fund_call', 'fund_calls', 'issue_date', ['validated', 'closed'], 'validated', 'residence_id'],
            ['payment', 'payments', 'payment_date', ['validated'], 'received', 'residence_id'],
            ['supplier_settlement', 'supplier_settlements', 'settlement_date', ['validated'], 'validated', 'residence_id'],
            ['supplier_credit_note', 'supplier_credit_notes', 'credit_date', ['validated'], 'validated', 'residence_id'],
        ];
        $violations = [];
        foreach ($definitions as [$type, $table, $date, $statuses, $event, $residenceColumn]) {
            if (($filters['source_domain'] ?? null) && $filters['source_domain'] !== $type) {
                continue;
            }
            $ids = DB::table($table)
                ->where('organization_id', $book->organization_id)
                ->where($residenceColumn, $book->residence_id)
                ->whereIn('status', $statuses)
                ->whereDate($date, '>=', $activation->effective_from)
                ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate($date, '>=', $from))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate($date, '<=', $to))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('accounting_source_postings as asp')
                    ->whereColumn('asp.source_id', "{$table}.id")
                    ->where('asp.accounting_book_id', $book->id)
                    ->where('asp.source_type', $type)
                    ->where('asp.source_event', $event)
                    ->whereIn('asp.status', ['posted', 'reversed']))
                ->pluck('id');
            foreach ($ids as $id) {
                $violations[] = ['classification' => 'finalized_source_missing_posting', 'source_type' => $type, 'source_id' => (int) $id];
            }
        }
        if (! ($filters['source_domain'] ?? null) || $filters['source_domain'] === 'supplier_invoice') {
            $ids = DB::table('supplier_invoices')
                ->join('supplier_invoice_lines', 'supplier_invoice_lines.supplier_invoice_id', '=', 'supplier_invoices.id')
                ->where('supplier_invoices.organization_id', $book->organization_id)
                ->where('supplier_invoice_lines.residence_id', $book->residence_id)
                ->whereIn('supplier_invoices.status', ['validated', 'partial', 'paid'])
                ->whereDate('supplier_invoices.invoice_date', '>=', $activation->effective_from)
                ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('supplier_invoices.invoice_date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('supplier_invoices.invoice_date', '<=', $to))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('accounting_source_postings as asp')
                    ->whereColumn('asp.source_id', 'supplier_invoices.id')
                    ->where('asp.accounting_book_id', $book->id)
                    ->where('asp.source_type', 'supplier_invoice')
                    ->where('asp.source_event', 'validated')
                    ->whereIn('asp.status', ['posted', 'reversed']))
                ->distinct()
                ->pluck('supplier_invoices.id');
            foreach ($ids as $id) {
                $violations[] = ['classification' => 'finalized_source_missing_posting', 'source_type' => 'supplier_invoice', 'source_id' => (int) $id];
            }
        }

        return $violations;
    }

    private function expectedAmount(AccountingSourcePosting $registry): ?int
    {
        return match ($registry->source_type) {
            'fund_call' => DB::table('fund_calls')->where('id', $registry->source_id)->value('total_cents'),
            'payment' => DB::table('payments')->where('id', $registry->source_id)->value('amount_cents'),
            'payment_allocation' => DB::table('payment_allocations')->where('id', $registry->source_id)->value('amount_cents'),
            'supplier_invoice' => DB::table('supplier_invoice_lines')->where('supplier_invoice_id', $registry->source_id)->where('residence_id', $registry->residence_id)->sum('total_cents'),
            'supplier_credit_note' => DB::table('supplier_credit_notes')->where('id', $registry->source_id)->value('amount_cents'),
            'supplier_settlement' => DB::table('supplier_settlements')->where('id', $registry->source_id)->value('amount_cents'),
            default => null,
        };
    }

    private function sourceTable(string $type): ?string
    {
        return match ($type) {
            'fund_call' => 'fund_calls',
            'payment' => 'payments',
            'payment_allocation' => 'payment_allocations',
            'supplier_invoice' => 'supplier_invoices',
            'supplier_credit_note' => 'supplier_credit_notes',
            'supplier_settlement' => 'supplier_settlements',
            default => null,
        };
    }

    private function violation(string $classification, AccountingSourcePosting $registry): array
    {
        return [
            'classification' => $classification,
            'registry_id' => $registry->id,
            'source_type' => $registry->source_type,
            'source_id' => (int) $registry->source_id,
        ];
    }
}
