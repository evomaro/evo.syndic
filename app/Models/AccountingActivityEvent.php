<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingActivityEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_evidence' => 'array', 'after_evidence' => 'array', 'occurred_at' => 'datetime'];
    }
}
