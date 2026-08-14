<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;

class ExperienceCapabilities
{
    public const ESSENTIAL = [
        'dashboard.basic', 'residences.manage', 'cotisations.manage', 'cotisations.generate', 'cotisations.cancel', 'payments.record',
        'expenses.manage', 'accounts.view', 'transfers.create', 'reports.basic',
    ];

    public const ADVANCED = [
        'advanced_accounting', 'advanced_reports', 'advanced_administration',
    ];

    public function capabilities(User $user, Organization $organization): array
    {
        $permissions = app(MembershipAuthorization::class)->permissions($user, $organization);
        if ($organization->experience_mode === 'pro') {
            return [...self::ESSENTIAL, ...self::ADVANCED];
        }

        return array_values(array_filter(self::ESSENTIAL, fn (string $capability) => match ($capability) {
            'residences.manage' => $this->any($permissions, ['view_residences', 'manage_property_structure']),
            'cotisations.manage' => $this->any($permissions, ['view_finance', 'view_outstanding']),
            'cotisations.generate' => $this->all($permissions, ['create_fund_calls', 'validate_fund_calls']),
            'cotisations.cancel' => $this->any($permissions, ['cancel_fund_calls']),
            'payments.record' => $this->any($permissions, ['create_payments', 'validate_payments']),
            'expenses.manage' => $this->any($permissions, ['view_expenses', 'create_expenses']),
            'accounts.view' => $this->any($permissions, ['view_finance', 'manage_financial_accounts']),
            'transfers.create' => $this->any($permissions, ['create_payments', 'create_settlements', 'manage_financial_accounts']),
            'reports.basic' => $this->any($permissions, ['view_outstanding', 'view_statements', 'export_finance', 'export_expenses']),
            'dashboard.basic' => $this->any($permissions, ['view_residences', 'view_finance', 'view_expenses']),
            default => true,
        }));
    }

    public function allows(User $user, Organization $organization, string $capability): bool
    {
        return in_array($capability, $this->capabilities($user, $organization), true);
    }

    public function allowsRoute(User $user, Organization $organization, ?string $routeName): bool
    {
        if ($organization->experience_mode === 'pro' || ! $routeName) {
            return true;
        }

        $neutral = ['dashboard', 'profile.', 'context.', 'help.', 'notifications.', 'receipts.download', 'onboarding.', 'portal.', 'owner-finance.', 'owner-governance.'];
        foreach ($neutral as $prefix) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        $routeCapabilities = [
            'essential.dashboard' => 'dashboard.basic',
            'essential.cotisations.preview' => 'cotisations.generate',
            'essential.cotisations.generate' => 'cotisations.generate',
            'essential.cotisations.cancel' => 'cotisations.cancel',
            'essential.cotisations' => 'cotisations.manage',
            'essential.payments.' => 'payments.record',
            'essential.expenses' => 'expenses.manage',
            'essential.accounts' => 'accounts.view',
            'essential.transfers.' => 'transfers.create',
            'essential.reports' => 'reports.basic',
            'essential.experience.' => null,
            'residences.' => 'residences.manage',
            'structure.' => 'residences.manage',
            'buildings.' => 'residences.manage',
            'entrances.' => 'residences.manage',
            'floors.' => 'residences.manage',
            'lots.' => 'residences.manage',
            'ownerships.' => 'residences.manage',
            'occupancies.' => 'residences.manage',
            'contacts.' => 'residences.manage',
            'search.contacts' => 'residences.manage',
        ];
        foreach ($routeCapabilities as $prefix => $capability) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix)) {
                return $capability === null || $this->allows($user, $organization, $capability);
            }
        }

        return false;
    }

    private function any(array $permissions, array $expected): bool
    {
        return (bool) array_intersect($permissions, $expected);
    }

    private function all(array $permissions, array $expected): bool
    {
        return empty(array_diff($expected, $permissions));
    }
}
