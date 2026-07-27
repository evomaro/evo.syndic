<?php

namespace App\Models;

use App\Services\AccountingMutationGuard;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class JournalEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if ($entry->status !== 'draft') {
                throw new LogicException('Journal entries must be created as drafts.');
            }
        });
        static::updating(function (self $entry) {
            if ($entry->isDirty('status') && ! app(AccountingMutationGuard::class)->active()) {
                throw new LogicException('Journal entry status changes require the accounting service.');
            }
            if (in_array($entry->getOriginal('status'), ['posted', 'reversed'], true)) {
                $allowed = ['status', 'reversed_by_id', 'reversed_by_actor', 'reversed_at', 'updated_at'];
                if (array_diff(array_keys($entry->getDirty()), $allowed)) {
                    throw new LogicException('Posted journal entries are immutable.');
                }
            }
        });
        static::deleting(fn (self $entry) => in_array($entry->status, ['posted', 'reversed'], true)
            ? throw new LogicException('Posted journal entries cannot be deleted.')
            : null);
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sequence');
    }

    public function period()
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function journal()
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
