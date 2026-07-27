<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BallotCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['corrected_at' => 'datetime'];
    }
}
