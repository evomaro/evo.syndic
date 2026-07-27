<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingSourcePosting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'failure_details' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $posting) {
            if (in_array($posting->getOriginal('status'), ['posted', 'reversed'], true)) {
                $allowed = ['status', 'reversal_entry_id', 'updated_at'];
                if (array_diff(array_keys($posting->getDirty()), $allowed)) {
                    throw new LogicException('Completed source-posting evidence is immutable.');
                }
            }
        });
        static::deleting(fn (self $posting) => in_array($posting->status, ['posted', 'reversed'], true)
            ? throw new LogicException('Completed source-posting evidence cannot be deleted.')
            : null);
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function reversalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function rule()
    {
        return $this->belongsTo(AccountingPostingRule::class, 'accounting_posting_rule_id');
    }
}
