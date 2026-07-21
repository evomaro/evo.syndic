<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleGeneration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation_date' => 'date:Y-m-d', 'template_snapshot' => 'array'];
    }

    public function fundCallSchedule()
    {
        return $this->belongsTo(FundCallSchedule::class);
    }

    public function fundCall()
    {
        return $this->belongsTo(FundCall::class);
    }
}
