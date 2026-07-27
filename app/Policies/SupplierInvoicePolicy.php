<?php

namespace App\Policies;

use App\Models\SupplierInvoice;
use App\Models\User;
use App\Policies\Concerns\AuthorizesExpenseScope;

class SupplierInvoicePolicy
{
    use AuthorizesExpenseScope;

    public function view(User $user, SupplierInvoice $model): bool
    {
        return $this->permitted($user, $model, 'view_expenses');
    }

    public function validate(User $user, SupplierInvoice $model): bool
    {
        return $this->permitted($user, $model, 'validate_expenses');
    }

    public function cancel(User $user, SupplierInvoice $model): bool
    {
        return $this->permitted($user, $model, 'cancel_expenses');
    }

    public function attach(User $user, SupplierInvoice $model): bool
    {
        return $this->permitted($user, $model, 'create_expenses');
    }
}
