<?php

namespace App\Policies;

use App\Models\SupplierCreditNote;
use App\Models\User;
use App\Policies\Concerns\AuthorizesExpenseScope;

class SupplierCreditNotePolicy
{
    use AuthorizesExpenseScope;

    public function view(User $user, SupplierCreditNote $model): bool
    {
        return $this->permitted($user, $model, 'view_expenses');
    }

    public function update(User $user, SupplierCreditNote $model): bool
    {
        return $this->permitted($user, $model, 'manage_credit_notes');
    }
}
