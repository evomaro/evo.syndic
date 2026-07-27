<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class SupplierContractRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('manage_supplier_contracts');
    }

    public function rules(): array
    {
        $context = $this->context();

        return [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $context->organization()->id)],
            'supplier_service_category_id' => ['nullable', Rule::exists('supplier_service_categories', 'id')->where('organization_id', $context->organization()->id)],
            'expense_category_id' => ['nullable', Rule::exists('expense_categories', 'id')->where('residence_id', $context->residence()->id)],
            'reference' => ['required', 'string', 'max:100', Rule::unique('supplier_contracts')->where('residence_id', $context->residence()->id)->ignore($this->route('contract'))],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'amount_cents' => ['nullable', 'integer', 'min:0'], 'billing_frequency' => ['nullable', Rule::in(['monthly', 'quarterly', 'yearly', 'one_off'])],
            'renewal_type' => ['required', Rule::in(['none', 'manual', 'automatic'])], 'notice_days' => ['required', 'integer', 'min:0', 'max:730'], 'auto_renew' => ['boolean'], 'notes' => ['nullable', 'string'],
        ];
    }
}
