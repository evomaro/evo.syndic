<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentGenerationAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_attempted_at' => 'datetime', 'failed_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
