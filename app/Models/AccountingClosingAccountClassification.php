<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingClosingAccountClassification extends Model
{
    public const ROLES = [
        'permanent',
        'temporary_income',
        'temporary_expense',
        'result_transfer',
        'excluded',
        'parent_non_posting',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'carry_forward_eligible' => 'boolean',
            'requires_third_party_dimensions' => 'boolean',
            'requires_analytical_dimensions' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $classification) {
            if ($classification->getOriginal('review_status') === 'approved') {
                throw new LogicException('Approved closing classifications are immutable.');
            }
        });
        static::deleting(fn (self $classification) => $classification->review_status === 'approved'
            ? throw new LogicException('Approved closing classifications cannot be deleted.')
            : null);
    }

    public function account()
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
