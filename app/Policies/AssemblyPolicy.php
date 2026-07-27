<?php

namespace App\Policies;

use App\Models\Assembly;
use App\Models\User;
use App\Policies\Concerns\AuthorizesGovernanceScope;
use App\Services\GovernancePortalAccessService;

class AssemblyPolicy
{
    use AuthorizesGovernanceScope;

    public function view(User $user, Assembly $assembly): bool
    {
        return $this->governancePermitted($user, $assembly, 'view_assemblies') || $this->owner($user, $assembly);
    }

    public function update(User $user, Assembly $assembly): bool
    {
        return in_array($assembly->status, ['draft', 'preparing'], true) && $this->governancePermitted($user, $assembly, 'manage_assemblies');
    }

    public function transition(User $user, Assembly $assembly): bool
    {
        return $this->governancePermitted($user, $assembly, 'transition_assemblies');
    }

    public function issue(User $user, Assembly $assembly): bool
    {
        return $this->governancePermitted($user, $assembly, 'issue_convocations');
    }

    public function vote(User $user, Assembly $assembly): bool
    {
        return $this->governancePermitted($user, $assembly, 'record_ballots');
    }

    public function ownerPortal(User $user, Assembly $assembly): bool
    {
        return $this->owner($user, $assembly);
    }

    private function owner(User $user, Assembly $assembly): bool
    {
        return (bool) app(GovernancePortalAccessService::class)->electorate($assembly,$user);
    }
}
