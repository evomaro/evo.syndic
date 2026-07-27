<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssemblyWorkflow
{
    private const TRANSITIONS = [
        'draft' => ['preparing', 'cancelled'],
        'preparing' => ['convocation_issued', 'cancelled'],
        'convocation_issued' => ['scheduled', 'postponed', 'cancelled'],
        'scheduled' => ['in_session', 'postponed', 'cancelled', 'adjourned_no_quorum'],
        'in_session' => ['deliberations_completed', 'adjourned_no_quorum'],
        'deliberations_completed' => ['minutes_prepared'],
        'minutes_prepared' => ['minutes_signed'],
        'minutes_signed' => ['decisions_notified'],
        'decisions_notified' => ['closed'],
        'postponed' => ['scheduled', 'cancelled'],
        'adjourned_no_quorum' => ['replaced_by_second_convocation'],
    ];

    public function transition(Assembly $assembly, string $to, User $actor, ?string $reason, string $idempotencyKey): Assembly
    {
        return DB::transaction(function () use ($assembly, $to, $actor, $reason, $idempotencyKey) {
            $assembly = Assembly::query()->whereKey($assembly->id)->lockForUpdate()->firstOrFail();
            if ($assembly->transitions()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $assembly;
            }
            $from = $assembly->status;
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Transition d’assemblée invalide.')]);
            }
            if (in_array($to, ['cancelled', 'postponed', 'adjourned_no_quorum'], true) && mb_strlen(trim((string) $reason)) < 10) {
                throw ValidationException::withMessages(['reason' => __('Un motif détaillé est obligatoire.')]);
            }
            $this->assertPrerequisites($assembly, $to);
            $changes = ['status' => $to];
            if ($to === 'in_session') {
                $changes['opened_at'] = now('UTC');
            }
            if ($to === 'deliberations_completed') {
                $changes['closed_at'] = now('UTC');
            }
            if ($to === 'cancelled') {
                $changes['cancellation_reason'] = trim((string) $reason);
            }
            if ($to === 'postponed') {
                $changes['postponement_reason'] = trim((string) $reason);
            }
            if ($to === 'adjourned_no_quorum') {
                $changes += ['adjournment_reason' => trim((string) $reason), 'quorum_status' => 'failed'];
            }
            $assembly->update($changes);
            $assembly->transitions()->create(['from_status' => $from, 'to_status' => $to, 'actor_id' => $actor->id, 'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'transitioned_at' => now('UTC'), 'snapshot' => $assembly->fresh()->only(['meeting_date', 'starts_at', 'location', 'quorum_status', 'minutes_status'])]);
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'from' => $from, 'to' => $to, 'reason' => $reason])->log('governance.assembly_transitioned');

            return $assembly->fresh();
        });
    }

    public function secondConvocation(Assembly $first, array $data, User $actor): Assembly
    {
        return DB::transaction(function () use ($first, $data, $actor) {
            $first = Assembly::query()->whereKey($first->id)->lockForUpdate()->firstOrFail();
            if ($first->status !== 'adjourned_no_quorum' || $first->secondConvocation()->exists()) {
                throw ValidationException::withMessages(['assembly' => __('Une seconde convocation existe déjà ou la première réunion n’a pas été ajournée pour défaut de quorum.')]);
            }
            $meetingDate = CarbonImmutable::parse($data['meeting_date'], $first->timezone);
            if ($meetingDate->lessThanOrEqualTo($first->meeting_date) || $meetingDate->diffInDays($first->meeting_date) > config('governance.second_convocation_max_days')) {
                throw ValidationException::withMessages(['meeting_date' => __('La seconde réunion doit intervenir dans les trente jours.')]);
            }
            $second = Assembly::create($first->only(['organization_id', 'residence_id', 'financial_exercise_id', 'type', 'convening_authority', 'location', 'timezone', 'created_by']) + [
                'parent_assembly_id' => $first->id, 'reference' => $data['reference'], 'convocation_number' => 2, 'status' => 'preparing',
                'meeting_date' => $data['meeting_date'], 'starts_at' => $data['starts_at'], 'expected_ends_at' => $data['expected_ends_at'] ?? null,
                'convocation_deadline_at' => $meetingDate->copy()->subDays(config('governance.notice_days')),
                'documents_available_at' => $meetingDate->copy()->subDays(config('governance.document_access_days')),
            ]);
            foreach ($first->agendaItems()->where('status', 'frozen')->with('resolution')->get() as $item) {
                $newItem = $second->agendaItems()->create($item->only(['version', 'display_order', 'title_fr', 'title_ar', 'explanation_fr', 'explanation_ar', 'proposed_text_fr', 'proposed_text_ar', 'category', 'financial_impact_cents', 'resident_visible']) + ['status' => 'draft']);
                if ($item->resolution) {
                    $newItem->resolution()->create($item->resolution->only(['governance_rule_version_id', 'budget_id', 'supplier_contract_id', 'supplier_id', 'maintenance_equipment_id', 'maintenance_request_id', 'maintenance_work_order_id', 'code', 'proposed_text_fr', 'proposed_text_ar', 'category', 'financial_snapshot']) + ['assembly_id' => $second->id, 'status' => 'draft']);
                }
            }
            $this->transition($first, 'replaced_by_second_convocation', $actor, 'Seconde convocation créée après défaut de quorum', 'second:'.$second->id);

            return $second;
        });
    }

    private function assertPrerequisites(Assembly $assembly, string $to): void
    {
        if ($to === 'convocation_issued' && (! $assembly->electorate()->exists() || ! $assembly->agendaItems()->where('status', 'frozen')->exists())) {
            throw ValidationException::withMessages(['assembly' => __('Le corps électoral et l’ordre du jour doivent être figés.')]);
        }
        if ($to === 'in_session' && ! $assembly->convocations()->exists()) {
            throw ValidationException::withMessages(['convocation' => __('Une convocation émise est obligatoire.')]);
        }
        if ($to === 'deliberations_completed' && $assembly->resolutions()->whereDoesntHave('results')->exists()) {
            throw ValidationException::withMessages(['resolutions' => __('Toutes les résolutions doivent être finalisées.')]);
        }
        if ($to === 'minutes_prepared' && $assembly->minutes?->status !== 'reviewed') {
            throw ValidationException::withMessages(['minutes' => __('Le procès-verbal doit être relu.')]);
        }
        if ($to === 'minutes_signed' && $assembly->minutes?->status !== 'signed') {
            throw ValidationException::withMessages(['minutes' => __('Le procès-verbal doit être signé.')]);
        }
        if ($to === 'decisions_notified' && $assembly->electorate()->where('eligibility_status', 'eligible')->whereDoesntHave('decisionNotifications', fn ($q) => $q->where('status', 'successful'))->exists()) {
            throw ValidationException::withMessages(['notifications' => __('Toutes les décisions doivent être notifiées ou faire l’objet d’une exception suivie.')]);
        }
        if ($to === 'adjourned_no_quorum' && ! $assembly->quorumSnapshots()->where('quorum_met', false)->exists()) {
            throw ValidationException::withMessages(['quorum' => __('Un constat de quorum insuffisant est obligatoire.')]);
        }
    }
}
