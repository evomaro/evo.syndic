<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LotOccupancy extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary_occupant' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
