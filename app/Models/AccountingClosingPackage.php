<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingClosingPackage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot_data' => 'array',
            'readiness_results' => 'array',
            'trial_balance_totals' => 'array',
            'prepared_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'stale_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $package) {
            if (! in_array($package->getOriginal('state'), ['approved', 'executing', 'closed', 'carry_forward_pending', 'carry_forward_completed', 'reopened', 'superseded'], true)) {
                return;
            }
            $allowed = [
                'state', 'executed_by', 'executed_at', 'stale_at', 'stale_reason_code',
                'closing_entry_id', 'carry_forward_batch_id', 'updated_at',
            ];
            if (array_diff(array_keys($package->getDirty()), $allowed)) {
                throw new LogicException('Approved closing package evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Closing packages are durable evidence and cannot be deleted.'));
    }

    public function configuration()
    {
        return $this->belongsTo(AccountingClosingConfiguration::class, 'accounting_closing_configuration_id');
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function periodSnapshots()
    {
        return $this->hasMany(AccountingClosingPeriodSnapshot::class);
    }

    public function transitions()
    {
        return $this->hasMany(AccountingClosingTransition::class);
    }
}
