<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialExercise;
use App\Models\FinancialTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialTransferService
{
    public function create(array $data, int $organizationId, int $residenceId, User $actor): FinancialTransfer
    {
        return DB::transaction(function () use ($data, $organizationId, $residenceId, $actor) {
            $accounts = FinancialAccount::query()->whereIn('id', [$data['source_account_id'], $data['destination_account_id']])
                ->where('organization_id', $organizationId)->where('residence_id', $residenceId)->where('active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $accounts->get((int) $data['source_account_id']);
            $destination = $accounts->get((int) $data['destination_account_id']);
            if (! $source || ! $destination || $source->is($destination) || $source->type === $destination->type || ! in_array($source->type, ['bank', 'cash'], true) || ! in_array($destination->type, ['bank', 'cash'], true)) {
                throw ValidationException::withMessages(['destination_account_id' => __('Choisissez deux comptes Banque et Caisse distincts de cette résidence.')]);
            }
            $exercise = FinancialExercise::query()->whereKey($data['financial_exercise_id'])->where('organization_id', $organizationId)->where('residence_id', $residenceId)->where('status', 'open')->lockForUpdate()->firstOrFail();
            if ($data['transferred_on'] < $exercise->starts_on->toDateString() || $data['transferred_on'] > $exercise->ends_on->toDateString()) {
                throw ValidationException::withMessages(['transferred_on' => __('La date doit appartenir à l’exercice ouvert.')]);
            }
            if ($source->current_balance_cents < (int) $data['amount_cents']) {
                throw ValidationException::withMessages(['amount' => __('Le solde du compte source est insuffisant.')]);
            }

            $key = $data['idempotency_key'] ?? null;
            if ($key && ($existing = FinancialTransfer::query()->where('residence_id', $residenceId)->where('idempotency_key', $key)->first())) {
                return $existing;
            }
            $transfer = FinancialTransfer::create($data + ['organization_id' => $organizationId, 'residence_id' => $residenceId, 'created_by' => $actor->id]);
            foreach ([[$source, 'debit'], [$destination, 'credit']] as [$account, $direction]) {
                FinancialAccountMovement::create([
                    'organization_id' => $organizationId, 'residence_id' => $residenceId,
                    'financial_account_id' => $account->id, 'financial_exercise_id' => $exercise->id,
                    'financial_transfer_id' => $transfer->id, 'direction' => $direction,
                    'operational_kind' => 'account_transfer', 'amount_cents' => $transfer->amount_cents,
                    'occurred_on' => $transfer->transferred_on, 'source_type' => FinancialTransfer::class,
                    'source_id' => $transfer->id, 'description' => __('Transfert :source vers :destination', ['source' => $source->name, 'destination' => $destination->name]),
                    'created_by' => $actor->id,
                ]);
            }
            activity()->performedOn($transfer)->causedBy($actor)->withProperties(['organization_id' => $organizationId, 'residence_id' => $residenceId, 'amount_cents' => $transfer->amount_cents])->log('financial_transfer.created');

            return $transfer->fresh('movements');
        }, 5);
    }
}
