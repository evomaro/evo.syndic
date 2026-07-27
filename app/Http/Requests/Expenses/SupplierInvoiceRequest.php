<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class SupplierInvoiceRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('create_expenses');
    }

    public function rules(): array
    {
        $context = $this->context();
        $organizationId = $context->organization()->id;

        return ['supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)], 'supplier_contract_id' => ['nullable', Rule::exists('supplier_contracts', 'id')->where('residence_id', $context->residence()->id)], 'expense_commitment_id' => ['nullable', Rule::exists('expense_commitments', 'id')->where('residence_id', $context->residence()->id)], 'supplier_invoice_number' => ['nullable', 'string', 'max:100'], 'invoice_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:invoice_date'], 'idempotency_key' => ['nullable', 'string', 'max:64'], 'duplicate_warning_reason' => ['nullable', 'string', 'min:10', 'max:1000'], 'notes' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.residence_id' => ['nullable', Rule::exists('residences', 'id')->where('organization_id', $organizationId)], 'lines.*.financial_exercise_id' => ['required', Rule::exists('financial_exercises', 'id')->where('organization_id', $organizationId)], 'lines.*.expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('organization_id', $organizationId)], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'decimal:0,3', 'gt:0'], 'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'], 'lines.*.tax_rate' => ['required', 'decimal:0,3', 'min:0', 'max:100'], 'lines.*.visibility' => ['nullable', Rule::in(['private', 'category_summary', 'invoice_summary'])]];
    }
}
