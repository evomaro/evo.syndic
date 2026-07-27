<?php

namespace App\Services;

use App\Models\SupplierContract;

class ContractExpirationNotificationService
{
    public function __construct(private ManagerNotificationService $notifications) {}

    public function dispatch(int $days = 30): int
    {
        SupplierContract::query()->where('status', 'active')->whereNotNull('ends_on')->whereDate('ends_on', '<', today())->update(['status' => 'expired']);
        $sent = 0;
        SupplierContract::query()->where('status', 'active')->whereNotNull('ends_on')->whereBetween('ends_on', [today(), today()->addDays($days)])->with(['organization', 'residence'])->each(function ($contract) use (&$sent) {
            $sent += $this->notifications->dispatch($contract->organization, $contract->residence, 'contract_expiring', "contract:{$contract->id}:expiring:{$contract->ends_on->toDateString()}", ['title' => 'Contrat arrivant à échéance', 'message' => 'Le contrat :title arrive à échéance le :date.', 'parameters' => ['title' => $contract->title, 'date' => $contract->ends_on->format('d/m/Y')], 'data' => ['contract_id' => $contract->id]], route('supplier-contracts.show', $contract), true);
        });

        return $sent;
    }
}
