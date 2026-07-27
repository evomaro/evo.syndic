<?php

namespace App\Policies;

use App\Models\ExpenseCommitment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesExpenseScope;

class ExpenseCommitmentPolicy
{
    use AuthorizesExpenseScope;

    public function view(User $user, ExpenseCommitment $model): bool
    {
        return $this->permitted($user, $model, 'view_expenses');
    }

    public function update(User $user, ExpenseCommitment $model): bool
    {
        return $this->permitted($user, $model, 'approve_commitments');
    }
}
