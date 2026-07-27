<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lot extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'surface' => 'decimal:2'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function entrance()
    {
        return $this->belongsTo(Entrance::class);
    }

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function ownerships()
    {
        return $this->hasMany(LotOwnership::class);
    }

    public function owners()
    {
        return $this->belongsToMany(Contact::class, 'lot_ownerships')->withPivot(['starts_on', 'ends_on', 'ownership_percentage', 'is_primary_contact']);
    }

    public function activeOwnerships(?string $date = null)
    {
        $date ??= now()->toDateString();

        return $this->ownerships()->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    public function occupancies()
    {
        return $this->hasMany(LotOccupancy::class);
    }

    public function activeOccupancies(?string $date = null)
    {
        $date ??= now()->toDateString();

        return $this->occupancies()->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    public function allocationValues()
    {
        return $this->hasMany(LotAllocationValue::class);
    }

    public function charges()
    {
        return $this->hasMany(LotCharge::class);
    }
}
