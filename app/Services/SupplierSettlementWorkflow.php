<?php

namespace App\Services;

use App\Models\FinancialAccountMovement;
use App\Models\FinancialExercise;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use App\Models\SupplierSettlementAllocation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierSettlementWorkflow
{
    public function __construct(
        private DocumentNumberService $numbers,
        private VoucherService $vouchers,
        private SupplierPayableService $payables,
        private AutomatedAccountingPostingService $accounting,
    ) {}

    public function validate(SupplierSettlement $settlement, User $actor, string $mode = 'fifo', array $manual = []): SupplierSettlement
    {
        $validated = DB::transaction(function () use ($settlement, $actor, $mode, $manual) {
            $settlement = SupplierSettlement::query()->whereKey($settlement->id)->with(['residence', 'exercise', 'account'])->lockForUpdate()->firstOrFail();
            if ($settlement->status === 'validated') {
                return $settlement->fresh(['allocations.line', 'documents']);
            }
            if ($settlement->status !== 'draft' || $settlement->amount_cents <= 0) {
                throw ValidationException::withMessages(['status' => __('Règlement invalide.')]);
            }
            if ($settlement->exercise->status !== 'open' || $settlement->settlement_date->lt($settlement->exercise->starts_on) || $settlement->settlement_date->gt($settlement->exercise->ends_on)) {
                throw ValidationException::withMessages(['exercise' => __('Le règlement doit appartenir à un exercice ouvert.')]);
            }
            if (! $settlement->account->active || $settlement->account->residence_id !== $settlement->residence_id) {
                throw ValidationException::withMessages(['financial_account_id' => __('Compte financier invalide.')]);
            }

            $rows = $mode === 'manual' ? collect($manual) : $this->fifoRows($settlement);
            $invoiceIds = $rows->pluck('supplier_invoice_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $invoices = collect();
            foreach ($invoiceIds as $invoiceId) {
                $invoice = $this->payables->lockInvoice($invoiceId);
                $this->assertEligibleInvoice($invoice, $settlement);
                $invoices->put($invoiceId, $invoice);
            }

            $remaining = (int) $settlement->amount_cents;
            $order = 1;
            $touched = collect();
            foreach ($rows as $row) {
                if ($remaining <= 0) {
                    break;
                }
                $invoice = $invoices->get((int) $row['supplier_invoice_id']);
                if (! $invoice) {
                    throw ValidationException::withMessages(['allocations' => __('Facture de règlement introuvable.')]);
                }
                $this->payables->recalculate($invoice);
                $requested = min((int) ($row['amount_cents'] ?? $remaining), $remaining);
                $this->payables->assertAmountAvailable($invoice, $settlement->residence_id, $requested);
                $lines = $this->eligibleLinesForRow($invoice, $settlement->residence_id, $row);
                $unallocated = $requested;
                foreach ($lines as $line) {
                    if ($unallocated <= 0) {
                        break;
                    }
                    $chunk = min($unallocated, $this->payables->lineRemaining($line, true));
                    if ($chunk <= 0) {
                        continue;
                    }
                    SupplierSettlementAllocation::create([
                        'supplier_settlement_id' => $settlement->id,
                        'supplier_invoice_id' => $invoice->id,
                        'supplier_invoice_line_id' => $line->id,
                        'amount_cents' => $chunk,
                        'allocation_order' => $order++,
                        'allocated_on' => $settlement->settlement_date,
                        'allocated_by' => $actor->id,
                    ]);
                    $unallocated -= $chunk;
                }
                if ($unallocated !== 0) {
                    throw ValidationException::withMessages(['allocations' => __('Le solde des lignes de cette résidence est insuffisant.')]);
                }
                $remaining -= $requested;
                $touched->put($invoice->id, $invoice);
            }
            if ($remaining !== 0) {
                throw ValidationException::withMessages(['amount_cents' => __('Le règlement doit être intégralement affecté aux factures du fournisseur.')]);
            }
            foreach ($touched as $invoice) {
                $this->payables->recalculate($invoice);
            }

            $number = $this->numbers->next($settlement->residence, 'SET', (int) $settlement->settlement_date->format('Y'));
            FinancialAccountMovement::create([
                'organization_id' => $settlement->organization_id,
                'residence_id' => $settlement->residence_id,
                'financial_account_id' => $settlement->financial_account_id,
                'financial_exercise_id' => $settlement->financial_exercise_id,
                'supplier_settlement_id' => $settlement->id,
                'direction' => 'debit',
                'operational_kind' => 'supplier_settlement',
                'amount_cents' => $settlement->amount_cents,
                'occurred_on' => $settlement->settlement_date,
                'source_type' => SupplierSettlement::class,
                'source_id' => $settlement->id,
                'description' => "Règlement fournisseur {$number}",
                'created_by' => $actor->id,
            ]);
            $settlement->update(['number' => $number, 'status' => 'validated', 'validated_at' => now(), 'validated_by' => $actor->id]);
            $this->accounting->postSupplierSettlement($settlement->fresh('allocations'), $actor);
            activity()->performedOn($settlement)->causedBy($actor)->withProperties(['organization_id' => $settlement->organization_id, 'residence_id' => $settlement->residence_id, 'amount_cents' => $settlement->amount_cents])->log('supplier_settlement.validated');

            return $settlement->fresh(['allocations.line', 'documents']);
        }, 5);

        $this->vouchers->generate($validated, $actor);

        return $validated->fresh(['allocations.line', 'documents']);
    }

    public function reverse(SupplierSettlement $settlement, User $actor, string $reason): SupplierSettlement
    {
        return DB::transaction(function () use ($settlement, $actor, $reason) {
            $settlement = SupplierSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'validated') {
                throw ValidationException::withMessages(['status' => __('Seul un règlement valide peut être extourné.')]);
            }
            $exercise = FinancialExercise::query()->where('residence_id', $settlement->residence_id)->where('status', 'open')->lockForUpdate()->first();
            if (! $exercise) {
                throw ValidationException::withMessages(['exercise' => __('Un exercice ouvert est requis pour l’extourne. Le mouvement correctif est daté dans cet exercice.')]);
            }
            $allocations = $settlement->allocations()->whereNull('reversed_at')->orderBy('supplier_invoice_id')->orderBy('allocation_order')->lockForUpdate()->get();
            $invoices = collect();
            foreach ($allocations->pluck('supplier_invoice_id')->unique()->sort() as $invoiceId) {
                $invoices->put($invoiceId, $this->payables->lockInvoice((int) $invoiceId));
            }
            foreach ($allocations as $allocation) {
                $allocation->update(['reversed_at' => now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason]);
            }
            foreach ($invoices as $invoice) {
                $this->payables->recalculate($invoice);
            }

            $original = FinancialAccountMovement::query()->where('supplier_settlement_id', $settlement->id)->where('operational_kind', 'supplier_settlement')->lockForUpdate()->firstOrFail();
            FinancialAccountMovement::create([
                'organization_id' => $settlement->organization_id,
                'residence_id' => $settlement->residence_id,
                'financial_account_id' => $settlement->financial_account_id,
                'financial_exercise_id' => $exercise->id,
                'supplier_settlement_id' => $settlement->id,
                'direction' => 'credit',
                'operational_kind' => 'supplier_settlement_reversal',
                'amount_cents' => $settlement->amount_cents,
                'occurred_on' => today(),
                'source_type' => SupplierSettlement::class,
                'source_id' => $settlement->id,
                'description' => "Extourne {$settlement->number}",
                'reversal_of_id' => $original->id,
                'created_by' => $actor->id,
            ]);
            $settlement->documents()->update(['status' => 'reversed']);
            $settlement->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason]);
            $this->accounting->reverse('supplier_settlement', $settlement->id, $actor, $reason);
            activity()->performedOn($settlement)->causedBy($actor)->withProperties(['organization_id' => $settlement->organization_id, 'residence_id' => $settlement->residence_id, 'reason' => $reason])->log('supplier_settlement.reversed');

            return $settlement->fresh(['allocations.line', 'documents']);
        }, 5);
    }

    private function fifoRows(SupplierSettlement $settlement): Collection
    {
        $remaining = (int) $settlement->amount_cents;

        return SupplierInvoice::query()
            ->where('organization_id', $settlement->organization_id)
            ->where('supplier_id', $settlement->supplier_id)
            ->whereIn('status', ['validated', 'partial'])
            ->whereHas('lines', fn ($query) => $query->where('residence_id', $settlement->residence_id))
            ->orderBy('due_date')->orderBy('invoice_date')->orderBy('number')->orderBy('id')
            ->get()
            ->map(function (SupplierInvoice $invoice) use (&$remaining, $settlement) {
                $amount = min($remaining, $this->payables->residenceOutstanding($invoice, $settlement->residence_id));
                $remaining -= $amount;

                return ['supplier_invoice_id' => $invoice->id, 'amount_cents' => $amount];
            })->filter(fn ($row) => $row['amount_cents'] > 0)->values();
    }

    private function assertEligibleInvoice(SupplierInvoice $invoice, SupplierSettlement $settlement): void
    {
        if ($invoice->organization_id !== $settlement->organization_id || $invoice->supplier_id !== $settlement->supplier_id || ! in_array($invoice->status, ['validated', 'partial'], true) || ! $invoice->lines->contains('residence_id', $settlement->residence_id)) {
            throw ValidationException::withMessages(['allocations' => __('La facture ne peut pas être réglée depuis cette résidence.')]);
        }
    }

    private function eligibleLinesForRow(SupplierInvoice $invoice, int $residenceId, array $row): Collection
    {
        $lines = $this->payables->eligibleLines($invoice, $residenceId);
        if (! empty($row['supplier_invoice_line_id'])) {
            $lines = $lines->where('id', (int) $row['supplier_invoice_line_id'])->values();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['allocations' => __('La ligne de facture ne correspond pas à cette résidence.')]);
            }
        }

        return $lines;
    }
}
