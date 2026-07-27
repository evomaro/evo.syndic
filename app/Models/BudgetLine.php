<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $guard = fn (self $line) => $line->budget()->where('status', '!=', 'draft')->exists() ? throw new \LogicException('Approved budget lines are immutable.') : null;
        static::updating($guard);
        static::deleting($guard);
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
