<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRequestTransition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime'];
    }

    public function request()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
