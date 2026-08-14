<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class FundCallSchedule extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['template' => 'array', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'next_generation_on' => 'date:Y-m-d', 'active' => 'boolean', 'auto_validate' => 'boolean', 'last_generated_at' => 'datetime', 'last_failed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (FundCallSchedule $schedule) {
            if (! array_key_exists('auto_validate', $schedule->getAttributes())
                && $schedule->residence()->with('organization')->first()?->organization?->experience_mode === 'essential') {
                $schedule->auto_validate = true;
            }
        });
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function generations()
    {
        return $this->hasMany(ScheduleGeneration::class);
    }
}
