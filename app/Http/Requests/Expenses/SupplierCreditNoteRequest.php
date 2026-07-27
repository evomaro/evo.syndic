<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class SupplierCreditNoteRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('manage_credit_notes');
    }

    public function rules(): array
    {
        return ['supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $this->context()->organization()->id)], 'supplier_credit_number' => ['nullable', 'string', 'max:100'], 'credit_date' => ['required', 'date'], 'amount_cents' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['nullable', 'string', 'max:64'], 'reason' => ['nullable', 'string', 'max:2000']];
    }
}
