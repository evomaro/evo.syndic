<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceApplicabilityProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }
}
