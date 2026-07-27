<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblyQuorumSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quorum_met' => 'boolean', 'input_snapshot' => 'array', 'calculated_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }
}
