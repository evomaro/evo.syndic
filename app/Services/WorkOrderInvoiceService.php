<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\FinancialExercise;
use App\Models\MaintenanceWorkOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkOrderInvoiceService
{
    public function link(MaintenanceWorkOrder $order, SupplierInvoice $invoice, User $actor, ?string $justification = null): SupplierInvoice
    {
        return DB::transaction(function () use ($order, $invoice, $actor, $justification) {
            $order = MaintenanceWorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->guard($order, $invoice->organization_id, $invoice->primary_residence_id, $invoice->supplier_id, (int) $invoice->total_cents, $justification);
            if ($invoice->maintenance_work_order_id && $invoice->maintenance_work_order_id !== $order->id) {
                throw ValidationException::withMessages(['invoice_id' => __('Cette facture est déjà liée à un autre bon de travail.')]);
            }
            if ($order->invoice()->whereKeyNot($invoice->id)->exists()) {
                throw ValidationException::withMessages(['invoice_id' => __('Une facture existe déjà pour ce bon de travail.')]);
            }
            $invoice->update(['maintenance_work_order_id' => $order->id, 'maintenance_amount_justification' => $justification]);
            activity()->performedOn($order)->causedBy($actor)->withProperties(['organization_id' => $order->organization_id, 'residence_id' => $order->residence_id, 'invoice_id' => $invoice->id])->log('maintenance_work_order.invoice_linked');

            return $invoice;
        }, 3);
    }

    public function createDraft(MaintenanceWorkOrder $order, array $data, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = MaintenanceWorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->invoice()->exists()) {
                throw ValidationException::withMessages(['invoice' => __('Une facture existe déjà pour ce bon de travail.')]);
            }
            $amount = (int) $data['total_cents'];
            $this->guard($order, $order->organization_id, $order->residence_id, (int) $order->supplier_id, $amount, $data['amount_justification'] ?? null);
            $exercise = FinancialExercise::query()->whereKey($data['financial_exercise_id'])->where('organization_id', $order->organization_id)->where('residence_id', $order->residence_id)->where('status', 'open')->firstOrFail();
            $category = ExpenseCategory::query()->whereKey($data['expense_category_id'])->where('organization_id', $order->organization_id)->where('residence_id', $order->residence_id)->where('active', true)->firstOrFail();
            $invoice = SupplierInvoice::create([
                'organization_id' => $order->organization_id, 'primary_residence_id' => $order->residence_id, 'supplier_id' => $order->supplier_id,
                'supplier_contract_id' => $order->supplier_contract_id, 'maintenance_work_order_id' => $order->id, 'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'], 'subtotal_cents' => $amount, 'tax_cents' => 0, 'total_cents' => $amount,
                'status' => 'draft', 'idempotency_key' => 'work-order:'.$order->id, 'maintenance_amount_justification' => $data['amount_justification'] ?? null,
            ]);
            $invoice->lines()->create(['residence_id' => $order->residence_id, 'financial_exercise_id' => $exercise->id, 'expense_category_id' => $category->id, 'description' => $order->scope_of_work, 'quantity' => 1, 'unit_price_cents' => $amount, 'tax_rate' => 0, 'subtotal_cents' => $amount, 'tax_cents' => 0, 'total_cents' => $amount, 'visibility' => 'private']);
            activity()->performedOn($order)->causedBy($actor)->withProperties(['organization_id' => $order->organization_id, 'residence_id' => $order->residence_id, 'invoice_id' => $invoice->id, 'total_cents' => $amount])->log('maintenance_work_order.invoice_created');

            return $invoice;
        }, 3);
    }

    private function guard(MaintenanceWorkOrder $order, int $organizationId, int $residenceId, int $supplierId, int $amount, ?string $justification): void
    {
        if ($order->status !== 'validated' || $organizationId !== $order->organization_id || $residenceId !== $order->residence_id || $supplierId !== $order->supplier_id) {
            throw ValidationException::withMessages(['invoice' => __('La facture ne correspond pas au bon validé.')]);
        }
        $expected = $order->actual_cost_cents ?? $order->quotation?->total_cents;
        if ($expected !== null && (int) $expected !== $amount && blank($justification)) {
            throw ValidationException::withMessages(['amount_justification' => __('Une justification est obligatoire lorsque le montant diffère.')]);
        }
    }
}
