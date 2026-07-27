<?php

namespace App\Policies;

use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Policies\Concerns\AuthorizesMaintenanceScope;

class MaintenanceRequestPolicy
{
    use AuthorizesMaintenanceScope;

    public function view(User $user, MaintenanceRequest $request): bool
    {
        return $this->maintenancePermitted($user, $request, 'view_maintenance_requests')
            || ($request->reporter_user_id === $user->id && $user->residences()->whereKey($request->residence_id)->exists());
    }

    public function update(User $user, MaintenanceRequest $request): bool
    {
        return ($request->reporter_user_id === $user->id && $request->status === 'draft')
            || $this->maintenancePermitted($user, $request, 'manage_maintenance_requests');
    }

    public function transition(User $user, MaintenanceRequest $request): bool
    {
        return $this->maintenancePermitted($user, $request, 'transition_maintenance_requests')
            || ($request->reporter_user_id === $user->id && in_array($request->status, ['draft', 'resolved', 'closed'], true));
    }

    public function assign(User $user, MaintenanceRequest $request): bool
    {
        return $this->maintenancePermitted($user, $request, 'assign_maintenance_requests');
    }
}
