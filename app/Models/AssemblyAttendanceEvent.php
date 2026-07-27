<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblyAttendanceEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime'];
    }
}
