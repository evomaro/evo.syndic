<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblyParticipant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['interest_scope' => 'array'];
    }
}
