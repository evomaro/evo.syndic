<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceSlaEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['deadline_at' => 'datetime', 'exceeded_at' => 'datetime'];
    }

    public function request()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }
}
