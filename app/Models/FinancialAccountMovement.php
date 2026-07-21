<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialAccountMovement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
