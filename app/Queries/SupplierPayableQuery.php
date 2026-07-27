<?php

namespace App\Queries;

use App\Models\SupplierInvoice;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SupplierPayableQuery
{
    public function get(Request $request, TenantContext $context)
    {
        $residenceId = $context->residence()->id;
        $query = SupplierInvoice::query()->where('organization_id', $context->organization()->id)->whereIn('status', ['validated', 'partial'])
            ->whereHas('lines', fn ($lines) => $lines->where('residence_id', $residenceId))
            ->with(['supplier:id,legal_name', 'lines' => fn ($lines) => $lines->where('residence_id', $residenceId), 'settlementAllocations' => fn ($rows) => $rows->whereNull('reversed_at')->whereHas('line', fn ($line) => $line->where('residence_id', $residenceId)), 'creditAllocations' => fn ($rows) => $rows->whereNull('reversed_at')->where('residence_id', $residenceId)])
            ->orderBy('due_date');
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        return $query->get()->map(function (SupplierInvoice $invoice) {
            $days = $invoice->due_date->isFuture() || $invoice->due_date->isToday() ? 0 : (int) $invoice->due_date->diffInDays(today());
            $total = (int) $invoice->lines->sum('total_cents');
            $paid = (int) $invoice->settlementAllocations->sum('amount_cents');
            $credited = (int) $invoice->creditAllocations->sum('amount_cents');

            return ['id' => $invoice->id, 'number' => $invoice->number, 'supplier' => $invoice->supplier->legal_name, 'invoice_date' => $invoice->invoice_date->toDateString(), 'due_date' => $invoice->due_date->toDateString(), 'total_cents' => $total, 'paid_cents' => $paid, 'credited_cents' => $credited, 'outstanding_cents' => max(0, $total - $paid - $credited), 'aging' => $days === 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '>90')))];
        })->where('outstanding_cents', '>', 0)->values();
    }
}
