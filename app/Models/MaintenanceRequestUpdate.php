<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRequestUpdate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function request()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
