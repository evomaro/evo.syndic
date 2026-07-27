<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceObligationAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
