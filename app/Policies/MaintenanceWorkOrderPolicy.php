<?php

namespace App\Policies;

use App\Models\MaintenanceWorkOrder;
use App\Models\User;
use App\Policies\Concerns\AuthorizesMaintenanceScope;

class MaintenanceWorkOrderPolicy
{
    use AuthorizesMaintenanceScope;

    public function view(User $user, MaintenanceWorkOrder $order): bool
    {
        return $this->maintenancePermitted($user, $order, 'view_work_orders');
    }

    public function update(User $user, MaintenanceWorkOrder $order): bool
    {
        return $this->maintenancePermitted($user, $order, 'manage_work_orders');
    }

    public function complete(User $user, MaintenanceWorkOrder $order): bool
    {
        return $this->maintenancePermitted($user, $order, 'complete_work_orders');
    }

    public function validate(User $user, MaintenanceWorkOrder $order): bool
    {
        return $this->maintenancePermitted($user, $order, 'validate_work_orders');
    }

    public function invoice(User $user, MaintenanceWorkOrder $order): bool
    {
        return $this->maintenancePermitted($user, $order, 'create_work_order_invoices');
    }
}
