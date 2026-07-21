<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class FundCall extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'validated_at' => 'datetime', 'cancelled_at' => 'datetime', 'metadata' => 'array'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function lines()
    {
        return $this->hasMany(FundCallLine::class)->orderBy('sort_order');
    }

    public function charges()
    {
        return $this->hasMany(LotCharge::class);
    }
}
