<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyAttendanceRecord extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['arrived_at' => 'datetime', 'departed_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function electorate()
    {
        return $this->belongsTo(AssemblyElectorate::class);
    }

    public function events()
    {
        return $this->hasMany(AssemblyAttendanceEvent::class, 'attendance_record_id');
    }
}
