<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConvocationDeliveryAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }
}
