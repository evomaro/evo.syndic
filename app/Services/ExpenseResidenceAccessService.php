<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ExpenseResidenceAccessService
{
    public function authorize(User $user, Organization $organization, array $residenceIds, bool $multiResidence = false): void
    {
        $residenceIds = collect($residenceIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($residenceIds->isEmpty()) {
            throw new AuthorizationException(__('Aucune résidence autorisée.'));
        }
        $membership = $user->organizations()->whereKey($organization->id)->first()?->pivot;
        if (! $membership) {
            throw new AuthorizationException;
        }
        if (! $membership->all_residences) {
            $allowed = $user->residences()->where('organization_id', $organization->id)->whereIn('residences.id', $residenceIds)->count();
            if ($allowed !== $residenceIds->count()) {
                throw new AuthorizationException(__('Accès refusé à une résidence de la facture.'));
            }
        }
        if (($multiResidence || $residenceIds->count() > 1) && ! $user->canInOrganization('create_cross_residence_expenses', $organization)) {
            throw new AuthorizationException(__('La permission multi-résidence est requise.'));
        }
    }
}
