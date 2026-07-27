<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['deadline_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function electorate()
    {
        return $this->belongsTo(AssemblyElectorate::class);
    }

    public function attempts()
    {
        return $this->hasMany(DecisionDeliveryAttempt::class);
    }
}
