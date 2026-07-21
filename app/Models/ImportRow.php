<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_values' => 'array', 'before_values' => 'array', 'after_values' => 'array', 'processed_at' => 'datetime'];
    }

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
