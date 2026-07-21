<?php

namespace App\Services;

use App\Models\AllocationKey;
use App\Models\Building;
use App\Models\Contact;
use App\Models\Entrance;
use App\Models\Floor;
use App\Models\ImportBatch;
use App\Models\Lot;
use App\Models\LotAllocationValue;
use App\Models\LotOccupancy;
use App\Models\LotOwnership;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Builder;

class ActivityScope
{
    public function apply(Builder $query, Organization $organization): Builder
    {
        $residenceIds = $organization->residences()->pluck('id');
        $buildingIds = Building::whereIn('residence_id', $residenceIds)->pluck('id');
        $lotIds = Lot::whereIn('residence_id', $residenceIds)->pluck('id');
        $keyIds = AllocationKey::whereIn('residence_id', $residenceIds)->pluck('id');
        $subjects = [
            Organization::class => [$organization->id], Residence::class => $residenceIds,
            Building::class => $buildingIds, Entrance::class => Entrance::whereIn('building_id', $buildingIds)->pluck('id'),
            Floor::class => Floor::whereIn('building_id', $buildingIds)->pluck('id'), Lot::class => $lotIds,
            Contact::class => $organization->contacts()->pluck('id'), LotOwnership::class => LotOwnership::whereIn('lot_id', $lotIds)->pluck('id'),
            LotOccupancy::class => LotOccupancy::whereIn('lot_id', $lotIds)->pluck('id'), AllocationKey::class => $keyIds,
            LotAllocationValue::class => LotAllocationValue::whereIn('allocation_key_id', $keyIds)->pluck('id'),
            ImportBatch::class => ImportBatch::where('organization_id', $organization->id)->pluck('id'),
            TeamInvitation::class => TeamInvitation::where('organization_id', $organization->id)->pluck('id'),
        ];

        return $query->where(function (Builder $outer) use ($subjects) {
            foreach ($subjects as $type => $ids) {
                if (count($ids)) {
                    $outer->orWhere(fn (Builder $inner) => $inner->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            }
        });
    }
}
