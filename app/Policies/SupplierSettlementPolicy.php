<?php

namespace App\Policies;

use App\Models\SupplierSettlement;
use App\Models\User;
use App\Policies\Concerns\AuthorizesExpenseScope;

class SupplierSettlementPolicy
{
    use AuthorizesExpenseScope;

    public function view(User $user, SupplierSettlement $model): bool
    {
        return $this->permitted($user, $model, 'view_supplier_payables');
    }

    public function validate(User $user, SupplierSettlement $model): bool
    {
        return $this->permitted($user, $model, 'validate_settlements');
    }

    public function reverse(User $user, SupplierSettlement $model): bool
    {
        return $this->permitted($user, $model, 'reverse_settlements');
    }
}
