<?php

namespace App\Services;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SupplierPayableService
{
    public function lockInvoice(int $invoiceId): SupplierInvoice
    {
        $invoice = SupplierInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        $lines = $invoice->lines()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
        $lines->each(fn (SupplierInvoiceLine $line) => $line->setRelation('invoice', $invoice));
        $invoice->setRelation('lines', $lines);

        return $invoice;
    }

    public function recalculate(SupplierInvoice $invoice): SupplierInvoice
    {
        $paid = (int) $invoice->settlementAllocations()->whereNull('reversed_at')->lockForUpdate()->get(['amount_cents'])->sum('amount_cents');
        $credited = (int) $invoice->creditAllocations()->whereNull('reversed_at')->lockForUpdate()->get(['amount_cents'])->sum('amount_cents');
        if ($paid < 0 || $credited < 0 || $paid + $credited > (int) $invoice->total_cents) {
            throw ValidationException::withMessages(['allocations' => __('Les affectations dépassent le montant de la facture.')]);
        }
        $status = $invoice->status;
        if (! in_array($status, ['draft', 'cancelled'], true)) {
            $status = $paid + $credited === (int) $invoice->total_cents ? 'paid' : ($paid + $credited > 0 ? 'partial' : 'validated');
        }
        $invoice->update(['paid_cents' => $paid, 'credited_cents' => $credited, 'status' => $status]);

        return $invoice->fresh();
    }

    public function lineRemaining(SupplierInvoiceLine $line, bool $lock = false): int
    {
        $settlements = $line->invoice->settlementAllocations()->where('supplier_invoice_line_id', $line->id)->whereNull('reversed_at');
        $credits = $line->invoice->creditAllocations()->where('supplier_invoice_line_id', $line->id)->whereNull('reversed_at');
        $settled = (int) ($lock ? $settlements->lockForUpdate()->get(['amount_cents'])->sum('amount_cents') : $settlements->sum('amount_cents'));
        $credited = (int) ($lock ? $credits->lockForUpdate()->get(['amount_cents'])->sum('amount_cents') : $credits->sum('amount_cents'));

        return max(0, (int) $line->total_cents - $settled - $credited);
    }

    public function residenceOutstanding(SupplierInvoice $invoice, int $residenceId): int
    {
        return $this->eligibleLines($invoice, $residenceId)->sum(fn (SupplierInvoiceLine $line) => $this->lineRemaining($line));
    }

    public function eligibleLines(SupplierInvoice $invoice, int $residenceId): Collection
    {
        $lines = $invoice->relationLoaded('lines') ? $invoice->lines : $invoice->lines()->orderBy('sort_order')->orderBy('id')->get();

        return $lines->where('residence_id', $residenceId)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
    }

    public function assertAmountAvailable(SupplierInvoice $invoice, int $residenceId, int $amount): void
    {
        $invoice = $this->recalculate($invoice);
        if ($amount <= 0 || $amount > $invoice->outstanding_cents || $amount > $this->eligibleLines($invoice, $residenceId)->sum(fn (SupplierInvoiceLine $line) => $this->lineRemaining($line, true))) {
            throw ValidationException::withMessages(['allocations' => __('Affectation supérieure au solde disponible.')]);
        }
    }
}
