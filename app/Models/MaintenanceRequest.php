<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class MaintenanceRequest extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date', 'contact_visible_to_assignees' => 'boolean', 'sla_snapshot' => 'array',
            'submitted_at' => 'datetime', 'acknowledged_at' => 'datetime', 'scheduled_at' => 'datetime', 'approved_at' => 'datetime',
            'rejected_at' => 'datetime', 'started_at' => 'datetime', 'resolved_at' => 'datetime',
            'closed_at' => 'datetime', 'cancelled_at' => 'datetime', 'ack_deadline_at' => 'datetime',
            'schedule_deadline_at' => 'datetime', 'resolution_deadline_at' => 'datetime', 'reopen_deadline_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Maintenance requests preserve immutable operational history.'));
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

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function equipment()
    {
        return $this->belongsTo(MaintenanceEquipment::class, 'equipment_id');
    }

    public function category()
    {
        return $this->belongsTo(MaintenanceCategory::class, 'maintenance_category_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function transitions()
    {
        return $this->hasMany(MaintenanceRequestTransition::class);
    }

    public function assignments()
    {
        return $this->hasMany(MaintenanceAssignment::class);
    }

    public function updates()
    {
        return $this->hasMany(MaintenanceRequestUpdate::class);
    }

    public function quotations()
    {
        return $this->hasMany(MaintenanceQuotation::class);
    }

    public function workOrders()
    {
        return $this->hasMany(MaintenanceWorkOrder::class);
    }

    public function attachments()
    {
        return $this->morphMany(MaintenanceAttachment::class, 'attachable');
    }

    public function slaEvents()
    {
        return $this->hasMany(MaintenanceSlaEvent::class);
    }
}
