<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingOpeningLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $guard = fn (self $line) => $line->batch()->where('status', '!=', 'draft')->exists()
            ? throw new LogicException('Reviewed opening-balance lines are immutable.')
            : null;
        static::updating($guard);
        static::deleting($guard);
    }

    public function batch()
    {
        return $this->belongsTo(AccountingOpeningBatch::class, 'accounting_opening_batch_id');
    }

    public function ledgerAccount()
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
