<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class MaintenanceEquipment extends Model
{
    use LogsDomainActivity;

    protected $table = 'maintenance_equipment';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['installed_on' => 'date', 'warranty_expires_on' => 'date', 'retired_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $equipment) => ($equipment->requests()->exists() || $equipment->workOrders()->exists() || $equipment->preventivePlans()->exists())
            ? throw new \LogicException('Operational equipment history cannot be deleted.') : null);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function category()
    {
        return $this->belongsTo(MaintenanceCategory::class, 'maintenance_category_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function contract()
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    public function requests()
    {
        return $this->hasMany(MaintenanceRequest::class, 'equipment_id');
    }

    public function preventivePlans()
    {
        return $this->hasMany(PreventiveMaintenancePlan::class, 'equipment_id');
    }

    public function workOrders()
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'equipment_id');
    }

    public function attachments()
    {
        return $this->morphMany(MaintenanceAttachment::class, 'attachable');
    }
}
