<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class ExpenseCommitmentRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('create_expenses');
    }

    public function rules(): array
    {
        $context = $this->context();

        return ['financial_exercise_id' => ['required', Rule::exists('financial_exercises', 'id')->where('residence_id', $context->residence()->id)], 'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('organization_id', $context->organization()->id)], 'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('residence_id', $context->residence()->id)], 'supplier_contract_id' => ['nullable', Rule::exists('supplier_contracts', 'id')->where('residence_id', $context->residence()->id)], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'committed_on' => ['required', 'date'], 'expected_invoice_date' => ['nullable', 'date'], 'amount_cents' => ['required', 'integer', 'min:1']];
    }
}
