<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialExercise extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'locked_at' => 'datetime', 'metadata' => 'array'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function fundCalls()
    {
        return $this->hasMany(FundCall::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function accountingPeriods()
    {
        return $this->hasMany(AccountingPeriod::class);
    }
}
