<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyElectorate extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lot_ids' => 'array', 'ownership_fractions' => 'array', 'source_ownership_ids' => 'array', 'generated_after_cutoff' => 'boolean', 'snapshotted_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function corrections()
    {
        return $this->hasMany(ElectorateCorrection::class, 'electorate_id');
    }

    public function attendance()
    {
        return $this->hasOne(AssemblyAttendanceRecord::class, 'electorate_id');
    }

    public function ballots()
    {
        return $this->hasMany(AssemblyBallot::class, 'electorate_id');
    }

    public function decisionNotifications()
    {
        return $this->hasMany(DecisionNotification::class, 'electorate_id');
    }
}
