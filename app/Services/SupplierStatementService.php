<?php

namespace App\Services;

use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SupplierStatementService
{
    public function build(Supplier $supplier, int $residenceId, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
        $toDate = $to ? CarbonImmutable::parse($to)->endOfDay() : null;
        $events = collect();
        $invoices = DB::table('supplier_invoices as i')->join('supplier_invoice_lines as il', 'il.supplier_invoice_id', '=', 'i.id')
            ->where('i.supplier_id', $supplier->id)->where('il.residence_id', $residenceId)->whereIn('i.status', ['validated', 'partial', 'paid'])
            ->groupBy('i.id', 'i.invoice_date', 'i.number', 'i.supplier_invoice_number')
            ->selectRaw('i.id, i.invoice_date, i.number, i.supplier_invoice_number, SUM(il.total_cents) residence_total_cents');
        $credits = DB::table('supplier_credit_notes as cn')->join('supplier_credit_note_allocations as ca', 'ca.supplier_credit_note_id', '=', 'cn.id')
            ->where('cn.supplier_id', $supplier->id)->where('ca.residence_id', $residenceId)->where('cn.status', 'validated')->whereNull('ca.reversed_at')
            ->groupBy('cn.id', 'cn.credit_date', 'cn.number', 'cn.supplier_credit_number')
            ->selectRaw('cn.id, cn.credit_date, cn.number, cn.supplier_credit_number, SUM(ca.amount_cents) residence_amount_cents');
        $settlements = DB::table('supplier_settlements')->where('supplier_id', $supplier->id)->where('residence_id', $residenceId)->whereIn('status', ['validated', 'reversed']);
        foreach ($invoices->get() as $row) {
            $events->push(['date' => $row->invoice_date, 'order' => 1, 'type' => 'invoice', 'number' => $row->number, 'label' => $row->supplier_invoice_number ?: 'Facture fournisseur', 'debit_cents' => (int) $row->residence_total_cents, 'credit_cents' => 0]);
        }
        foreach ($credits->get() as $row) {
            $events->push(['date' => $row->credit_date, 'order' => 2, 'type' => 'credit_note', 'number' => $row->number, 'label' => $row->supplier_credit_number ?: 'Avoir fournisseur', 'debit_cents' => 0, 'credit_cents' => (int) $row->residence_amount_cents]);
        }
        foreach ($settlements->get() as $row) {
            $events->push(['date' => $row->settlement_date, 'order' => 3, 'type' => 'settlement', 'number' => $row->number, 'label' => 'Règlement', 'debit_cents' => 0, 'credit_cents' => (int) $row->amount_cents]);
            if ($row->status === 'reversed') {
                $events->push(['date' => substr($row->reversed_at, 0, 10), 'order' => 4, 'type' => 'settlement_reversal', 'number' => $row->number, 'label' => 'Extourne règlement', 'debit_cents' => (int) $row->amount_cents, 'credit_cents' => 0]);
            }
        }
        $events = $events->sortBy(fn ($row) => sprintf('%s-%02d-%s', $row['date'], $row['order'], $row['number']))->values();
        $opening = $fromDate ? $events->filter(fn ($row) => CarbonImmutable::parse($row['date'])->lt($fromDate))->sum(fn ($row) => $row['debit_cents'] - $row['credit_cents']) : 0;
        $visible = $events->filter(fn ($row) => (! $fromDate || CarbonImmutable::parse($row['date'])->gte($fromDate)) && (! $toDate || CarbonImmutable::parse($row['date'])->lte($toDate)))->values();
        $balance = (int) $opening;
        $rows = $visible->map(function ($row) use (&$balance) {
            $balance += $row['debit_cents'] - $row['credit_cents'];

            return $row + ['balance_cents' => $balance];
        });

        return ['supplier' => $supplier, 'residence_id' => $residenceId, 'from' => $from, 'to' => $to, 'opening_cents' => (int) $opening, 'rows' => $rows, 'closing_cents' => $balance];
    }
}
