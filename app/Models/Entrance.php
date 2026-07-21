<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entrance extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function floors()
    {
        return $this->hasMany(Floor::class);
    }
}
