<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class FinancialTransfer extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Financial transfers are immutable.'));
        static::deleting(fn () => throw new \LogicException('Financial transfers cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['transferred_on' => 'date:Y-m-d'];
    }

    public function sourceAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'source_account_id');
    }

    public function destinationAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_account_id');
    }

    public function movements()
    {
        return $this->hasMany(FinancialAccountMovement::class);
    }
}
