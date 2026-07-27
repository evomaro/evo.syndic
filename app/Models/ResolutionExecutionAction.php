<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class ResolutionExecutionAction extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'dependency_action_ids' => 'array', 'completed_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function resolution()
    {
        return $this->belongsTo(AssemblyResolution::class);
    }

    public function events()
    {
        return $this->hasMany(ResolutionExecutionEvent::class);
    }
}
