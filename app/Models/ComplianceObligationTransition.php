<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceObligationTransition extends Model
{
    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime'];
    }
}
