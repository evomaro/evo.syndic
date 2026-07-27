<?php

namespace App\Services;

use App\Models\FinancialExercise;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceWorkflow
{
    public function __construct(
        private OrganizationDocumentNumberService $numbers,
        private SupplierPayableService $payables,
        private ExpenseResidenceAccessService $access,
        private AutomatedAccountingPostingService $accounting,
    ) {}

    public function validate(SupplierInvoice $invoice, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->with(['supplier', 'residence.organization', 'commitment'])->lockForUpdate()->firstOrFail();
            $locked = $this->payables->lockInvoice($invoice->id);
            $locked->load(['lines.residence', 'lines.exercise', 'lines.category']);
            $invoice->setRelation('lines', $locked->lines);
            if ($invoice->status === 'validated') {
                $this->accounting->postSupplierInvoice($invoice->fresh('lines'), $actor);

                return $invoice;
            }
            if ($invoice->status !== 'draft' || $invoice->lines->isEmpty()) {
                throw ValidationException::withMessages(['status' => __('La facture doit être un brouillon avec au moins une ligne.')]);
            }
            $this->access->authorize($actor, $invoice->residence->organization, $invoice->lines->pluck('residence_id')->all(), $invoice->lines->pluck('residence_id')->unique()->count() > 1);
            $original = $invoice->attachments->firstWhere('kind', 'original');
            if (! $original || ! Storage::disk($original->disk)->exists($original->path) || ! hash_equals($original->checksum, hash('sha256', Storage::disk($original->disk)->get($original->path)))) {
                throw ValidationException::withMessages(['attachments' => __('La facture originale privée est obligatoire et doit passer le contrôle d’intégrité.')]);
            }
            foreach ($invoice->lines as $line) {
                if ($line->residence->organization_id !== $invoice->organization_id || $line->exercise->status !== 'open'
                    || $invoice->invoice_date->lt($line->exercise->starts_on) || $invoice->invoice_date->gt($line->exercise->ends_on)) {
                    throw ValidationException::withMessages(['lines' => __('Chaque ligne doit appartenir à la résidence et à un exercice ouvert couvrant la date de facture.')]);
                }
                if ($line->category->residence_id !== $line->residence_id || ! $line->category->active) {
                    throw ValidationException::withMessages(['lines' => __('La catégorie de dépense est invalide.')]);
                }
            }
            $duplicate = SupplierInvoice::query()->where('organization_id', $invoice->organization_id)->where('supplier_id', $invoice->supplier_id)
                ->where('supplier_invoice_number', $invoice->supplier_invoice_number)->whereKeyNot($invoice->id)->whereNot('status', 'cancelled')->lockForUpdate()->exists();
            if ($duplicate && ! $invoice->duplicate_warning_acknowledged_at) {
                throw ValidationException::withMessages(['supplier_invoice_number' => __('Cette référence fournisseur existe déjà.')]);
            }
            $subtotal = (int) $invoice->lines->sum('subtotal_cents');
            $tax = (int) $invoice->lines->sum('tax_cents');
            $total = (int) $invoice->lines->sum('total_cents');
            if ($total <= 0 || $subtotal + $tax !== $total) {
                throw ValidationException::withMessages(['lines' => __('Les totaux calculés de la facture sont incohérents.')]);
            }
            if ($invoice->commitment && (! in_array($invoice->commitment->status, ['approved', 'partially_invoiced'], true) || ($invoice->commitment->supplier_id && $invoice->commitment->supplier_id !== $invoice->supplier_id))) {
                throw ValidationException::withMessages(['expense_commitment_id' => __('L’engagement lié doit être approuvé et concerner le même fournisseur.')]);
            }
            if ($invoice->commitment && $invoice->lines->contains(fn ($line) => $line->residence_id !== $invoice->commitment->residence_id)) {
                throw ValidationException::withMessages(['expense_commitment_id' => __('Un engagement de résidence ne peut financer des lignes d’une autre résidence.')]);
            }
            if ($invoice->commitment) {
                $already = (int) SupplierInvoice::query()->where('expense_commitment_id', $invoice->commitment->id)->whereIn('status', ['validated', 'partial', 'paid'])->whereKeyNot($invoice->id)->sum('total_cents');
                if ($already + $total > $invoice->commitment->amount_cents && ! $invoice->duplicate_warning_reason) {
                    throw ValidationException::withMessages(['expense_commitment_id' => __('La facture dépasse l’engagement. Une justification d’override est requise.')]);
                }
            }
            $number = $this->numbers->next($invoice->residence->organization, 'EXP', (int) $invoice->invoice_date->format('Y'));
            foreach ($invoice->lines as $line) {
                $line->update(['immutable_snapshot' => ['residence' => $line->residence?->name, 'exercise' => $line->exercise->name, 'category' => $line->category->name, 'amounts' => $line->only(['subtotal_cents', 'tax_cents', 'total_cents'])]]);
            }
            $invoice->update([
                'number' => $number, 'subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'total_cents' => $total,
                'status' => 'validated', 'validated_at' => now(), 'validated_by' => $actor->id,
                'validation_snapshot' => ['supplier' => $invoice->supplier->only(['legal_name', 'ice', 'tax_id']), 'lines' => $invoice->lines->map->only(['residence_id', 'financial_exercise_id', 'expense_category_id', 'description', 'subtotal_cents', 'tax_cents', 'total_cents'])->all()],
            ]);
            $this->payables->recalculate($invoice->fresh());
            $invoice->attachments()->update(['immutable' => true]);
            if ($invoice->commitment) {
                $invoiced = (int) SupplierInvoice::query()->where('expense_commitment_id', $invoice->commitment->id)->whereIn('status', ['validated', 'partial', 'paid'])->sum('total_cents');
                $invoice->commitment->update(['status' => $invoiced >= $invoice->commitment->amount_cents ? 'fully_invoiced' : 'partially_invoiced']);
            }
            $this->accounting->postSupplierInvoice($invoice->fresh('lines'), $actor);
            activity()->performedOn($invoice)->causedBy($actor)->withProperties(['organization_id' => $invoice->organization_id, 'residence_id' => $invoice->primary_residence_id, 'number' => $number, 'total_cents' => $total])->log('supplier_invoice.validated');

            return $invoice->fresh(['lines', 'attachments']);
        }, 5);
    }

    public function cancel(SupplierInvoice $invoice, User $actor, string $reason): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $invoice = $this->payables->lockInvoice($invoice->id);
            $this->payables->recalculate($invoice);
            $invoice->refresh();
            if ($invoice->status !== 'validated' || $invoice->paid_cents > 0 || $invoice->credited_cents > 0) {
                throw ValidationException::withMessages(['status' => __('Une facture réglée ou créditée ne peut pas être annulée.')]);
            }
            FinancialExercise::query()->whereIn('id', $invoice->lines()->pluck('financial_exercise_id'))->where('status', 'closed')->exists()
                && throw ValidationException::withMessages(['exercise' => __('L’exercice financier est clôturé.')]);
            $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]);
            $this->accounting->reverse('supplier_invoice', $invoice->id, $actor, $reason);
            if ($invoice->expense_commitment_id) {
                $commitment = $invoice->commitment()->lockForUpdate()->first();
                $invoiced = (int) SupplierInvoice::query()->where('expense_commitment_id', $commitment->id)->whereIn('status', ['validated', 'partial', 'paid'])->sum('total_cents');
                $commitment->update(['status' => $invoiced === 0 ? 'approved' : ($invoiced >= $commitment->amount_cents ? 'fully_invoiced' : 'partially_invoiced')]);
            }
            activity()->performedOn($invoice)->causedBy($actor)->withProperties(['organization_id' => $invoice->organization_id, 'residence_id' => $invoice->primary_residence_id, 'reason' => $reason])->log('supplier_invoice.cancelled');

            return $invoice;
        }, 5);
    }
}
