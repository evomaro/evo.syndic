<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAccount extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'opening_balance_on' => 'date:Y-m-d'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function movements()
    {
        return $this->hasMany(FinancialAccountMovement::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getCurrentBalanceCentsAttribute(): int
    {
        $net = (int) $this->movements()->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_cents ELSE -amount_cents END), 0) AS net")->value('net');

        return (int) $this->opening_balance_cents + $net;
    }
}
