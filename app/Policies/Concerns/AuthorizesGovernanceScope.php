<?php

namespace App\Policies\Concerns;

use App\Models\Organization;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesGovernanceScope
{
    private function governancePermitted(User $user, Model $model, string $permission): bool
    {
        $organization = Organization::find((int) $model->getAttribute('organization_id'));
        $context = app(TenantContext::class);
        if (! $organization || $context->organization?->id !== $organization->id || $context->residence?->id !== (int) $model->getAttribute('residence_id') || ! $user->canInOrganization($permission, $organization)) {
            return false;
        }$membership = $user->organizations()->whereKey($organization->id)->first()?->pivot;

        return (bool) $membership?->all_residences || $user->residences()->whereKey($model->getAttribute('residence_id'))->exists();
    }
}
