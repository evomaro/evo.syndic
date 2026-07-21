<?php

namespace App\Policies;

use App\Models\Residence;
use App\Models\User;

class ResidencePolicy
{
    public function view(User $user, Residence $residence): bool
    {
        $membership = $user->organizations()->whereKey($residence->organization_id)->first()?->pivot;

        return (bool) $membership && ($membership->all_residences || $user->residences()->whereKey($residence->id)->exists());
    }

    public function update(User $user, Residence $residence): bool
    {
        return $this->view($user, $residence) && $residence->status !== 'archived' && $user->canInOrganization('edit_residences', $residence->organization);
    }

    public function archive(User $user, Residence $residence): bool
    {
        $role = $user->organizations()->whereKey($residence->organization_id)->first()?->pivot?->role;

        return $this->view($user, $residence) && in_array($role, ['owner', 'administrator'], true);
    }

    public function restore(User $user, Residence $residence): bool
    {
        return $this->archive($user, $residence);
    }
}
