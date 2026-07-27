<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblyMinutesApproval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
