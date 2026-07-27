<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectorateCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_payload' => 'array', 'after_payload' => 'array', 'corrected_at' => 'datetime'];
    }
}
