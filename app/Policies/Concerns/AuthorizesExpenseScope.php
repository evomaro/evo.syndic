<?php

namespace App\Policies\Concerns;

use App\Models\Organization;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesExpenseScope
{
    private function permitted(User $user, Model $model, string $permission): bool
    {
        $organizationId = (int) $model->getAttribute('organization_id');
        $active = app(TenantContext::class);
        $residenceId = $active->residence && (int) $active->residence->organization_id === $organizationId
            ? (int) $active->residence->id
            : (int) ($model->getAttribute('residence_id') ?: $model->getAttribute('primary_residence_id'));
        $organization = Organization::query()->find($organizationId);

        if (! $organization || ! $user->canInOrganization($permission, $organization)) {
            return false;
        }

        $membership = $user->organizations()->whereKey($organizationId)->first()?->pivot;

        return (bool) $membership?->all_residences || ($residenceId > 0 && $user->residences()->whereKey($residenceId)->exists());
    }
}
