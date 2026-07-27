<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreventiveIntervention extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'schedule_snapshot' => 'array', 'checklist_snapshot' => 'array', 'completion_result' => 'array', 'completed_at' => 'datetime'];
    }

    public function plan()
    {
        return $this->belongsTo(PreventiveMaintenancePlan::class, 'preventive_maintenance_plan_id');
    }

    public function workOrders()
    {
        return $this->hasMany(MaintenanceWorkOrder::class);
    }

    public function attachments()
    {
        return $this->morphMany(MaintenanceAttachment::class, 'attachable');
    }
}
