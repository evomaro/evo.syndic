<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingJournal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $journal) => $journal->entries()->whereIn('status', ['posted', 'reversed'])->exists()
            ? throw new LogicException('A journal used by posted entries cannot be deleted.')
            : null);
    }

    public function entries()
    {
        return $this->hasMany(JournalEntry::class);
    }
}
