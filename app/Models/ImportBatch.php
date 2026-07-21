<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['column_mapping' => 'array', 'report' => 'array', 'completed_at' => 'datetime', 'rolled_back_at' => 'datetime', 'processing_started_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(ImportRow::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
