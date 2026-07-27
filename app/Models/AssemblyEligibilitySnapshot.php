<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyEligibilitySnapshot extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['eligibility_on' => 'date', 'findings' => 'array', 'ownership_boundary_at' => 'datetime', 'generated_at' => 'datetime', 'stale_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function interests()
    {
        return $this->hasMany(AssemblyElectorate::class, 'eligibility_snapshot_id');
    }

    public function votingShareSource()
    {
        return $this->belongsTo(GovernanceVotingShareSource::class);
    }
}
