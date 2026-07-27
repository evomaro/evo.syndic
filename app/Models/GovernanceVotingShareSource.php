<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class GovernanceVotingShareSource extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'expected_total' => 'decimal:8', 'effective_from' => 'date', 'effective_until' => 'date', 'verified_at' => 'datetime', 'approved_at' => 'datetime'];
    }
}
