<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceSubmission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted_on' => 'date', 'recorded_at' => 'datetime'];
    }
}
