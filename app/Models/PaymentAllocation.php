<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allocated_on' => 'date:Y-m-d', 'reversed_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function charge()
    {
        return $this->belongsTo(LotCharge::class, 'lot_charge_id');
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
