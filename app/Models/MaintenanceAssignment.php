<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function request()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
