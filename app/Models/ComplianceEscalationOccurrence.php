<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceEscalationOccurrence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }
}
