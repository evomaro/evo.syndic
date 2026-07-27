<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingClosingPeriodSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['readiness_results' => 'array', 'closed_at' => 'datetime'];
    }
}
