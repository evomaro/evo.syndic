<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingPostingRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
            'activated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule) {
            if (in_array($rule->getOriginal('status'), ['active', 'superseded'], true)) {
                throw new LogicException('Published posting-rule versions are immutable.');
            }
        });
        static::deleting(fn (self $rule) => $rule->status !== 'draft'
            ? throw new LogicException('Published posting-rule versions cannot be deleted.')
            : null);
    }

    public function book()
    {
        return $this->belongsTo(AccountingBook::class, 'accounting_book_id');
    }

    public function journal()
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }
}
