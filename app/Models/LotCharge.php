<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotCharge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime', 'validation_snapshot' => 'array'];
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function fundCall()
    {
        return $this->belongsTo(FundCall::class);
    }

    public function line()
    {
        return $this->belongsTo(FundCallLine::class, 'fund_call_line_id');
    }

    public function billedContact()
    {
        return $this->belongsTo(Contact::class, 'billed_contact_id');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function getAllocatedCentsAttribute(): int
    {
        return (int) $this->allocations()->whereNull('reversed_at')->sum('amount_cents');
    }

    public function getOutstandingCentsAttribute(): int
    {
        return $this->cancelled_at ? 0 : max(0, (int) $this->amount_cents - $this->allocated_cents);
    }
}
