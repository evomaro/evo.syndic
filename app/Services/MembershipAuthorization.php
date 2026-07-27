<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;

class MembershipAuthorization
{
    public function permissions(User $user, Organization $organization): array
    {
        $pivot = $user->organizations()->whereKey($organization->id)->first()?->pivot;
        if (! $pivot) {
            return [];
        }
        if ($pivot->role === 'owner') {
            return config('evosyndic.permissions');
        }
        $defaults = [
            ...config("evosyndic.roles.{$pivot->role}", []),
            ...config("evosyndic.accounting_roles.{$pivot->role}", []),
            ...config("evosyndic.compliance_roles.{$pivot->role}", []),
            ...config("evosyndic.governance_roles.{$pivot->role}", []),
        ];
        $explicit = is_array($pivot->permissions) ? $pivot->permissions : json_decode($pivot->permissions ?: '[]', true);

        return array_values(array_intersect(config('evosyndic.permissions'), array_unique([...$defaults, ...$explicit])));
    }

    public function can(User $user, Organization $organization, string $permission): bool
    {
        return in_array($permission, $this->permissions($user, $organization), true);
    }

    public function mayAssign(User $actor, Organization $organization, string $role, array $explicit): bool
    {
        $actorRole = $actor->organizations()->whereKey($organization->id)->first()?->pivot?->role;
        if ($actorRole === 'owner') {
            return true;
        }
        if ($role === 'owner') {
            return false;
        }
        $granted = array_unique([...config("evosyndic.roles.$role", []), ...$explicit]);

        return empty(array_diff($granted, $this->permissions($actor, $organization)));
    }
}
