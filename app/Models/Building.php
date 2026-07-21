<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function entrances()
    {
        return $this->hasMany(Entrance::class);
    }

    public function floors()
    {
        return $this->hasMany(Floor::class);
    }

    public function lots()
    {
        return $this->hasMany(Lot::class);
    }
}
