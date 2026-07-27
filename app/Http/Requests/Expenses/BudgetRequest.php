<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Validation\Rule;

class BudgetRequest extends ExpenseFormRequest
{
    public function authorize(): bool
    {
        return $this->permits('manage_budgets');
    }

    public function rules(): array
    {
        $residenceId = $this->context()->residence()->id;

        return ['financial_exercise_id' => ['required', Rule::exists('financial_exercises', 'id')->where('residence_id', $residenceId)], 'title' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.expense_category_id' => ['required', 'distinct', Rule::exists('expense_categories', 'id')->where('residence_id', $residenceId)], 'lines.*.planned_cents' => ['required', 'integer', 'min:0'], 'lines.*.description' => ['nullable', 'string', 'max:255']];
    }
}
