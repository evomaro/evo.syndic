<?php

namespace App\Services;

use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditNoteWorkflow
{
    public function __construct(
        private OrganizationDocumentNumberService $numbers,
        private SupplierPayableService $payables,
    ) {}

    public function validate(SupplierCreditNote $credit, User $actor, array $allocations): SupplierCreditNote
    {
        return DB::transaction(function () use ($credit, $actor, $allocations) {
            $credit = SupplierCreditNote::query()->whereKey($credit->id)->with('residence.organization')->lockForUpdate()->firstOrFail();
            if ($credit->status === 'validated') {
                return $credit->fresh('allocations.line');
            }
            if ($credit->status !== 'draft' || $credit->amount_cents <= 0) {
                throw ValidationException::withMessages(['status' => __('Avoir invalide.')]);
            }
            $rows = collect($allocations);
            $invoices = collect();
            foreach ($rows->pluck('supplier_invoice_id')->map(fn ($id) => (int) $id)->unique()->sort() as $invoiceId) {
                $invoice = $this->payables->lockInvoice($invoiceId);
                if ($invoice->organization_id !== $credit->organization_id || $invoice->supplier_id !== $credit->supplier_id || ! in_array($invoice->status, ['validated', 'partial'], true)) {
                    throw ValidationException::withMessages(['allocations' => __('Facture non éligible à cet avoir.')]);
                }
                $invoices->put($invoiceId, $invoice);
            }

            $remaining = (int) $credit->amount_cents;
            $touched = collect();
            foreach ($rows as $row) {
                $invoice = $invoices->get((int) $row['supplier_invoice_id']);
                $requested = min((int) $row['amount_cents'], $remaining);
                $this->payables->assertAmountAvailable($invoice, $credit->residence_id, $requested);
                $lines = $this->payables->eligibleLines($invoice, $credit->residence_id);
                if (! empty($row['supplier_invoice_line_id'])) {
                    $lines = $lines->where('id', (int) $row['supplier_invoice_line_id'])->values();
                }
                if ($lines->isEmpty()) {
                    throw ValidationException::withMessages(['allocations' => __('Aucune ligne éligible pour cette résidence.')]);
                }
                $unallocated = $requested;
                foreach ($lines as $line) {
                    if ($unallocated <= 0) {
                        break;
                    }
                    $chunk = min($unallocated, $this->payables->lineRemaining($line, true));
                    if ($chunk <= 0) {
                        continue;
                    }
                    SupplierCreditNoteAllocation::create([
                        'supplier_credit_note_id' => $credit->id,
                        'supplier_invoice_id' => $invoice->id,
                        'supplier_invoice_line_id' => $line->id,
                        'residence_id' => $line->residence_id,
                        'financial_exercise_id' => $line->financial_exercise_id,
                        'expense_category_id' => $line->expense_category_id,
                        'amount_cents' => $chunk,
                    ]);
                    $unallocated -= $chunk;
                }
                if ($unallocated !== 0) {
                    throw ValidationException::withMessages(['allocations' => __('L’avoir dépasse le solde éligible des lignes.')]);
                }
                $remaining -= $requested;
                $touched->put($invoice->id, $invoice);
            }
            if ($remaining !== 0) {
                throw ValidationException::withMessages(['allocations' => __('L’avoir doit être intégralement affecté. Les crédits fournisseurs non affectés ne sont pas activés.')]);
            }
            foreach ($touched as $invoice) {
                $this->payables->recalculate($invoice);
            }
            $number = $this->numbers->next($credit->residence->organization, 'CRN', (int) $credit->credit_date->format('Y'));
            $credit->update([
                'number' => $number,
                'status' => 'validated',
                'validated_at' => now(),
                'validated_by' => $actor->id,
                'validation_snapshot' => [
                    'number' => $number,
                    'amount_cents' => (int) $credit->amount_cents,
                    'supplier_id' => $credit->supplier_id,
                    'allocations' => $credit->allocations()->get(['supplier_invoice_id', 'supplier_invoice_line_id', 'residence_id', 'amount_cents'])->toArray(),
                ],
            ]);
            activity()->performedOn($credit)->causedBy($actor)->withProperties(['organization_id' => $credit->organization_id, 'residence_id' => $credit->residence_id, 'amount_cents' => $credit->amount_cents])->log('supplier_credit.validated');

            return $credit->fresh('allocations.line');
        });
    }

    public function cancel(SupplierCreditNote $credit, User $actor, string $reason): SupplierCreditNote
    {
        return DB::transaction(function () use ($credit, $actor, $reason) {
            $credit = SupplierCreditNote::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            if ($credit->status !== 'validated') {
                throw ValidationException::withMessages(['status' => __('Seul un avoir valide peut être annulé.')]);
            }
            $allocations = $credit->allocations()->whereNull('reversed_at')->orderBy('supplier_invoice_id')->lockForUpdate()->get();
            $invoices = collect();
            foreach ($allocations->pluck('supplier_invoice_id')->unique()->sort() as $invoiceId) {
                $invoices->put($invoiceId, $this->payables->lockInvoice((int) $invoiceId));
            }
            foreach ($allocations as $allocation) {
                $allocation->update(['reversed_at' => now(), 'reversed_by' => $actor->id]);
            }
            foreach ($invoices as $invoice) {
                $this->payables->recalculate($invoice);
            }
            $credit->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]);
            activity()->performedOn($credit)->causedBy($actor)->withProperties(['organization_id' => $credit->organization_id, 'residence_id' => $credit->residence_id, 'reason' => $reason])->log('supplier_credit.cancelled');

            return $credit;
        });
    }
}
