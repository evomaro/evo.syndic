<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class SupplierSettlement extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $model) {
            $locked = ['organization_id', 'residence_id', 'financial_exercise_id', 'supplier_id', 'financial_account_id', 'number', 'settlement_date', 'amount_cents', 'method'];
            if ($model->getOriginal('status') !== 'draft' && array_intersect(array_keys($model->getDirty()), $locked)) {
                throw new \LogicException('Validated settlements are immutable.');
            }
        });
        static::deleting(fn (self $model) => $model->status !== 'draft' ? throw new \LogicException('Validated settlements cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return ['settlement_date' => 'date', 'validated_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function allocations()
    {
        return $this->hasMany(SupplierSettlementAllocation::class);
    }

    public function movements()
    {
        return $this->hasMany(FinancialAccountMovement::class);
    }

    public function documents()
    {
        return $this->morphMany(FinancialDocument::class, 'subject');
    }
}
