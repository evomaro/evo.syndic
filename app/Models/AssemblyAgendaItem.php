<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyAgendaItem extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['resident_visible' => 'boolean', 'frozen_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function resolution()
    {
        return $this->hasOne(AssemblyResolution::class, 'agenda_item_id');
    }
}
