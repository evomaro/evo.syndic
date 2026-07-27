<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyMinutes extends Model
{
    use LogsDomainActivity;

    protected $table = 'assembly_minutes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'signed_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function versions()
    {
        return $this->hasMany(AssemblyMinuteVersion::class, 'assembly_minutes_id');
    }

    public function reviewedVersion()
    {
        return $this->belongsTo(AssemblyMinuteVersion::class, 'reviewed_version_id');
    }

    public function signedVersion()
    {
        return $this->belongsTo(AssemblyMinuteVersion::class, 'signed_version_id');
    }
}
