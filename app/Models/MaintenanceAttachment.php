<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceAttachment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function attachable()
    {
        return $this->morphTo();
    }
}
