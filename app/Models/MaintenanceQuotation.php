<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class MaintenanceQuotation extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted_on' => 'date', 'valid_until' => 'date', 'accepted_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $quotation) => ($quotation->status === 'accepted' || $quotation->workOrders()->exists())
            ? throw new \LogicException('Accepted quotation history cannot be deleted.') : null);
    }

    public function request()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function contract()
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    public function workOrders()
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'accepted_quotation_id');
    }

    public function attachments()
    {
        return $this->morphMany(MaintenanceAttachment::class, 'attachable');
    }
}
