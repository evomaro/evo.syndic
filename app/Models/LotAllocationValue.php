<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LotAllocationValue extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'decimal:4'];
    }

    public function allocationKey()
    {
        return $this->belongsTo(AllocationKey::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
}
