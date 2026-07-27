<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblyTransition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime', 'snapshot' => 'array'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }
}
