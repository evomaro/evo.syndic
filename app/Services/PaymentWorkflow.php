<?php

namespace App\Services;

use App\Models\FinancialAccountMovement;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\LotCharge;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PaymentWorkflow
{
    public function __construct(
        private DocumentNumberService $numbers,
        private ReceiptService $receipts,
        private AutomatedAccountingPostingService $accounting,
    ) {}

    public function validate(Payment $payment, User $actor, string $mode = 'fifo', array $lotIds = [], array $manual = []): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $mode, $lotIds, $manual) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->with(['residence', 'exercise', 'account'])->firstOrFail();
            if ($payment->status === 'validated') {
                $this->accounting->postPayment($payment, $actor);
                $this->receipts->generate($payment, $actor);

                return $payment->fresh(['allocations', 'documents']);
            }
            if ($payment->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('Le paiement ne peut plus être validé.')]);
            }
            if ($payment->exercise->status !== 'open') {
                throw ValidationException::withMessages(['exercise' => __('L’exercice financier doit être ouvert.')]);
            }
            if ($payment->payment_date->lt($payment->exercise->starts_on) || $payment->payment_date->gt($payment->exercise->ends_on)) {
                throw ValidationException::withMessages(['payment_date' => __('La date doit appartenir à l’exercice.')]);
            }
            if (! $payment->account->active) {
                throw ValidationException::withMessages(['financial_account_id' => __('Le compte financier est inactif.')]);
            }

            $remaining = (int) $payment->amount_cents;
            $order = 1;
            if (in_array($mode, ['manual', 'manual_then_fifo'], true)) {
                foreach ($manual as $row) {
                    $amount = (int) $row['amount_cents'];
                    $remaining = $this->allocate($payment, (int) $row['lot_charge_id'], $amount, $remaining, $actor, $order++, $payment->payment_date->toDateString());
                }
            }
            // An unidentified receipt is never auto-applied. Staff may identify it later,
            // or make an explicit manual allocation while recording the receipt.
            if ($mode !== 'manual' && $payment->payer_contact_id) {
                $charges = LotCharge::query()->where('organization_id', $payment->organization_id)->where('residence_id', $payment->residence_id)
                    ->whereIn('status', ['unpaid', 'partial'])->whereNull('cancelled_at');
                if ($mode === 'selected_lots') {
                    $charges->whereIn('lot_id', $lotIds);
                }
                foreach ($charges->orderBy('due_date')->orderBy('issue_date')->orderBy(FundCall::select('number')->whereColumn('fund_calls.id', 'lot_charges.fund_call_id'))->orderBy('id')->lockForUpdate()->get() as $charge) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $outstanding = (int) $charge->amount_cents - (int) $charge->allocations()->whereNull('reversed_at')->sum('amount_cents');
                    if ($outstanding > 0) {
                        $remaining = $this->allocate($payment, $charge->id, min($remaining, $outstanding), $remaining, $actor, $order++, $payment->payment_date->toDateString());
                    }
                }
            }
            $this->assertPaymentInvariant($payment);
            $number = $this->numbers->next($payment->residence, 'PAY', (int) $payment->payment_date->format('Y'));
            FinancialAccountMovement::create(['organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id, 'financial_account_id' => $payment->financial_account_id, 'financial_exercise_id' => $payment->financial_exercise_id, 'payment_id' => $payment->id, 'direction' => 'credit', 'operational_kind' => 'payment_receipt', 'amount_cents' => $payment->amount_cents, 'occurred_on' => $payment->payment_date, 'source_type' => Payment::class, 'source_id' => $payment->id, 'description' => "Paiement {$number}", 'created_by' => $actor->id]);
            $payment->update(['number' => $number]);
            $this->receipts->generate($payment, $actor);
            $payment->update(['status' => 'validated', 'validated_at' => now(), 'validated_by' => $actor->id]);
            $this->accounting->postPayment($payment->fresh('allocations'), $actor);
            activity()->performedOn($payment)->causedBy($actor)->withProperties(['organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id, 'from' => 'draft', 'to' => 'validated', 'allocated_cents' => $payment->amount_cents - $remaining, 'credit_cents' => $remaining])->log('payment.validated');

            return $payment->fresh(['allocations', 'documents']);
        }, 5);
    }

    public function allocateCredit(Payment $payment, User $actor, array $manual): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $manual) {
            $payment = Payment::query()->whereKey($payment->id)->with(['residence.organization', 'documents'])->lockForUpdate()->firstOrFail();
            abort_unless($actor->canInOrganization('allocate_credit', $payment->residence->organization), 403);
            if ($payment->status !== 'validated') {
                throw ValidationException::withMessages(['status' => __('Le paiement doit être valide.')]);
            }
            if (! $payment->payer_contact_id) {
                throw ValidationException::withMessages(['payer_contact_id' => __('Le payeur doit être identifié avant d’utiliser un crédit disponible.')]);
            }
            $this->assertRecoverablePayment($payment);
            $remaining = $payment->credit_cents;
            $order = (int) $payment->allocations()->max('allocation_order') + 1;
            $lastAllocationId = (int) $payment->allocations()->max('id');
            foreach ($manual as $row) {
                $remaining = $this->allocate($payment, (int) $row['lot_charge_id'], (int) $row['amount_cents'], $remaining, $actor, $order++, today()->toDateString(), true);
            }
            $this->assertPaymentInvariant($payment);
            $payment->allocations()->where('id', '>', $lastAllocationId)->orderBy('id')->get()
                ->each(fn (PaymentAllocation $allocation) => $this->accounting->postPaymentAllocation($allocation, $actor));
            activity()->performedOn($payment)->causedBy($actor)->withProperties(['organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id, 'remaining_credit_cents' => $remaining])->log('payment.credit_allocated');

            return $payment->fresh('allocations');
        }, 5);
    }

    public function identifyPayer(Payment $payment, User $actor, int $contactId): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $contactId) {
            $payment = Payment::query()->whereKey($payment->id)->with('residence.organization')->lockForUpdate()->firstOrFail();
            abort_unless($actor->canInOrganization('allocate_credit', $payment->residence->organization), 403);
            if ($payment->status !== 'validated' || $payment->payer_contact_id) {
                throw ValidationException::withMessages(['payer_contact_id' => __('Ce paiement ne peut pas être identifié.')]);
            }
            $this->assertRecoverablePayment($payment);
            $contact = $payment->residence->organization->contacts()->whereKey($contactId)->lockForUpdate()->firstOrFail();
            $payment->update(['payer_contact_id' => $contact->id]);
            activity()->performedOn($payment)->causedBy($actor)->withProperties([
                'organization_id' => $payment->organization_id,
                'residence_id' => $payment->residence_id,
                'contact_id' => $contact->id,
                'received_from' => $payment->received_from,
            ])->log('payment.payer_identified');

            return $payment->fresh('payer');
        }, 5);
    }

    public function reverse(Payment $payment, User $actor, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'validated') {
                throw ValidationException::withMessages(['status' => __('Seul un paiement valide peut être extourné.')]);
            }
            $movementExercise = FinancialExercise::query()->whereKey($payment->financial_exercise_id)->lockForUpdate()->firstOrFail();
            if ($movementExercise->status !== 'open') {
                $movementExercise = FinancialExercise::query()->where('residence_id', $payment->residence_id)->where('status', 'open')->lockForUpdate()->first();
            }
            if (! $movementExercise) {
                throw ValidationException::withMessages(['exercise' => __('L’exercice financier doit être ouvert.')]);
            }
            $allocations = $payment->allocations()->whereNull('reversed_at')->lockForUpdate()->get();
            $charges = LotCharge::query()->whereIn('id', $allocations->pluck('lot_charge_id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($allocations as $allocation) {
                $allocation->update(['reversed_at' => now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason, 'restored_charge_cents' => $allocation->amount_cents]);
            }
            foreach ($charges as $charge) {
                $this->refreshCharge($charge);
            }
            $original = FinancialAccountMovement::query()->where('source_type', Payment::class)->where('source_id', $payment->id)->whereNull('reversal_of_id')->lockForUpdate()->firstOrFail();
            FinancialAccountMovement::create(['organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id, 'financial_account_id' => $payment->financial_account_id, 'financial_exercise_id' => $movementExercise->id, 'payment_id' => $payment->id, 'direction' => 'debit', 'operational_kind' => 'payment_reversal', 'amount_cents' => $payment->amount_cents, 'occurred_on' => now()->toDateString(), 'source_type' => Payment::class, 'source_id' => $payment->id, 'description' => "Extourne {$payment->number}", 'reversal_of_id' => $original->id, 'created_by' => $actor->id]);
            $payment->documents()->where('type', 'receipt')->update(['status' => 'reversed']);
            $payment->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason]);
            $this->accounting->reversePayment($payment, $actor, $reason);
            activity()->performedOn($payment)->causedBy($actor)->withProperties(['organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id, 'reason' => $reason])->log('payment.reversed');

            return $payment;
        }, 5);
    }

    private function allocate(Payment $payment, int $chargeId, int $amount, int $remaining, User $actor, int $order, string $allocatedOn, bool $advanceCredit = false): int
    {
        if ($amount <= 0 || $amount > $remaining) {
            throw ValidationException::withMessages(['allocations' => __('Montant d’affectation invalide.')]);
        }
        $charge = LotCharge::query()->whereKey($chargeId)->where('organization_id', $payment->organization_id)->where('residence_id', $payment->residence_id)->whereNull('cancelled_at')->with('exercise')->lockForUpdate()->firstOrFail();
        if ($advanceCredit) {
            if ($charge->exercise->status !== 'open') {
                throw ValidationException::withMessages(['exercise' => __('L’exercice de la charge doit être ouvert.')]);
            }
            $isPayerOwner = $charge->lot->ownerships()->where('contact_id', $payment->payer_contact_id)
                ->whereDate('starts_on', '<=', $charge->issue_date)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $charge->issue_date))->exists();
            if (! $isPayerOwner) {
                throw ValidationException::withMessages(['allocations' => __('Ce crédit ne peut être affecté qu’aux charges d’un lot détenu par son payeur à la date d’émission.')]);
            }
        }
        $outstanding = (int) $charge->amount_cents - (int) $charge->allocations()->whereNull('reversed_at')->sum('amount_cents');
        if ($amount > $outstanding) {
            throw ValidationException::withMessages(['allocations' => __('L’affectation dépasse le solde de la charge.')]);
        }
        PaymentAllocation::create(['payment_id' => $payment->id, 'lot_charge_id' => $charge->id, 'lot_id' => $charge->lot_id, 'amount_cents' => $amount, 'allocation_order' => $order, 'allocated_on' => $allocatedOn, 'allocated_by' => $actor->id]);
        $this->refreshCharge($charge);

        return $remaining - $amount;
    }

    private function refreshCharge(LotCharge $charge): void
    {
        $allocated = (int) $charge->allocations()->whereNull('reversed_at')->sum('amount_cents');
        $charge->update(['status' => $allocated <= 0 ? 'unpaid' : ($allocated >= $charge->amount_cents ? 'paid' : 'partial')]);
    }

    private function assertPaymentInvariant(Payment $payment): void
    {
        $allocated = (int) $payment->allocations()->whereNull('reversed_at')->sum('amount_cents');
        if ($allocated < 0 || $allocated > (int) $payment->amount_cents) {
            throw ValidationException::withMessages(['allocations' => __('Les affectations du paiement sont incohérentes.')]);
        }
    }

    private function assertRecoverablePayment(Payment $payment): void
    {
        $this->assertPaymentInvariant($payment);
        $originals = $payment->movements()->where('operational_kind', 'payment_receipt')->get();
        $document = $payment->documents->where('type', 'receipt')->where('version', 1)->first();
        if ($originals->count() !== 1 || (int) $originals->sum('amount_cents') !== (int) $payment->amount_cents
            || ! $document || ! Storage::disk($document->disk)->exists($document->path)) {
            throw ValidationException::withMessages(['payment' => __('Le paiement doit être rapproché avant toute affectation de crédit.')]);
        }
    }
}
