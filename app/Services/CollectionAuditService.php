<?php

namespace App\Services;

use App\Models\LotCharge;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CollectionAuditService
{
    public function __construct(private FinancialDocumentChecksumService $checksums) {}

    public function audit(array $filters = []): array
    {
        $violations = [];
        $payments = $this->paymentQuery($filters)->with(['allocations', 'movements', 'documents'])->get();
        $paymentIds = $payments->pluck('id');
        $charges = $this->chargeQuery($filters)->with('allocations')->get();

        foreach ($payments as $payment) {
            $allocated = (int) $payment->allocations->whereNull('reversed_at')->sum('amount_cents');
            if ($allocated > (int) $payment->amount_cents) {
                $violations[] = $this->issue('payment_overallocated', 'payment', $payment->id, ['amount_cents' => $payment->amount_cents, 'allocated_cents' => $allocated]);
            }
            if ($payment->status === 'validated' && (int) $payment->amount_cents !== $allocated + $payment->unallocated_cents) {
                $violations[] = $this->issue('payment_equation_failed', 'payment', $payment->id);
            }

            $originals = $payment->movements->where('operational_kind', 'payment_receipt');
            $reversals = $payment->movements->where('operational_kind', 'payment_reversal');
            if (in_array($payment->status, ['validated', 'reversed'], true) && ($originals->count() !== 1 || (int) $originals->sum('amount_cents') !== (int) $payment->amount_cents)) {
                $violations[] = $this->issue('movement_payment_mismatch', 'payment', $payment->id);
            }
            if ($payment->status === 'reversed' && ($reversals->count() !== 1 || (int) $reversals->sum('amount_cents') !== (int) $payment->amount_cents || $payment->allocations->whereNull('reversed_at')->isNotEmpty())) {
                $violations[] = $this->issue('reversal_inconsistent', 'payment', $payment->id);
            }
            if ($payment->status === 'validated' && $reversals->isNotEmpty()) {
                $violations[] = $this->issue('unexpected_reversal_movement', 'payment', $payment->id);
            }
            if ($payment->status === 'reversed' && $reversals->contains(fn ($movement) => ! $movement->reversal_of_id || ! $originals->contains('id', $movement->reversal_of_id))) {
                $violations[] = $this->issue('reversal_link_invalid', 'payment', $payment->id);
            }

            if (in_array($payment->status, ['validated', 'reversed'], true)) {
                $documents = $payment->documents->where('type', 'receipt')->where('version', 1);
                if ($documents->count() !== 1) {
                    $violations[] = $this->issue('receipt_count_invalid', 'payment', $payment->id, ['count' => $documents->count()]);
                } else {
                    $document = $documents->first();
                    if (! Storage::disk($document->disk)->exists($document->path)) {
                        $violations[] = $this->issue('receipt_file_missing', 'document', $document->id);
                    } elseif (! $this->checksums->matches($document->checksum, $bytes = Storage::disk($document->disk)->get($document->path))) {
                        $violations[] = $this->issue('receipt_checksum_invalid', 'document', $document->id, [
                            'organization_id' => $document->organization_id,
                            'residence_id' => $document->residence_id,
                            'payment_id' => $payment->id,
                            'payment_number' => $payment->number,
                            'document_number' => $document->number,
                            'stored_checksum' => $document->checksum,
                            'calculated_checksum' => $this->checksums->checksum($bytes),
                            'checksum_version' => $document->checksum_version ?: FinancialDocumentChecksumService::VERSION,
                            'generated_at' => $document->generated_at?->toIso8601String(),
                            'created_at' => $document->created_at?->toIso8601String(),
                            'updated_at' => $document->updated_at?->toIso8601String(),
                        ]);
                    }
                    if ($payment->status === 'reversed' && $document->status !== 'reversed') {
                        $violations[] = $this->issue('reversed_receipt_still_valid', 'document', $document->id);
                    }
                }
            }
        }

        foreach ($charges as $charge) {
            $allocated = (int) $charge->allocations->whereNull('reversed_at')->sum('amount_cents');
            if ($allocated > (int) $charge->amount_cents) {
                $violations[] = $this->issue('charge_overallocated', 'lot_charge', $charge->id, ['amount_cents' => $charge->amount_cents, 'allocated_cents' => $allocated]);
            }
        }

        $this->duplicateNumbers($violations, 'payments', $filters);
        $this->duplicateNumbers($violations, 'fund_calls', $filters);
        $this->duplicateNumbers($violations, 'financial_documents', $filters);
        $this->crossTenantViolations($violations, $paymentIds);

        return [
            'ok' => $violations === [], 'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'checked' => ['payments' => $payments->count(), 'charges' => $charges->count()], 'violations' => $violations,
        ];
    }

    private function paymentQuery(array $filters): Builder
    {
        return Payment::query()
            ->when($filters['residence'] ?? null, fn ($query, $id) => $query->where('residence_id', $id))
            ->when($filters['exercise'] ?? null, fn ($query, $id) => $query->where('financial_exercise_id', $id))
            ->when($filters['payment'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['fund_call'] ?? null, fn ($query, $id) => $query->whereHas('allocations.charge', fn ($charge) => $charge->where('fund_call_id', $id)));
    }

    private function chargeQuery(array $filters): Builder
    {
        return LotCharge::query()
            ->when($filters['residence'] ?? null, fn ($query, $id) => $query->where('residence_id', $id))
            ->when($filters['exercise'] ?? null, fn ($query, $id) => $query->where('financial_exercise_id', $id))
            ->when($filters['fund_call'] ?? null, fn ($query, $id) => $query->where('fund_call_id', $id))
            ->when($filters['payment'] ?? null, fn ($query, $id) => $query->whereHas('allocations', fn ($allocation) => $allocation->where('payment_id', $id)));
    }

    private function duplicateNumbers(array &$violations, string $table, array $filters): void
    {
        $query = DB::table($table)->whereNotNull('number');
        if ($filters['residence'] ?? null) {
            $query->where('residence_id', $filters['residence']);
        }
        foreach ($query->select(['residence_id', 'number'])->groupBy('residence_id', 'number')->havingRaw('COUNT(*) > 1')->get() as $duplicate) {
            $violations[] = $this->issue('duplicate_number', $table, $duplicate->number, ['residence_id' => $duplicate->residence_id]);
        }
    }

    private function crossTenantViolations(array &$violations, $paymentIds): void
    {
        $allocationCount = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->join('lot_charges', 'lot_charges.id', '=', 'payment_allocations.lot_charge_id')->join('lots', 'lots.id', '=', 'payment_allocations.lot_id')
            ->when($paymentIds->isNotEmpty(), fn ($query) => $query->whereIn('payments.id', $paymentIds))
            ->where(fn ($query) => $query->whereColumn('payments.organization_id', '!=', 'lot_charges.organization_id')->orWhereColumn('payments.residence_id', '!=', 'lot_charges.residence_id')->orWhereColumn('payment_allocations.lot_id', '!=', 'lot_charges.lot_id')->orWhereColumn('lots.residence_id', '!=', 'payments.residence_id'))->count();
        if ($allocationCount > 0) {
            $violations[] = $this->issue('cross_tenant_allocation', 'payment_allocations', '*', ['count' => $allocationCount]);
        }

        $movementCount = DB::table('financial_account_movements')->join('payments', 'payments.id', '=', 'financial_account_movements.payment_id')->join('financial_accounts', 'financial_accounts.id', '=', 'financial_account_movements.financial_account_id')->join('financial_exercises', 'financial_exercises.id', '=', 'financial_account_movements.financial_exercise_id')
            ->when($paymentIds->isNotEmpty(), fn ($query) => $query->whereIn('payments.id', $paymentIds))
            ->where(fn ($query) => $query->whereColumn('payments.organization_id', '!=', 'financial_account_movements.organization_id')->orWhereColumn('payments.residence_id', '!=', 'financial_account_movements.residence_id')->orWhereColumn('payments.financial_account_id', '!=', 'financial_account_movements.financial_account_id')->orWhereColumn('financial_accounts.residence_id', '!=', 'payments.residence_id')->orWhereColumn('financial_exercises.residence_id', '!=', 'payments.residence_id'))->count();
        if ($movementCount > 0) {
            $violations[] = $this->issue('cross_tenant_movement', 'financial_account_movements', '*', ['count' => $movementCount]);
        }

        $paymentReferenceCount = DB::table('payments')->join('financial_exercises', 'financial_exercises.id', '=', 'payments.financial_exercise_id')->join('financial_accounts', 'financial_accounts.id', '=', 'payments.financial_account_id')
            ->when($paymentIds->isNotEmpty(), fn ($query) => $query->whereIn('payments.id', $paymentIds))
            ->where(fn ($query) => $query->whereColumn('payments.residence_id', '!=', 'financial_exercises.residence_id')->orWhereColumn('payments.organization_id', '!=', 'financial_exercises.organization_id')->orWhereColumn('payments.residence_id', '!=', 'financial_accounts.residence_id'))->count();
        if ($paymentReferenceCount > 0) {
            $violations[] = $this->issue('cross_tenant_payment_reference', 'payments', '*', ['count' => $paymentReferenceCount]);
        }

        $chargeReferenceCount = DB::table('lot_charges')->join('fund_calls', 'fund_calls.id', '=', 'lot_charges.fund_call_id')->join('lots', 'lots.id', '=', 'lot_charges.lot_id')
            ->where(fn ($query) => $query->whereColumn('lot_charges.residence_id', '!=', 'fund_calls.residence_id')->orWhereColumn('lot_charges.organization_id', '!=', 'fund_calls.organization_id')->orWhereColumn('lot_charges.residence_id', '!=', 'lots.residence_id'))->count();
        if ($chargeReferenceCount > 0) {
            $violations[] = $this->issue('cross_tenant_charge_reference', 'lot_charges', '*', ['count' => $chargeReferenceCount]);
        }

        $contactLinkCount = DB::table('contact_user')->join('contacts', 'contacts.id', '=', 'contact_user.contact_id')->whereColumn('contact_user.organization_id', '!=', 'contacts.organization_id')->count();
        if ($contactLinkCount > 0) {
            $violations[] = $this->issue('cross_tenant_contact_link', 'contact_user', '*', ['count' => $contactLinkCount]);
        }
    }

    private function issue(string $code, string $entity, int|string $id, array $details = []): array
    {
        return compact('code', 'entity', 'id', 'details');
    }
}
