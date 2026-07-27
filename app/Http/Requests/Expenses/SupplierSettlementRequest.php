<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class SupplierSettlementRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('create_settlements');
    }

    public function rules(): array
    {
        $context = $this->context();

        return ['financial_exercise_id' => ['required', Rule::exists('financial_exercises', 'id')->where('residence_id', $context->residence()->id)], 'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $context->organization()->id)], 'financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('residence_id', $context->residence()->id)], 'settlement_date' => ['required', 'date'], 'amount_cents' => ['required', 'integer', 'min:1'], 'method' => ['required', Rule::in(['bank_transfer', 'cheque', 'cash', 'direct_debit'])], 'bank_reference' => ['nullable', 'string', 'max:255'], 'cheque_number' => ['nullable', 'string', 'max:100'], 'idempotency_key' => ['nullable', 'string', 'max:64'], 'notes' => ['nullable', 'string']];
    }
}
