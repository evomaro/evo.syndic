<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ChecksumRepairHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Checksum repair history is immutable.'));
        static::deleting(fn () => throw new LogicException('Checksum repair history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'evidence_summary' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
