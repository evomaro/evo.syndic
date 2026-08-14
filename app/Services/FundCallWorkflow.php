<?php

namespace App\Services;

use App\Models\FundCall;
use App\Models\LotCharge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FundCallWorkflow
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FundCallDistributionService $distribution,
        private AutomatedAccountingPostingService $accounting,
        private PrepaidCreditAllocator $prepaidCredits,
    ) {}

    public function preview(FundCall $call): array
    {
        $this->assertDraft($call);

        return $call->lines()->with(['fundCall', 'allocationKey.values'])->get()->map(fn ($line) => [
            'line_id' => $line->id,
            'label' => $line->label,
            'total_cents' => collect($this->distribution->distribute($line))->sum('amount_cents'),
            'allocations' => collect($this->distribution->distribute($line))->map(fn ($row) => ['lot_id' => $row['lot']->id, 'lot' => $row['lot']->reference, 'amount_cents' => $row['amount_cents']])->all(),
        ])->all();
    }

    public function validate(FundCall $call, User $actor): FundCall
    {
        return DB::transaction(function () use ($call, $actor) {
            $call = FundCall::query()->whereKey($call->id)->lockForUpdate()->with(['residence', 'exercise', 'lines.category', 'lines.allocationKey.values'])->firstOrFail();
            if ($call->status === 'validated') {
                $this->accounting->postFundCall($call, $actor);

                return $call->fresh(['charges']);
            }
            $this->assertDraft($call);
            if ($call->exercise->status !== 'open') {
                throw ValidationException::withMessages(['exercise' => __('L’exercice financier doit être ouvert.')]);
            }
            if ($call->issue_date->lt($call->exercise->starts_on) || $call->issue_date->gt($call->exercise->ends_on)) {
                throw ValidationException::withMessages(['issue_date' => __('La date doit appartenir à l’exercice.')]);
            }
            if ($call->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => __('Ajoutez au moins une ligne.')]);
            }

            $total = 0;
            foreach ($call->lines as $line) {
                $rows = $this->distribution->distribute($line);
                foreach ($rows as $row) {
                    $lot = $row['lot'];
                    $owner = $lot->activeOwnerships($call->issue_date->toDateString())->with('contact')->orderByDesc('is_primary_contact')->orderBy('id')->first();
                    LotCharge::create([
                        'organization_id' => $call->organization_id, 'residence_id' => $call->residence_id,
                        'financial_exercise_id' => $call->financial_exercise_id, 'fund_call_id' => $call->id,
                        'fund_call_line_id' => $line->id, 'lot_id' => $lot->id, 'billed_contact_id' => $owner?->contact_id,
                        'amount_cents' => $row['amount_cents'], 'issue_date' => $call->issue_date, 'due_date' => $call->due_date,
                        'lot_reference_snapshot' => $lot->reference, 'contact_name_snapshot' => $owner?->contact?->display_name,
                        'distribution_method_snapshot' => $line->distribution_method,
                        'distribution_value_snapshot' => $row['value'], 'distribution_total_snapshot' => $row['total'],
                        'validation_snapshot' => [
                            'target_type' => $line->target_type, 'target_ids' => $line->target_ids,
                            'targeted_lot_ids' => collect($rows)->pluck('lot.id')->all(), 'lot_reference' => $lot->reference,
                            'distribution_method' => $line->distribution_method,
                            'allocation_key' => $line->allocationKey ? ['id' => $line->allocationKey->id, 'name' => $line->allocationKey->name] : null,
                            'allocation_value' => $row['value'], 'selected_total_value' => $row['total'],
                            'ownership_recipient' => $owner ? ['ownership_id' => $owner->id, 'contact_id' => $owner->contact_id, 'name' => $owner->contact?->display_name] : null,
                            'category' => ['id' => $line->category->id, 'code' => $line->category->code, 'name' => $line->category->name],
                            'line_label' => $line->label, 'source_line_amount_cents' => (int) $line->amount_cents,
                            'distributed_amount_cents' => (int) $row['amount_cents'], 'rounding_adjustment_cents' => (int) $row['rounding_adjustment_cents'],
                            'authorized_override' => false, 'override_reason' => null,
                        ],
                    ]);
                    $total += $row['amount_cents'];
                }
            }
            $number = $this->numbers->next($call->residence, 'AF', (int) $call->issue_date->format('Y'));
            $call->update(['number' => $number, 'total_cents' => $total, 'status' => 'validated', 'validated_at' => now(), 'validated_by' => $actor->id]);
            $this->accounting->postFundCall($call->fresh(['charges.line']), $actor);
            $this->prepaidCredits->applyToFundCall($call->fresh('charges'), $actor);
            activity()->performedOn($call)->causedBy($actor)->withProperties(['organization_id' => $call->organization_id, 'residence_id' => $call->residence_id, 'from' => 'draft', 'to' => 'validated'])->log('fund_call.validated');

            return $call->fresh(['charges']);
        }, 5);
    }

    public function cancel(FundCall $call, User $actor, string $reason): FundCall
    {
        return DB::transaction(function () use ($call, $actor, $reason) {
            $call = FundCall::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
            if (! in_array($call->status, ['validated', 'closed'], true)) {
                throw ValidationException::withMessages(['status' => __('Seul un appel validé peut être annulé.')]);
            }
            if ($call->exercise()->value('status') !== 'open') {
                throw ValidationException::withMessages(['exercise' => __('L’exercice financier doit être ouvert.')]);
            }
            $charges = $call->charges()->lockForUpdate()->get();
            if ($charges->pluck('id')->isNotEmpty() && DB::table('payment_allocations')->whereIn('lot_charge_id', $charges->pluck('id'))->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['status' => __('Annulez ou réaffectez d’abord les paiements liés.')]);
            }
            LotCharge::whereIn('id', $charges->pluck('id'))->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $call->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]);
            $this->accounting->reverse('fund_call', $call->id, $actor, $reason);
            activity()->performedOn($call)->causedBy($actor)->withProperties(['organization_id' => $call->organization_id, 'residence_id' => $call->residence_id, 'reason' => $reason])->log('fund_call.cancelled');

            return $call;
        }, 5);
    }

    private function assertDraft(FundCall $call): void
    {
        if ($call->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('Un appel validé est immuable.')]);
        }
    }
}
