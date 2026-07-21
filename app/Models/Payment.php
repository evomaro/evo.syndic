<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d', 'validated_at' => 'datetime', 'reversed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function payer()
    {
        return $this->belongsTo(Contact::class, 'payer_contact_id');
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('allocation_order');
    }

    public function documents()
    {
        return $this->morphMany(FinancialDocument::class, 'subject');
    }

    public function movements()
    {
        return $this->hasMany(FinancialAccountMovement::class);
    }

    public function getAllocatedCentsAttribute(): int
    {
        return (int) $this->allocations()->whereNull('reversed_at')->sum('amount_cents');
    }

    public function getCreditCentsAttribute(): int
    {
        return $this->status === 'validated' && $this->payer_contact_id
            ? $this->unallocated_cents
            : 0;
    }

    public function getUnallocatedCentsAttribute(): int
    {
        return $this->status === 'reversed' ? 0 : max(0, (int) $this->amount_cents - $this->allocated_cents);
    }
}
