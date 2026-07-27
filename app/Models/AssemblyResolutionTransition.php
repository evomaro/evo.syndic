<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblyResolutionTransition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime'];
    }
}
