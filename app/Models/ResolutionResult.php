<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResolutionResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['adopted' => 'boolean', 'rule_snapshot' => 'array', 'ballot_snapshot' => 'array', 'finalized_at' => 'datetime'];
    }

    public function resolution()
    {
        return $this->belongsTo(AssemblyResolution::class);
    }
}
