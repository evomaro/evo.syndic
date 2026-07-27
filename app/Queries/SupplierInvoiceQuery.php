<?php

namespace App\Queries;

use App\Models\SupplierInvoice;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SupplierInvoiceQuery
{
    public function paginate(Request $request, TenantContext $context)
    {
        $residenceId = $context->residence()->id;
        $query = SupplierInvoice::query()->where('organization_id', $context->organization()->id)
            ->whereHas('lines', fn ($lines) => $lines->where('residence_id', $residenceId));
        if ($term = trim((string) $request->query('q'))) {
            $query->where(fn ($row) => $row->where('number', 'like', "%{$term}%")->orWhere('supplier_invoice_number', 'like', "%{$term}%")->orWhereHas('supplier', fn ($supplier) => $supplier->where('legal_name', 'like', "%{$term}%")));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $page = $query->with($this->scopedRelations($residenceId))->latest('invoice_date')->paginate(20)->withQueryString();
        $page->through(fn (SupplierInvoice $invoice) => $this->residenceView($invoice));

        return $page;
    }

    public function search(string $term, TenantContext $context)
    {
        return SupplierInvoice::query()->where('organization_id', $context->organization()->id)
            ->whereHas('lines', fn ($lines) => $lines->where('residence_id', $context->residence()->id))
            ->where(fn ($query) => $query->where('number', 'like', "%{$term}%")->orWhere('supplier_invoice_number', 'like', "%{$term}%"))
            ->orderByDesc('invoice_date')->simplePaginate(15, ['id', 'number', 'supplier_invoice_number', 'status', 'total_cents'])->withQueryString();
    }

    public function openForSupplier(int $supplierId, TenantContext $context): Collection
    {
        $residenceId = $context->residence()->id;

        return SupplierInvoice::query()
            ->where('organization_id', $context->organization()->id)
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['validated', 'partial'])
            ->whereHas('lines', fn ($lines) => $lines->where('residence_id', $residenceId))
            ->with($this->scopedRelations($residenceId))
            ->orderBy('due_date')
            ->orderBy('invoice_date')
            ->get()
            ->map(function (SupplierInvoice $invoice) {
                $invoice = $this->residenceView($invoice);
                $invoice->setAttribute('outstanding_cents', max(0, (int) $invoice->total_cents - (int) $invoice->paid_cents - (int) $invoice->credited_cents));

                return $invoice->only(['id', 'number', 'supplier_invoice_number', 'due_date', 'status', 'outstanding_cents']);
            })
            ->filter(fn (array $invoice) => $invoice['outstanding_cents'] > 0)
            ->values();
    }

    public function show(SupplierInvoice $invoice, int $residenceId): SupplierInvoice
    {
        return $this->residenceView($invoice->load(array_merge($this->scopedRelations($residenceId), ['attachments'])));
    }

    private function scopedRelations(int $residenceId): array
    {
        return ['supplier:id,legal_name', 'lines' => fn ($lines) => $lines->where('residence_id', $residenceId)->with('category:id,name'), 'settlementAllocations' => fn ($rows) => $rows->whereNull('reversed_at')->whereHas('line', fn ($line) => $line->where('residence_id', $residenceId)), 'creditAllocations' => fn ($rows) => $rows->whereNull('reversed_at')->where('residence_id', $residenceId)];
    }

    private function residenceView(SupplierInvoice $invoice): SupplierInvoice
    {
        $invoice->setAttribute('total_cents', (int) $invoice->lines->sum('total_cents'));
        $invoice->setAttribute('subtotal_cents', (int) $invoice->lines->sum('subtotal_cents'));
        $invoice->setAttribute('tax_cents', (int) $invoice->lines->sum('tax_cents'));
        $invoice->setAttribute('paid_cents', (int) $invoice->settlementAllocations->sum('amount_cents'));
        $invoice->setAttribute('credited_cents', (int) $invoice->creditAllocations->sum('amount_cents'));

        return $invoice->makeHidden(['notes', 'validation_snapshot']);
    }
}
