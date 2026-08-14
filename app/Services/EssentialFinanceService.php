<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\LotCharge;
use App\Models\Residence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class EssentialFinanceService
{
    public function period(?string $period): array
    {
        $value = preg_match('/^\d{4}-\d{2}$/', (string) $period) ? $period : now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();

        return ['value' => $value, 'start' => $start->toDateString(), 'end' => $start->endOfMonth()->toDateString()];
    }

    public function periodRange(?string $from, ?string $to, ?string $legacyPeriod = null): array
    {
        $fallback = $this->period($legacyPeriod)['value'];
        $fromValue = preg_match('/^\d{4}-\d{2}$/', (string) $from) ? $from : $fallback;
        $toValue = preg_match('/^\d{4}-\d{2}$/', (string) $to) ? $to : $fallback;
        $normalized = $fromValue > $toValue;

        if ($normalized) {
            [$fromValue, $toValue] = [$toValue, $fromValue];
        }

        $fromPeriod = $this->period($fromValue);
        $toPeriod = $this->period($toValue);

        return [
            'from' => $fromPeriod,
            'to' => $toPeriod,
            'start' => $fromPeriod['start'],
            'end' => $toPeriod['end'],
            'normalized' => $normalized,
        ];
    }

    public function chargesInRange(Residence $residence, array $range): Builder
    {
        return LotCharge::query()
            ->where('organization_id', $residence->organization_id)
            ->where('residence_id', $residence->id)
            ->whereNull('cancelled_at')
            ->whereBetween('issue_date', [$range['start'], $range['end']])
            ->withSum(['allocations as paid_cents' => fn ($query) => $query->whereNull('reversed_at')], 'amount_cents');
    }

    public function charges(Residence $residence, Request $request): Builder
    {
        $period = $this->period($request->string('period')->toString());

        return LotCharge::query()
            ->where('organization_id', $residence->organization_id)
            ->where('residence_id', $residence->id)
            ->whereNull('cancelled_at')
            ->whereBetween('issue_date', [$period['start'], $period['end']])
            ->with([
                'lot:id,residence_id,building_id,reference,lot_number',
                'lot.building:id,name',
                'lot.ownerships' => fn ($query) => $query
                    ->whereDate('starts_on', '<=', today())
                    ->where(fn ($ownerships) => $ownerships->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
                    ->with('contact:id,type,first_name,last_name,company_name')
                    ->orderByDesc('is_primary_contact'),
                'billedContact:id,type,first_name,last_name,company_name',
            ])
            ->withSum(['allocations as paid_cents' => fn ($query) => $query->whereNull('reversed_at')], 'amount_cents')
            ->when($request->integer('building_id'), fn ($query, $id) => $query->whereHas('lot', fn ($lots) => $lots->where('building_id', $id)))
            ->when($request->filled('status'), function ($query) use ($request) {
                $allocated = '(SELECT COALESCE(SUM(payment_allocations.amount_cents),0) FROM payment_allocations WHERE payment_allocations.lot_charge_id = lot_charges.id AND payment_allocations.reversed_at IS NULL)';
                match ($request->string('status')->toString()) {
                    'paid' => $query->whereRaw("$allocated >= lot_charges.amount_cents"),
                    'partial' => $query->whereRaw("$allocated > 0 AND $allocated < lot_charges.amount_cents"),
                    'unpaid' => $query->whereRaw("$allocated = 0"),
                    default => null,
                };
            });
    }

    public function chargeRow(LotCharge $charge): array
    {
        $paid = (int) ($charge->paid_cents ?? $charge->allocated_cents);
        $remaining = max(0, (int) $charge->amount_cents - $paid);
        $activeOwner = $charge->lot?->ownerships->first()?->contact;
        $billingContact = $charge->billedContact ?: $activeOwner;

        return [
            'id' => $charge->id,
            'lot_id' => $charge->lot_id,
            'lot' => $charge->lot?->reference ?: $charge->lot?->lot_number,
            'building' => $charge->lot?->building?->name,
            'resident' => $billingContact?->display_name,
            'resident_id' => $billingContact?->id,
            'can_record_payment' => (bool) $billingContact,
            'period' => $charge->issue_date?->format('Y-m'),
            'expected_cents' => (int) $charge->amount_cents,
            'paid_cents' => $paid,
            'remaining_cents' => $remaining,
            'status' => $remaining === 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ];
    }

    public function balances(Residence $residence, ?string $asOf = null): array
    {
        return FinancialAccount::query()->where('organization_id', $residence->organization_id)->where('residence_id', $residence->id)->where('active', true)
            ->get()->groupBy('type')->map(fn ($accounts) => (int) $accounts->sum(function (FinancialAccount $account) use ($asOf) {
                if (! $asOf) {
                    return (int) $account->current_balance_cents;
                }

                $opening = ! $account->opening_balance_on || $account->opening_balance_on->toDateString() <= $asOf
                    ? (int) $account->opening_balance_cents
                    : 0;
                $net = (int) $account->movements()->whereDate('occurred_on', '<=', $asOf)
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_cents ELSE -amount_cents END), 0) AS net")
                    ->value('net');

                return $opening + $net;
            }))
            ->only(['bank', 'cash'])->all() + ['bank' => 0, 'cash' => 0];
    }
}
