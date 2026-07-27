<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResolutionRuleSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'snapshotted_at' => 'datetime'];
    }

    public function resolution()
    {
        return $this->belongsTo(AssemblyResolution::class);
    }
}
