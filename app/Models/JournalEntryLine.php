<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class JournalEntryLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        $guard = function (self $line) {
            if ($line->entry()->whereIn('status', ['posted', 'reversed'])->exists()) {
                throw new LogicException('Lines of posted entries are immutable.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
