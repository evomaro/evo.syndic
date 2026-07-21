<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChargeCategory extends Model
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

    public function defaultAllocationKey()
    {
        return $this->belongsTo(AllocationKey::class, 'default_allocation_key_id');
    }
}
