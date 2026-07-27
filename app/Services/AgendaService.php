<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyAgendaItem;
use App\Models\GovernanceRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgendaService
{
    public function __construct(private GovernanceRuleService $rules) {}

    public function add(Assembly $assembly, array $data, GovernanceRuleVersion $rule, User $actor): AssemblyAgendaItem
    {
        if (! in_array($assembly->status, ['draft', 'preparing'], true)) {
            throw ValidationException::withMessages(['agenda' => __('L’ordre du jour est figé.')]);
        }

        return DB::transaction(function () use ($assembly, $data, $rule, $actor) {
            $item = $assembly->agendaItems()->create(collect($data)->only(['display_order', 'title_fr', 'title_ar', 'explanation_fr', 'explanation_ar', 'proposed_text_fr', 'proposed_text_ar', 'category', 'financial_impact_cents', 'resident_visible', 'internal_notes'])->all());
            if (! empty($data['resolution'])) {
                $item->resolution()->create(collect($data['resolution'])->only(['budget_id', 'supplier_contract_id', 'supplier_id', 'maintenance_equipment_id', 'maintenance_request_id', 'maintenance_work_order_id', 'code', 'proposed_text_fr', 'proposed_text_ar', 'category', 'financial_snapshot'])->all() + ['assembly_id' => $assembly->id, 'governance_rule_version_id' => $rule->id]);
            }
            activity()->performedOn($item)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id])->log('governance.agenda_item_created');

            return $item->fresh('resolution');
        });
    }

    public function freeze(Assembly $assembly, User $actor): int
    {
        return DB::transaction(function () use ($assembly, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->with('agendaItems.resolution.ruleVersion')->lockForUpdate()->firstOrFail();
            if ($assembly->status !== 'preparing' || $assembly->agendaItems->isEmpty()) {
                throw ValidationException::withMessages(['agenda' => __('Un ordre du jour préparé est requis.')]);
            }
            foreach ($assembly->agendaItems as $item) {
                $item->update(['status' => 'frozen', 'frozen_at' => now('UTC')]);
                if ($item->resolution) {
                    $payload = $this->rules->payload($item->resolution->ruleVersion);
                    $item->resolution->ruleSnapshot()->create(['governance_rule_version_id' => $item->resolution->governance_rule_version_id, 'payload' => $payload, 'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'snapshotted_at' => now('UTC'), 'snapshotted_by' => $actor->id]);
                    $item->resolution->update(['status' => 'authorized', 'rule_snapshotted_at' => now('UTC')]);
                }
            }
            app(AgendaVersionService::class)->freeze($assembly->fresh(), $actor);
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'items' => $assembly->agendaItems->count()])->log('governance.agenda_frozen');

            return $assembly->agendaItems->count();
        });
    }

    public function amend(AssemblyAgendaItem $item, array $data, User $actor, string $reason): AssemblyAgendaItem
    {
        return DB::transaction(function () use ($item, $data, $actor, $reason) {
            $item = AssemblyAgendaItem::query()->whereKey($item->id)->with('assembly')->lockForUpdate()->firstOrFail();
            if ($item->assembly->status !== 'convocation_issued' || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['agenda' => __('Une modification post-convocation exige le workflow d’amendement et un motif détaillé.')]);
            }
            $item->update(['status' => 'removed', 'removed_at' => now('UTC'), 'removed_by' => $actor->id, 'amendment_reason' => trim($reason)]);
            $replacement = $item->assembly->agendaItems()->create($item->only(['display_order', 'title_fr', 'title_ar', 'explanation_fr', 'explanation_ar', 'proposed_text_fr', 'proposed_text_ar', 'category', 'financial_impact_cents', 'resident_visible']) + collect($data)->only(['title_fr', 'title_ar', 'explanation_fr', 'explanation_ar', 'proposed_text_fr', 'proposed_text_ar'])->all() + ['parent_item_id' => $item->id, 'version' => $item->version + 1, 'status' => 'draft', 'amendment_reason' => trim($reason)]);
            app(AgendaVersionService::class)->freeze($item->assembly->fresh(), $actor, trim($reason));
            activity()->performedOn($replacement)->causedBy($actor)->withProperties(['organization_id' => $item->assembly->organization_id, 'residence_id' => $item->assembly->residence_id, 'replaces_item_id' => $item->id, 'reason' => trim($reason)])->log('governance.agenda_amended');

            return $replacement;
        });
    }
}
