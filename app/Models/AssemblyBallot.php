<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyBallot extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ownership_unit_snapshot' => 'array', 'rule_snapshot' => 'array', 'entered_at' => 'datetime', 'finalized_at' => 'datetime'];
    }

    public function resolution()
    {
        return $this->belongsTo(AssemblyResolution::class);
    }

    public function electorate()
    {
        return $this->belongsTo(AssemblyElectorate::class);
    }

    public function corrections()
    {
        return $this->hasMany(BallotCorrection::class, 'ballot_id');
    }
}
