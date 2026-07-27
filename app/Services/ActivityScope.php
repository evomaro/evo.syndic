<?php

namespace App\Services;

use App\Models\AgendaQuestionSubmission;
use App\Models\AllocationKey;
use App\Models\Assembly;
use App\Models\AssemblyAgendaItem;
use App\Models\AssemblyBallot;
use App\Models\AssemblyElectorate;
use App\Models\AssemblyMinutes;
use App\Models\AssemblyProxy;
use App\Models\Building;
use App\Models\Contact;
use App\Models\Entrance;
use App\Models\Floor;
use App\Models\GovernanceDocument;
use App\Models\GovernanceMandate;
use App\Models\ImportBatch;
use App\Models\Lot;
use App\Models\LotAllocationValue;
use App\Models\LotOccupancy;
use App\Models\LotOwnership;
use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceQuotation;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceRequestUpdate;
use App\Models\MaintenanceWorkOrder;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Residence;
use App\Models\ResolutionExecutionAction;
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
            MaintenanceCategory::class => MaintenanceCategory::where('organization_id', $organization->id)->pluck('id'),
            MaintenanceEquipment::class => MaintenanceEquipment::where('organization_id', $organization->id)->pluck('id'),
            MaintenanceRequest::class => MaintenanceRequest::where('organization_id', $organization->id)->pluck('id'),
            MaintenanceRequestUpdate::class => MaintenanceRequestUpdate::whereHas('request', fn ($q) => $q->where('organization_id', $organization->id))->pluck('id'),
            MaintenanceQuotation::class => MaintenanceQuotation::where('organization_id', $organization->id)->pluck('id'),
            MaintenanceWorkOrder::class => MaintenanceWorkOrder::where('organization_id', $organization->id)->pluck('id'),
            PreventiveMaintenancePlan::class => PreventiveMaintenancePlan::where('organization_id', $organization->id)->pluck('id'),
            MaintenanceAttachment::class => MaintenanceAttachment::where('organization_id', $organization->id)->pluck('id'),
            Assembly::class => Assembly::where('organization_id', $organization->id)->pluck('id'),
            AssemblyAgendaItem::class => AssemblyAgendaItem::whereHas('assembly', fn ($q) => $q->where('organization_id', $organization->id))->pluck('id'),
            AssemblyElectorate::class => AssemblyElectorate::where('organization_id', $organization->id)->pluck('id'),
            AssemblyBallot::class => AssemblyBallot::where('organization_id', $organization->id)->pluck('id'),
            AssemblyProxy::class => AssemblyProxy::whereHas('assembly', fn ($q) => $q->where('organization_id', $organization->id))->pluck('id'),
            AssemblyMinutes::class => AssemblyMinutes::where('organization_id', $organization->id)->pluck('id'),
            GovernanceDocument::class => GovernanceDocument::where('organization_id', $organization->id)->pluck('id'),
            GovernanceMandate::class => GovernanceMandate::where('organization_id', $organization->id)->pluck('id'),
            AgendaQuestionSubmission::class => AgendaQuestionSubmission::where('organization_id', $organization->id)->pluck('id'),
            ResolutionExecutionAction::class => ResolutionExecutionAction::where('organization_id', $organization->id)->pluck('id'),
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
