<?php

namespace App\Services;

use App\Models\ExpenseCommitment;
use App\Models\FinancialExercise;
use App\Models\Payment;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FinancialExerciseLifecycleService
{
    public function transition(FinancialExercise $exercise, string $action, User $actor, ?string $reason = null): FinancialExercise
    {
        return DB::transaction(function () use ($exercise, $action, $actor, $reason) {
            FinancialExercise::query()->where('residence_id', $exercise->residence_id)->lockForUpdate()->get();
            $exercise = FinancialExercise::query()->whereKey($exercise->id)->lockForUpdate()->firstOrFail();

            if ($action === 'open') {
                if ($exercise->status !== 'draft' || FinancialExercise::where('residence_id', $exercise->residence_id)->where('status', 'open')->whereKeyNot($exercise->id)->exists()) {
                    throw ValidationException::withMessages(['action' => __('Un autre exercice est ouvert ou cet exercice ne peut pas être ouvert.')]);
                }
                $exercise->update(['status' => 'open', 'opened_at' => now(), 'opened_by' => $actor->id]);
            }

            if ($action === 'close') {
                if ($exercise->status !== 'open') {
                    throw ValidationException::withMessages(['action' => __('Cet exercice ne peut pas être clôturé.')]);
                }
                $issues = $this->closeReadiness($exercise);
                if ($issues !== []) {
                    throw ValidationException::withMessages(['action' => implode(' ', $issues)]);
                }
                $exercise->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id]);
            }

            if ($action === 'reopen') {
                if ($exercise->status !== 'closed' || blank($reason)) {
                    throw ValidationException::withMessages(['reason' => __('Un motif est obligatoire pour la réouverture.')]);
                }
                $role = $actor->organizations()->whereKey($exercise->organization_id)->first()?->pivot?->role;
                abort_unless(in_array($role, ['owner', 'accountant'], true), 403);
                if (FinancialExercise::where('residence_id', $exercise->residence_id)->where('status', 'open')->whereKeyNot($exercise->id)->exists()) {
                    throw ValidationException::withMessages(['action' => __('Un autre exercice est déjà ouvert.')]);
                }
                $metadata = $exercise->metadata ?? [];
                $metadata['reopen_history'][] = ['reason' => $reason, 'actor_id' => $actor->id, 'at' => now()->toIso8601String()];
                $exercise->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null, 'metadata' => $metadata]);
            }

            activity()->performedOn($exercise)->causedBy($actor)->withProperties([
                'organization_id' => $exercise->organization_id, 'residence_id' => $exercise->residence_id,
                'action' => $action, 'reason' => $reason,
            ])->log('financial_exercise.'.$action);

            return $exercise->fresh();
        });
    }

    /** @return list<string> */
    public function closeReadiness(FinancialExercise $exercise): array
    {
        $issues = [];
        if ($exercise->fundCalls()->where('status', 'draft')->exists()) {
            $issues[] = __('Des appels de fonds sont encore en brouillon.');
        }
        if ($exercise->payments()->where('status', 'draft')->exists()) {
            $issues[] = __('Des paiements sont encore en brouillon.');
        }
        if (SupplierInvoiceLine::query()->where('financial_exercise_id', $exercise->id)->whereHas('invoice', fn ($query) => $query->where('status', 'draft'))->exists()) {
            $issues[] = __('Des factures fournisseurs sont encore en brouillon.');
        }
        if (SupplierSettlement::query()->where('financial_exercise_id', $exercise->id)->where('status', 'draft')->exists()) {
            $issues[] = __('Des règlements fournisseurs sont encore en brouillon.');
        }
        if (ExpenseCommitment::query()->where('financial_exercise_id', $exercise->id)->whereIn('status', ['draft', 'submitted'])->exists()) {
            $issues[] = __('Des engagements sont encore en attente.');
        }

        $payments = $exercise->payments()->whereIn('status', ['validated', 'reversed'])->with(['allocations', 'documents'])->get();
        if ($payments->where('status', 'validated')->contains(fn (Payment $payment) => ! $payment->payer_contact_id)) {
            $issues[] = __('Un paiement non identifié doit être identifié ou extourné avant la clôture.');
        }
        if ($payments->contains(fn (Payment $payment) => $payment->allocated_cents > $payment->amount_cents)) {
            $issues[] = __('Des affectations de paiement sont incohérentes.');
        }
        if ($exercise->residence->lotCharges()->where('financial_exercise_id', $exercise->id)->whereRaw('lot_charges.amount_cents < (SELECT COALESCE(SUM(payment_allocations.amount_cents), 0) FROM payment_allocations WHERE payment_allocations.lot_charge_id = lot_charges.id AND payment_allocations.reversed_at IS NULL)')->exists()) {
            $issues[] = __('Une charge est sur-affectée.');
        }
        if ($payments->contains(function (Payment $payment) {
            $originals = $payment->movements()->where('operational_kind', 'payment_receipt');

            return $originals->count() !== 1 || (int) $originals->sum('amount_cents') !== (int) $payment->amount_cents;
        })) {
            $issues[] = __('Des mouvements de paiement ne sont pas rapprochés.');
        }
        if ($payments->contains(function (Payment $payment) {
            $document = $payment->documents->where('type', 'receipt')->where('version', 1)->first();

            return ! $document || ! Storage::disk($document->disk)->exists($document->path);
        })) {
            $issues[] = __('Des reçus sont manquants ou en attente de régénération.');
        }
        if ($exercise->residence->fundCallSchedules()->whereNotNull('last_failed_at')->whereBetween('last_failed_at', [$exercise->starts_on, $exercise->ends_on->copy()->endOfDay()])->exists()) {
            $issues[] = __('Des générations récurrentes ont échoué pendant l’exercice.');
        }
        $audit = app(CollectionAuditService::class)->audit(['residence' => $exercise->residence_id, 'exercise' => $exercise->id]);
        if (! $audit['ok']) {
            $issues[] = __('Le rapprochement des encaissements contient des incohérences.');
        }
        if (! app(ExpenseAuditService::class)->run(['residence' => $exercise->residence_id, 'exercise' => $exercise->id])['ok']) {
            $issues[] = __('Le rapprochement des dépenses contient des incohérences.');
        }

        return array_values(array_unique($issues));
    }
}
