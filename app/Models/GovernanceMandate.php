<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class GovernanceMandate extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'activated_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function appointingResolution()
    {
        return $this->belongsTo(AssemblyResolution::class, 'appointing_resolution_id');
    }
}
