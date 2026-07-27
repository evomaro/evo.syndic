<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class SupplierRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('manage_suppliers');
    }

    public function rules(): array
    {
        $organizationId = $this->context()->organization()->id;

        return [
            'legal_name' => ['required', 'string', 'max:255'], 'trade_name' => ['nullable', 'string', 'max:255'],
            'ice' => ['nullable', 'digits:15'], 'tax_id' => ['nullable', 'string', 'max:50'], 'registration_number' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'], 'bank_name' => ['nullable', 'string', 'max:255'], 'rib' => ['nullable', 'string', 'max:40'], 'iban' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'duplicate_warning_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
            'category_ids' => ['array'], 'category_ids.*' => [Rule::exists('supplier_service_categories', 'id')->where('organization_id', $organizationId)],
        ];
    }
}
