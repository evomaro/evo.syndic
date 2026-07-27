<?php

namespace App\Services;

use App\Models\SupplierContract;
use App\Models\User;
use Carbon\CarbonImmutable;

class SupplierContractRenewalService
{
    public function __construct(private SupplierContractWorkflow $workflow, private ManagerNotificationService $notifications) {}

    public function dispatch(CarbonImmutable $date, array $filters = [], bool $apply = false): array
    {
        $query = SupplierContract::query()->where('status', 'active')->where('renewal_type', 'automatic')->whereNotNull('ends_on')->with(['organization', 'residence']);
        if ($filters['organization'] ?? null) {
            $query->where('organization_id', $filters['organization']);
        }
        if ($filters['residence'] ?? null) {
            $query->where('residence_id', $filters['residence']);
        }
        $eligible = 0;
        $renewed = 0;
        $failed = 0;
        foreach ($query->get() as $contract) {
            if ($contract->renewals()->exists() || $contract->ends_on->gt($date->addDays((int) $contract->notice_days))) {
                continue;
            }
            $eligible++;
            $actor = User::query()->whereHas('organizations', fn ($q) => $q->where('organizations.id', $contract->organization_id))
                ->where(fn ($q) => $q->whereHas('organizations', fn ($memberships) => $memberships->where('organizations.id', $contract->organization_id)->where('all_residences', true))->orWhereHas('residences', fn ($residences) => $residences->whereKey($contract->residence_id)))
                ->get()->first(fn ($user) => $user->canInOrganization('manage_supplier_contracts', $contract->organization));
            $months = match ($contract->billing_frequency) {
                'monthly' => 1, 'quarterly' => 3, 'yearly' => 12, default => null
            };
            if (! $actor || ! $months || ! $contract->ends_on) {
                $failed++;
                if ($apply) {
                    $this->notifications->dispatch($contract->organization, $contract->residence, 'contract_renewal_failed', "contract:{$contract->id}:renewal-failed:{$contract->ends_on?->toDateString()}", ['title' => 'Renouvellement automatique impossible', 'message' => 'Le contrat :title doit être corrigé avant renouvellement.', 'parameters' => ['title' => $contract->title], 'data' => ['contract_id' => $contract->id]], route('supplier-contracts.show', $contract), true);
                }

                continue;
            }
            if (! $apply) {
                continue;
            }
            $starts = CarbonImmutable::parse($contract->ends_on)->addDay();
            $ends = $starts->addMonthsNoOverflow($months)->subDay();
            $successor = $this->workflow->renew($contract, $actor, $starts->toDateString(), $ends->toDateString(), __('Renouvellement automatique planifié.'), true);
            $renewed++;
            $this->notifications->dispatch($contract->organization, $contract->residence, 'contract_renewed', "contract:{$contract->id}:renewed:{$successor->id}", ['title' => 'Contrat renouvelé automatiquement', 'message' => 'Le contrat :title a été renouvelé.', 'parameters' => ['title' => $contract->title], 'data' => ['contract_id' => $successor->id]], route('supplier-contracts.show', $successor), true);
        }

        return compact('eligible', 'renewed', 'failed');
    }
}
