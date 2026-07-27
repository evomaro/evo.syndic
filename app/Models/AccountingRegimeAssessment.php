<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingRegimeAssessment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'reason_codes' => 'array',
            'explanation_fr' => 'array',
            'explanation_ar' => 'array',
            'assessed_at' => 'datetime',
        ];
    }

    public function book()
    {
        return $this->belongsTo(AccountingBook::class, 'accounting_book_id');
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }
}
