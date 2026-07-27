<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingOpeningBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $batch) {
            if ($batch->getOriginal('status') === 'posted') {
                throw new LogicException('Posted opening-balance batches are immutable.');
            }
        });
        static::deleting(fn (self $batch) => $batch->status !== 'draft'
            ? throw new LogicException('Reviewed opening-balance batches cannot be deleted.')
            : null);
    }

    public function lines()
    {
        return $this->hasMany(AccountingOpeningLine::class)->orderBy('sequence');
    }
}
