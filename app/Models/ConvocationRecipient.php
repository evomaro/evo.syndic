<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConvocationRecipient extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function convocation()
    {
        return $this->belongsTo(Convocation::class);
    }

    public function electorate()
    {
        return $this->belongsTo(AssemblyElectorate::class);
    }

    public function attempts()
    {
        return $this->hasMany(ConvocationDeliveryAttempt::class);
    }
}
