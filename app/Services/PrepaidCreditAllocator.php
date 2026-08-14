<?php

namespace App\Services;

use App\Models\FundCall;
use App\Models\Payment;
use App\Models\User;

class PrepaidCreditAllocator
{
    public function __construct(private PaymentWorkflow $payments) {}

    public function applyToFundCall(FundCall $call, User $actor): void
    {
        $charges = $call->charges()->whereNull('cancelled_at')->orderBy('due_date')->orderBy('id')->get();

        foreach ($charges as $charge) {
            $remaining = $charge->outstanding_cents;
            if ($remaining <= 0) {
                continue;
            }

            $credits = Payment::query()
                ->where('organization_id', $call->organization_id)
                ->where('residence_id', $call->residence_id)
                ->where('status', 'validated')
                ->whereNotNull('payer_contact_id')
                ->where('metadata->advance_lot_id', $charge->lot_id)
                ->orderBy('payment_date')
                ->orderBy('id')
                ->get();

            foreach ($credits as $payment) {
                $available = $payment->credit_cents;
                if ($available <= 0) {
                    continue;
                }

                $amount = min($remaining, $available);
                $this->payments->allocateCreditAutomatically($payment, $actor, [[
                    'lot_charge_id' => $charge->id,
                    'amount_cents' => $amount,
                    'allocated_on' => $charge->issue_date->toDateString(),
                ]]);
                $remaining -= $amount;

                if ($remaining <= 0) {
                    break;
                }
            }
        }
    }
}
