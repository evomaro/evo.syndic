<?php

namespace App\Http\Requests\Expenses;

class BudgetRevisionRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('manage_budgets');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:1000']];
    }
}
