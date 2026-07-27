<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingClosingConfiguration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $configuration) {
            if (in_array($configuration->getOriginal('status'), ['approved', 'superseded'], true)) {
                $allowed = ['status', 'superseded_by_id', 'updated_at'];
                if (array_diff(array_keys($configuration->getDirty()), $allowed)) {
                    throw new LogicException('Approved closing configurations are immutable.');
                }
            }
        });
        static::deleting(fn (self $configuration) => $configuration->status !== 'draft'
            ? throw new LogicException('Reviewed closing configurations cannot be deleted.')
            : null);
    }

    public function classifications()
    {
        return $this->hasMany(AccountingClosingAccountClassification::class);
    }

    public function closingJournal()
    {
        return $this->belongsTo(AccountingJournal::class, 'closing_journal_id');
    }

    public function openingJournal()
    {
        return $this->belongsTo(AccountingJournal::class, 'opening_journal_id');
    }

    public function resultTransferAccount()
    {
        return $this->belongsTo(LedgerAccount::class, 'result_transfer_account_id');
    }
}
