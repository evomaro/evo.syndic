<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('create_expenses');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('expense_categories')->where('residence_id', $this->context()->residence()->id)->ignore($this->route('category'))], 'type' => ['required', Rule::in(['ordinary', 'exceptional'])], 'default_visibility' => ['sometimes', Rule::in(['private', 'category_summary', 'invoice_summary'])]];
    }
}
