<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class MaintenanceWorkOrder extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'planned_start_at' => 'datetime', 'planned_end_at' => 'datetime', 'actual_start_at' => 'datetime', 'completed_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $order) {
            if ($order->getOriginal('status') === 'validated' && $order->isDirty()) {
                throw new \LogicException('Validated work orders are immutable.');
            }
        });
        static::deleting(fn (self $order) => ($order->status !== 'draft' || $order->invoice()->exists())
            ? throw new \LogicException('Operational work order history cannot be deleted.') : null);
    }

    public function request()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function intervention()
    {
        return $this->belongsTo(PreventiveIntervention::class, 'preventive_intervention_id');
    }

    public function equipment()
    {
        return $this->belongsTo(MaintenanceEquipment::class, 'equipment_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function quotation()
    {
        return $this->belongsTo(MaintenanceQuotation::class, 'accepted_quotation_id');
    }

    public function contract()
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function invoice()
    {
        return $this->hasOne(SupplierInvoice::class, 'maintenance_work_order_id');
    }

    public function attachments()
    {
        return $this->morphMany(MaintenanceAttachment::class, 'attachable');
    }
}
