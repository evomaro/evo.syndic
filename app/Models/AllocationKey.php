<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AllocationKey extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'applies_to_all_lots' => 'boolean', 'active' => 'boolean', 'expected_total' => 'decimal:4'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function values()
    {
        return $this->hasMany(LotAllocationValue::class);
    }

    public function lots()
    {
        return $this->belongsToMany(Lot::class, 'allocation_key_lot');
    }
}
