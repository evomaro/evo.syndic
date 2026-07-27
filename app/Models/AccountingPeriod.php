<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'locked_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }
}
