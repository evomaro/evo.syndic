<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundCallLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_ids' => 'array', 'manual_allocations' => 'array'];
    }

    public function fundCall()
    {
        return $this->belongsTo(FundCall::class);
    }

    public function category()
    {
        return $this->belongsTo(ChargeCategory::class, 'charge_category_id');
    }

    public function allocationKey()
    {
        return $this->belongsTo(AllocationKey::class);
    }

    public function charges()
    {
        return $this->hasMany(LotCharge::class);
    }
}
