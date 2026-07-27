<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyAgendaVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgendaVersionService
{
    public function freeze(Assembly $assembly, User $actor, ?string $reason = null): AssemblyAgendaVersion
    {
        return DB::transaction(function () use ($assembly, $actor, $reason) {
            $assembly = Assembly::query()->whereKey($assembly->id)->with(['agendaItems' => fn ($q) => $q->whereIn('status', ['draft', 'frozen'])->orderBy('display_order')])->lockForUpdate()->firstOrFail();
            if ($assembly->agendaItems->isEmpty()) {
                throw ValidationException::withMessages(['agenda' => __('Un ordre du jour est requis.')]);
            }
            $payload = $assembly->agendaItems->map(fn ($item) => [
                'item_id' => $item->id, 'version' => $item->version, 'order' => $item->display_order,
                'title_fr' => $item->title_fr, 'title_ar' => $item->title_ar, 'category' => $item->category,
                'information_only' => (bool) $item->information_only, 'resolution_id' => $item->resolution?->id,
            ])->all();
            $versionNumber = (int) $assembly->agendaVersions()->max('version') + 1;
            $parent = $assembly->activeAgendaVersion;
            $version = $assembly->agendaVersions()->create([
                'organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id,
                'version' => $versionNumber, 'status' => 'frozen', 'parent_version_id' => $parent?->id,
                'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'frozen_payload' => $payload, 'change_reason' => $reason,
                'convocation_impact' => $assembly->convocations()->exists() ? 'professional_review_required' : 'none',
                'impact_reason' => $assembly->convocations()->exists() ? 'Agenda changed after a convocation was issued; the system cannot infer whether replacement service is legally required.' : null,
                'created_by' => $actor->id, 'frozen_at' => now('UTC'),
            ]);
            $assembly->agendaItems()->whereIn('id', collect($payload)->pluck('item_id'))->update(['agenda_version_id' => $version->id]);
            $assembly->update(['active_agenda_version_id' => $version->id]);
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'agenda_version_id' => $version->id, 'convocation_impact' => $version->convocation_impact])->log('governance.agenda_version_frozen');

            return $version;
        });
    }
}
