<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyAgendaVersion extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['frozen_payload' => 'array', 'frozen_at' => 'datetime', 'issued_at' => 'datetime', 'opened_for_session_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function items()
    {
        return $this->hasMany(AssemblyAgendaItem::class, 'agenda_version_id');
    }
}
