<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingClosingTransition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Closing transition evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Closing transition evidence cannot be deleted.'));
    }
}
