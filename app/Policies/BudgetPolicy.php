<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;
use App\Policies\Concerns\AuthorizesExpenseScope;

class BudgetPolicy
{
    use AuthorizesExpenseScope;

    public function view(User $user, Budget $model): bool
    {
        return $this->permitted($user, $model, 'view_expenses');
    }

    public function update(User $user, Budget $model): bool
    {
        return $this->permitted($user, $model, 'manage_budgets');
    }

    public function approve(User $user, Budget $model): bool
    {
        return $this->permitted($user, $model, 'approve_budgets');
    }
}
