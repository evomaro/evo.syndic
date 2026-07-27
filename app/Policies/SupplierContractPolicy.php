<?php

namespace App\Policies;

use App\Models\SupplierContract;
use App\Models\User;
use App\Policies\Concerns\AuthorizesExpenseScope;

class SupplierContractPolicy
{
    use AuthorizesExpenseScope;

    public function view(User $user, SupplierContract $contract): bool
    {
        return $this->permitted($user, $contract, 'view_expenses');
    }

    public function update(User $user, SupplierContract $contract): bool
    {
        return $this->permitted($user, $contract, 'manage_supplier_contracts');
    }
}
