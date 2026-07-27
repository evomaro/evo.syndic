<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyElectorate;
use App\Models\User;

class GovernancePortalAccessService
{
    public function ownerElectorate(Assembly $assembly, User $user): ?AssemblyElectorate
    {
        return $assembly->electorate()
            ->whereHas('contact.users', fn ($query) => $query
                ->where('users.id', $user->id)
                ->where('contact_user.organization_id', $assembly->organization_id)
                ->whereNull('contact_user.revoked_at'))
            ->whereHas('contact.ownerships', fn ($query) => $query
                ->whereDate('starts_on', '<=', today())
                ->where(fn ($dates) => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
                ->whereHas('lot', fn ($lot) => $lot
                    ->where('residence_id', $assembly->residence_id)
                    ->where('active', true)))
            ->first();
    }

    public function proxyElectorate(Assembly $assembly, User $user): ?AssemblyElectorate
    {
        return $assembly->electorate()
            ->whereHas('assembly.proxies', fn ($query) => $query
                ->whereColumn('assembly_proxies.principal_electorate_id', 'assembly_electorates.id')
                ->where('assembly_proxies.representative_user_id', $user->id)
                ->where('assembly_proxies.status', 'verified'))
            ->first();
    }

    public function electorate(Assembly $assembly, User $user): ?AssemblyElectorate
    {
        return $this->ownerElectorate($assembly, $user) ?? $this->proxyElectorate($assembly, $user);
    }

    public function isProxyOnly(Assembly $assembly, User $user): bool
    {
        return ! $this->ownerElectorate($assembly, $user) && (bool) $this->proxyElectorate($assembly, $user);
    }
}
