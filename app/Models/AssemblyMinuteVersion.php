<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblyMinuteVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['frozen_payload' => 'array', 'signatures' => 'array', 'signed_at' => 'datetime'];
    }

    public function minutes()
    {
        return $this->belongsTo(AssemblyMinutes::class, 'assembly_minutes_id');
    }
}
