<?php

namespace App\Services;

use App\Models\SupplierInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OverdueSupplierInvoiceNotificationService
{
    public function __construct(private ManagerNotificationService $notifications) {}

    public function dispatch(CarbonImmutable $date, array $filters = [], bool $apply = false): array
    {
        $query = SupplierInvoice::query()->whereIn('status', ['validated', 'partial'])->whereDate('due_date', '<', $date)->with(['organization', 'residence']);
        if ($filters['organization'] ?? null) {
            $query->where('organization_id', $filters['organization']);
        }
        if ($filters['residence'] ?? null) {
            $query->whereHas('lines', fn ($lines) => $lines->where('residence_id', $filters['residence']));
        }
        $events = 0;
        $deliveries = 0;
        foreach ($query->get() as $invoice) {
            $gross = (int) $invoice->lines()->sum('total_cents');
            $paid = (int) DB::table('supplier_settlement_allocations')->where('supplier_invoice_id', $invoice->id)->whereNull('reversed_at')->sum('amount_cents');
            $credited = (int) DB::table('supplier_credit_note_allocations')->where('supplier_invoice_id', $invoice->id)->whereNull('reversed_at')->sum('amount_cents');
            if ($gross - $paid - $credited <= 0) {
                continue;
            }
            $days = (int) $invoice->due_date->diffInDays($date);
            $stage = $days >= 90 ? '90' : ($days >= 60 ? '60' : ($days >= 30 ? '30' : ($days >= 7 ? '7' : 'new')));
            $events++;
            $deliveries += $this->notifications->dispatch($invoice->organization, $invoice->residence, 'overdue_supplier_invoice', "invoice:{$invoice->id}:overdue:{$stage}:{$invoice->due_date->toDateString()}", [
                'title' => 'Facture fournisseur échue',
                'message' => 'La facture :number est échue depuis :days jour(s).',
                'parameters' => ['number' => $invoice->number, 'days' => $days],
                'data' => ['invoice_id' => $invoice->id, 'stage' => $stage, 'due_date' => $invoice->due_date->toDateString()],
            ], route('supplier-invoices.show', $invoice), $apply);
        }

        return compact('events', 'deliveries');
    }
}
