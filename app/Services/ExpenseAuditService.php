<?php

namespace App\Services;

use App\Models\FinancialDocument;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseAuditService
{
    public function __construct(private FinancialDocumentChecksumService $checksums) {}

    public function run(array $filters = []): array
    {
        $violations = [];
        $invoices = SupplierInvoice::query()->with(['lines.exercise', 'lines.category', 'supplier']);
        $this->scope($invoices, $filters, 'primary_residence_id');
        if (! empty($filters['exercise'])) {
            $invoices->whereHas('lines', fn ($query) => $query->where('financial_exercise_id', $filters['exercise']));
        }
        if (! empty($filters['invoice'])) {
            $invoices->whereKey($filters['invoice']);
        }
        if (! empty($filters['supplier'])) {
            $invoices->where('supplier_id', $filters['supplier']);
        }
        foreach ($invoices->get() as $invoice) {
            $subtotal = (int) $invoice->lines->sum('subtotal_cents');
            $tax = (int) $invoice->lines->sum('tax_cents');
            $total = (int) $invoice->lines->sum('total_cents');
            if ($subtotal !== $invoice->subtotal_cents || $tax !== $invoice->tax_cents || $total !== $invoice->total_cents || $subtotal + $tax !== $total) {
                $violations[] = $this->v('invoice_totals', $invoice->id, 'Header and line totals differ');
            }
            if ($invoice->paid_cents < 0 || $invoice->credited_cents < 0 || $invoice->paid_cents + $invoice->credited_cents > $invoice->total_cents) {
                $violations[] = $this->v('invoice_payable', $invoice->id, 'Paid or credited amount exceeds total');
            }
            $paid = (int) $invoice->settlementAllocations()->whereNull('reversed_at')->sum('amount_cents');
            $credited = (int) $invoice->creditAllocations()->whereNull('reversed_at')->sum('amount_cents');
            if ($paid !== $invoice->paid_cents || $credited !== $invoice->credited_cents) {
                $violations[] = $this->v('invoice_payable', $invoice->id, 'Stored payable counters differ from active allocations');
            }
            $outstanding = (int) $invoice->total_cents - $paid - $credited;
            if ($paid < 0 || $credited < 0 || $outstanding < 0 || $invoice->outstanding_cents !== $outstanding) {
                $violations[] = $this->v('invoice_payable', $invoice->id, 'Combined active allocations do not reconcile to a non-negative payable');
            }
            if (in_array($invoice->status, ['validated', 'partial', 'paid'], true)) {
                $expected = $outstanding === 0 ? 'paid' : (($paid + $credited) > 0 ? 'partial' : 'validated');
                if ($invoice->status !== $expected) {
                    $violations[] = $this->v('invoice_status', $invoice->id, "Stored status {$invoice->status} should be {$expected}");
                }
            }
            if (! in_array($invoice->status, ['draft', 'validated', 'partial', 'paid', 'cancelled'], true)) {
                $violations[] = $this->v('invalid_status', $invoice->id, "Invalid invoice status {$invoice->status}");
            }
            if ($invoice->status !== 'draft') {
                $original = $invoice->attachments()->where('kind', 'original')->first();
                if (! $original || ! Storage::disk($original->disk)->exists($original->path)) {
                    $violations[] = $this->v('missing_document', $invoice->id, 'Original supplier invoice is missing');
                } elseif (! $this->checksums->matches($original->checksum, Storage::disk($original->disk)->get($original->path))) {
                    $violations[] = $this->v('corrupt_checksum', $invoice->id, 'Original supplier invoice checksum mismatch');
                }
            }
            foreach ($invoice->lines as $line) {
                if ($line->residence_id !== $line->exercise->residence_id || $line->residence_id !== $line->category->residence_id || $invoice->organization_id !== $invoice->supplier->organization_id) {
                    $violations[] = $this->v('cross_tenant', $invoice->id, "Invalid line relationship {$line->id}");
                }
                if ($invoice->validated_at && $line->exercise->status === 'closed' && $invoice->validated_at->toDateString() > $line->exercise->closed_at?->toDateString()) {
                    $violations[] = $this->v('closed_exercise', $invoice->id, 'Validated after exercise closure');
                }
                $linePaid = (int) $invoice->settlementAllocations()->where('supplier_invoice_line_id', $line->id)->whereNull('reversed_at')->sum('amount_cents');
                $lineCredited = (int) $invoice->creditAllocations()->where('supplier_invoice_line_id', $line->id)->whereNull('reversed_at')->sum('amount_cents');
                if ($linePaid < 0 || $lineCredited < 0 || $linePaid + $lineCredited > $line->total_cents) {
                    $violations[] = $this->v('invoice_line_payable', $line->id, 'Line allocations exceed the validated line total');
                }
            }
        }
        $duplicates = SupplierInvoice::query()->whereNotNull('number')->whereIn('status', ['validated', 'partial', 'paid'])->groupBy('organization_id', 'number')->havingRaw('COUNT(*) > 1')->selectRaw('organization_id, number, COUNT(*) count')->get();
        foreach ($duplicates as $duplicate) {
            $violations[] = ['check' => 'duplicate_number', 'record' => $duplicate->number, 'detail' => "{$duplicate->count} invoices"];
        }
        $settlements = SupplierSettlement::query()->with(['allocations.invoice', 'allocations.line', 'movements']);
        $this->scope($settlements, $filters);
        if (! empty($filters['exercise'])) {
            $settlements->where('financial_exercise_id', $filters['exercise']);
        }
        if (! empty($filters['settlement'])) {
            $settlements->whereKey($filters['settlement']);
        }
        if (! empty($filters['supplier'])) {
            $settlements->where('supplier_id', $filters['supplier']);
        }
        foreach ($settlements->get() as $settlement) {
            $active = (int) $settlement->allocations->whereNull('reversed_at')->sum('amount_cents');
            if ($settlement->status === 'validated' && $active !== $settlement->amount_cents) {
                $violations[] = $this->v('settlement_allocations', $settlement->id, 'Active allocations do not equal settlement');
            }
            $expectedKind = $settlement->status === 'reversed' ? ['supplier_settlement', 'supplier_settlement_reversal'] : ($settlement->status === 'validated' ? ['supplier_settlement'] : []);
            if ($settlement->movements->pluck('operational_kind')->sort()->values()->all() !== collect($expectedKind)->sort()->values()->all()) {
                $violations[] = $this->v('settlement_movements', $settlement->id, 'Movement set does not match state');
            }
            $original = $settlement->movements->firstWhere('operational_kind', 'supplier_settlement');
            $reversal = $settlement->movements->firstWhere('operational_kind', 'supplier_settlement_reversal');
            if ($original && ($original->direction !== 'debit' || $original->amount_cents !== $settlement->amount_cents)) {
                $violations[] = $this->v('settlement_movements', $settlement->id, 'Outgoing movement amount or direction is invalid');
            }
            if ($reversal && ($reversal->direction !== 'credit' || $reversal->amount_cents !== $settlement->amount_cents || $reversal->reversal_of_id !== $original?->id)) {
                $violations[] = $this->v('reversal_consistency', $settlement->id, 'Compensating movement is invalid');
            }
            if (! in_array($settlement->status, ['draft', 'validated', 'reversed'], true)) {
                $violations[] = $this->v('invalid_status', $settlement->id, "Invalid settlement status {$settlement->status}");
            }
            foreach ($settlement->allocations as $allocation) {
                if (! $allocation->supplier_invoice_line_id || ! $allocation->line
                    || $allocation->invoice->supplier_id !== $settlement->supplier_id
                    || $allocation->line->supplier_invoice_id !== $allocation->supplier_invoice_id
                    || $allocation->line->residence_id !== $settlement->residence_id) {
                    $violations[] = $this->v('cross_tenant', $settlement->id, "Invalid settlement allocation {$allocation->id}");
                }
            }
        }
        $documents = FinancialDocument::query()->where('type', 'supplier_voucher');
        $this->scope($documents, $filters);
        foreach ($documents->get() as $document) {
            if (! Storage::disk($document->disk)->exists($document->path)) {
                $violations[] = $this->v('missing_document', $document->id, 'Voucher file missing');
            } elseif (! $this->checksums->matches($document->checksum, $bytes = Storage::disk($document->disk)->get($document->path))) {
                $violations[] = $this->v('corrupt_checksum', $document->id, 'Voucher checksum mismatch', [
                    'organization_id' => $document->organization_id,
                    'residence_id' => $document->residence_id,
                    'settlement_id' => $document->subject_id,
                    'document_number' => $document->number,
                    'stored_checksum' => $document->checksum,
                    'calculated_checksum' => $this->checksums->checksum($bytes),
                    'checksum_version' => $document->checksum_version ?: FinancialDocumentChecksumService::VERSION,
                    'generated_at' => $document->generated_at?->toIso8601String(),
                    'created_at' => $document->created_at?->toIso8601String(),
                    'updated_at' => $document->updated_at?->toIso8601String(),
                ]);
            }
        }
        $overCredits = DB::table('supplier_credit_note_allocations')->whereNull('reversed_at')->groupBy('supplier_credit_note_id')->havingRaw('SUM(amount_cents) > (SELECT amount_cents FROM supplier_credit_notes WHERE supplier_credit_notes.id = supplier_credit_note_allocations.supplier_credit_note_id)')->pluck('supplier_credit_note_id');
        foreach ($overCredits as $id) {
            $violations[] = $this->v('credit_allocations', $id, 'Credit allocations exceed credit note');
        }
        $overCommitments = DB::table('expense_commitments as c')->join('supplier_invoices as i', 'i.expense_commitment_id', '=', 'c.id')->whereIn('i.status', ['validated', 'partial', 'paid'])->groupBy('c.id', 'c.amount_cents')->havingRaw('SUM(i.total_cents) > c.amount_cents')->selectRaw('c.id')->pluck('id');
        foreach ($overCommitments as $id) {
            $violations[] = $this->v('commitment_total', $id, 'Validated invoices exceed approved commitment');
        }
        $badBudgets = DB::table('budget_lines as bl')->join('budgets as b', 'b.id', '=', 'bl.budget_id')->join('expense_categories as ec', 'ec.id', '=', 'bl.expense_category_id')->whereColumn('b.organization_id', '!=', 'ec.organization_id')->pluck('b.id');
        foreach ($badBudgets as $id) {
            $violations[] = $this->v('budget_actual', $id, 'Budget category crosses tenant boundary');
        }

        return ['ok' => $violations === [], 'checked' => ['invoices' => $invoices->count(), 'settlements' => $settlements->count(), 'vouchers' => $documents->count()], 'violations' => $violations];
    }

    private function scope(Builder $query, array $filters, string $residenceColumn = 'residence_id'): void
    {
        if (! empty($filters['organization'])) {
            $query->where('organization_id', $filters['organization']);
        }
        if (! empty($filters['residence'])) {
            $query->where($residenceColumn, $filters['residence']);
        }
    }

    private function v(string $check, int|string $record, string $detail, array $evidence = []): array
    {
        return compact('check', 'record', 'detail', 'evidence');
    }
}
