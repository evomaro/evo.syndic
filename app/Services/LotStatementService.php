<?php

namespace App\Services;

use App\Models\LotCharge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LotStatementService
{
    public function build(int $residenceId, int $lotId, ?string $from = null, ?string $to = null): array
    {
        $all = $this->allRows($residenceId, $lotId);
        $opening = $from ? $all->filter(fn ($row) => $row['date'] < $from)->sum(fn ($row) => $row['debit_cents'] - $row['credit_cents']) : 0;
        $balance = $opening;
        $rows = $all->when($from, fn ($rows) => $rows->where('date', '>=', $from))->when($to, fn ($rows) => $rows->where('date', '<=', $to))->values()
            ->map(function ($row) use (&$balance) {
                $balance += $row['debit_cents'] - $row['credit_cents'];
                $row['balance_cents'] = $balance;

                return $row;
            });

        return ['opening_balance_cents' => $opening, 'closing_balance_cents' => $balance, 'transactions' => $rows];
    }

    private function allRows(int $residenceId, int $lotId): Collection
    {
        $charges = LotCharge::query()->where('residence_id', $residenceId)->where('lot_id', $lotId)->whereNull('cancelled_at')->with('fundCall')->get()->map(fn ($charge) => [
            'sort_id' => $charge->id, 'sort_type' => 1, 'date' => $charge->issue_date->toDateString(), 'due_date' => $charge->due_date->toDateString(),
            'type' => 'charge', 'reference' => $charge->fundCall->number, 'label' => $charge->validation_snapshot['line_label'] ?? $charge->fundCall->title,
            'debit_cents' => (int) $charge->amount_cents, 'credit_cents' => 0,
        ]);
        $allocations = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.residence_id', $residenceId)->where('payment_allocations.lot_id', $lotId)
            ->select(['payment_allocations.id', DB::raw('COALESCE(payment_allocations.allocated_on, payments.payment_date) as date'), 'payments.number as reference', 'payment_allocations.amount_cents', 'payment_allocations.reversed_at'])->get()
            ->flatMap(fn ($allocation) => $allocation->reversed_at ? [[
                'sort_id' => $allocation->id, 'sort_type' => 2, 'date' => $allocation->date, 'due_date' => null, 'type' => 'payment', 'reference' => $allocation->reference,
                'label' => __('Paiement affecté'), 'debit_cents' => 0, 'credit_cents' => (int) $allocation->amount_cents,
            ], [
                'sort_id' => $allocation->id, 'sort_type' => 3, 'date' => substr($allocation->reversed_at, 0, 10), 'due_date' => null, 'type' => 'payment_reversal', 'reference' => $allocation->reference,
                'label' => __('Extourne de paiement'), 'debit_cents' => (int) $allocation->amount_cents, 'credit_cents' => 0,
            ]] : [[
                'sort_id' => $allocation->id, 'sort_type' => 2, 'date' => $allocation->date, 'due_date' => null, 'type' => 'payment', 'reference' => $allocation->reference,
                'label' => __('Paiement affecté'), 'debit_cents' => 0, 'credit_cents' => (int) $allocation->amount_cents,
            ]]);

        return $charges->concat($allocations)->sortBy([['date', 'asc'], ['sort_type', 'asc'], ['reference', 'asc'], ['sort_id', 'asc']])->values();
    }
}
