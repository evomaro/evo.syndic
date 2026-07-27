<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $budget) {
            if ($budget->getOriginal('status') !== 'draft' && $budget->isDirty(['organization_id', 'residence_id', 'financial_exercise_id', 'version', 'title', 'total_budget_cents'])) {
                throw new \LogicException('Approved budgets are immutable. Create a revision instead.');
            }
        });
        static::deleting(fn (self $budget) => $budget->status !== 'draft' ? throw new \LogicException('Approved budgets cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'locked_at' => 'datetime', 'archived_at' => 'datetime', 'unlocked_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
