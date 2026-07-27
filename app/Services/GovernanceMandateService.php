<?php

namespace App\Services;

use App\Models\GovernanceMandate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GovernanceMandateService
{
    public function create(array $data, User $actor): GovernanceMandate
    {
        return DB::transaction(function () use ($data, $actor) {
            if (empty($data['user_id']) && empty($data['contact_id'])) {
                throw ValidationException::withMessages(['holder' => __('Le titulaire du mandat est obligatoire.')]);
            }if ($data['ends_on'] < $data['starts_on']) {
                throw ValidationException::withMessages(['ends_on' => __('La date de fin doit suivre la date de début.')]);
            }$m = GovernanceMandate::create($data + ['status' => 'draft']);
            activity()->performedOn($m)->causedBy($actor)->withProperties(['organization_id' => $m->organization_id, 'residence_id' => $m->residence_id, 'role' => $m->role])->log('governance.mandate_created');

            return $m;
        });
    }

    public function transition(GovernanceMandate $mandate, string $to, User $actor, string $reason): GovernanceMandate
    {
        return DB::transaction(function () use ($mandate, $to, $actor, $reason) {
            $mandate = GovernanceMandate::query()->whereKey($mandate->id)->lockForUpdate()->firstOrFail();
            $allowed = ['draft' => ['active'], 'active' => ['renewed', 'revoked', 'resigned', 'expired'], 'renewed' => [], 'revoked' => [], 'resigned' => [], 'expired' => []];
            if (! in_array($to, $allowed[$mandate->status] ?? [], true) || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['mandate' => __('Transition de mandat invalide ou motif insuffisant.')]);
            }if ($to === 'active' && in_array($mandate->role, ['syndic', 'deputy_syndic'], true)) {
                $slot = $mandate->role;
                if (GovernanceMandate::where('residence_id', $mandate->residence_id)->where('active_slot', $slot)->whereKeyNot($mandate->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['mandate' => __('Un mandat actif existe déjà pour ce rôle.')]);
                }$mandate->active_slot = $slot;
            }$from = $mandate->status;
            $changes = ['status' => $to, 'active_slot' => $to === 'active' ? $mandate->active_slot : null];
            if ($to === 'active') {
                $changes += ['activated_at' => now('UTC'), 'activated_by' => $actor->id];
            } else {
                $changes += ['ended_at' => now('UTC'), 'ended_by' => $actor->id, 'end_reason' => trim($reason)];
            }$mandate->update($changes);
            activity()->performedOn($mandate)->causedBy($actor)->withProperties(['organization_id' => $mandate->organization_id, 'residence_id' => $mandate->residence_id, 'from' => $from, 'to' => $to, 'reason' => trim($reason)])->log('governance.mandate_transitioned');

            return $mandate->fresh();
        });
    }
}
