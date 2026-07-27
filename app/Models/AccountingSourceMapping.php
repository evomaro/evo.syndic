<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingSourceMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $mapping) {
            if ($mapping->getOriginal('review_status') === 'approved') {
                throw new LogicException('Approved accounting mappings are immutable; create a prospective successor.');
            }
        });
        static::deleting(fn (self $mapping) => $mapping->review_status === 'approved'
            ? throw new LogicException('Approved accounting mappings cannot be deleted.')
            : null);
    }

    public function account()
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
