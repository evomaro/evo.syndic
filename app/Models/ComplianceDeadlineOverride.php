<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceDeadlineOverride extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous_due_on' => 'date', 'new_due_on' => 'date', 'overridden_at' => 'datetime'];
    }
}
