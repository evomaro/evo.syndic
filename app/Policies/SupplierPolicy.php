<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function view(User $user, Supplier $supplier): bool
    {
        $organization = Organization::query()->find($supplier->organization_id);

        return $organization && $user->canInOrganization('view_expenses', $organization);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        $organization = Organization::query()->find($supplier->organization_id);

        return $organization && $user->canInOrganization('manage_suppliers', $organization);
    }
}
