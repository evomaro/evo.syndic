<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyAttendanceRecord;
use App\Models\AssemblyElectorate;
use App\Models\AssemblyProxy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendanceProxyService
{
    public function record(Assembly $assembly, AssemblyElectorate $electorate, string $status, User $actor, ?string $reason = null): AssemblyAttendanceRecord
    {
        return DB::transaction(function () use ($assembly, $electorate, $status, $actor, $reason) {
            $assembly = Assembly::query()->whereKey($assembly->id)->lockForUpdate()->firstOrFail();
            $this->sameScope($assembly, $electorate);
            if (! in_array($assembly->status, ['scheduled', 'in_session'], true) || ! in_array($status, ['present', 'represented', 'absent', 'excluded', 'ineligible'], true)) {
                throw ValidationException::withMessages(['attendance' => __('Présence invalide pour cette étape.')]);
            }
            if ($status === 'present' && AssemblyProxy::where('principal_electorate_id', $electorate->id)->where('status', 'verified')->exists()) {
                throw ValidationException::withMessages(['attendance' => __('Révoquez ou transférez d’abord le mandat actif.')]);
            }
            $record = AssemblyAttendanceRecord::query()->where('assembly_id', $assembly->id)->where('electorate_id', $electorate->id)->lockForUpdate()->first();
            $from = $record?->status;
            $payload = ['status' => $status, 'active_weight_numerator' => in_array($status, ['present', 'represented'], true) ? $electorate->voting_weight_numerator : 0, 'active_weight_denominator' => $electorate->voting_weight_denominator, 'recorded_by' => $actor->id];
            if ($status === 'present' && ! $record?->arrived_at) {
                $payload['arrived_at'] = now('UTC');
            }
            if ($status === 'absent' && $record?->arrived_at) {
                $payload['departed_at'] = now('UTC');
            }
            $record ??= new AssemblyAttendanceRecord(['assembly_id' => $assembly->id, 'electorate_id' => $electorate->id]);
            $record->fill($payload)->save();
            $record->events()->create(['from_status' => $from, 'to_status' => $status, 'weight_numerator' => $payload['active_weight_numerator'], 'weight_denominator' => $payload['active_weight_denominator'], 'actor_id' => $actor->id, 'reason' => $reason, 'effective_at' => now('UTC')]);
            activity()->performedOn($record)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'from' => $from, 'to' => $status, 'reason' => $reason])->log('governance.attendance_changed');

            return $record->fresh();
        });
    }

    public function submitProxy(Assembly $assembly, AssemblyElectorate $principal, ?User $representative, ?int $representativeContactId, UploadedFile $file, User $actor): AssemblyProxy
    {
        $this->sameScope($assembly, $principal);
        if ($representative?->id === $actor->id && $principal->contact->users()->whereKey($actor->id)->exists()) {
            throw ValidationException::withMessages(['proxy' => __('Un copropriétaire ne peut pas valider son propre mandat.')]);
        }
        $path = $file->store("governance/{$assembly->residence_id}/{$assembly->id}/proxies", 'local');
        $bytes = Storage::disk('local')->get($path);

        return AssemblyProxy::create(['assembly_id' => $assembly->id, 'principal_electorate_id' => $principal->id, 'representative_user_id' => $representative?->id, 'representative_contact_id' => $representativeContactId, 'status' => 'submitted', 'entitlement_weight_numerator' => $principal->voting_weight_numerator, 'entitlement_weight_denominator' => $principal->voting_weight_denominator, 'document_path' => $path, 'document_checksum' => hash('sha256', $bytes), 'submitted_at' => now('UTC')]);
    }

    public function verify(AssemblyProxy $proxy, User $actor): AssemblyProxy
    {
        return DB::transaction(function () use ($proxy, $actor) {
            $proxy = AssemblyProxy::query()->whereKey($proxy->id)->with(['assembly', 'principal.contact'])->lockForUpdate()->firstOrFail();
            if ($proxy->status === 'verified') {
                return $proxy;
            }
            if ($proxy->status !== 'submitted' || $proxy->principal->contact->users()->whereKey($actor->id)->exists()) {
                throw ValidationException::withMessages(['proxy' => __('Ce mandat ne peut pas être vérifié par cet acteur.')]);
            }
            $rules = config('governance.proxy');
            $existing = AssemblyProxy::where('assembly_id', $proxy->assembly_id)->where('status', 'verified')->where(function ($q) use ($proxy) {
                $q->when($proxy->representative_user_id, fn ($q) => $q->where('representative_user_id', $proxy->representative_user_id))->when(! $proxy->representative_user_id, fn ($q) => $q->where('representative_contact_id', $proxy->representative_contact_id));
            })->lockForUpdate()->get();
            if ($existing->count() >= $rules['max_principals']) {
                throw ValidationException::withMessages(['proxy' => __('Le mandataire représente déjà le maximum autorisé de copropriétaires.')]);
            }
            $totalEligible = (int) $proxy->assembly->electorate()->where('eligibility_status', 'eligible')->sum('voting_weight_numerator');
            $represented = (int) $existing->sum('entitlement_weight_numerator') + (int) $proxy->entitlement_weight_numerator;
            if ($represented * $rules['max_total_weight_denominator'] > $totalEligible * $rules['max_total_weight_numerator']) {
                throw ValidationException::withMessages(['proxy' => __('Le plafond légal de quote-part représentée serait dépassé.')]);
            }
            if ($proxy->principal->attendance?->status === 'present') {
                throw ValidationException::withMessages(['proxy' => __('Le mandant est déjà enregistré présent.')]);
            }
            $proxy->update(['status' => 'verified', 'verified_at' => now('UTC'), 'verified_by' => $actor->id, 'active_principal_slot' => 'principal:'.$proxy->principal_electorate_id]);
            $proxy->events()->create(['from_status' => 'submitted', 'to_status' => 'verified', 'actor_id' => $actor->id, 'transitioned_at' => now('UTC')]);
            $this->record($proxy->assembly, $proxy->principal, 'represented', $actor, 'Mandat écrit vérifié');
            activity()->performedOn($proxy)->causedBy($actor)->withProperties(['organization_id' => $proxy->assembly->organization_id, 'residence_id' => $proxy->assembly->residence_id])->log('governance.proxy_verified');
            app(GovernanceNotificationService::class)->electorateEvent($proxy->principal, 'proxy_accepted', "proxy:{$proxy->id}:accepted", ['title' => 'Mandat accepté', 'message' => 'Votre mandat écrit a été vérifié et accepté.'], route('owner-governance.show', $proxy->assembly));

            return $proxy->fresh();
        });
    }

    public function revoke(AssemblyProxy $proxy, User $actor, string $reason): AssemblyProxy
    {
        return DB::transaction(function () use ($proxy, $actor, $reason) {
            $proxy = AssemblyProxy::query()->whereKey($proxy->id)->with(['assembly', 'principal'])->lockForUpdate()->firstOrFail();
            if ($proxy->status !== 'verified' || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['proxy' => __('Un mandat vérifié et un motif détaillé sont requis.')]);
            }
            $proxy->update(['status' => 'revoked', 'revoked_at' => now('UTC'), 'revoked_by' => $actor->id, 'revocation_reason' => trim($reason), 'active_principal_slot' => null]);
            $proxy->events()->create(['from_status' => 'verified', 'to_status' => 'revoked', 'actor_id' => $actor->id, 'reason' => trim($reason), 'transitioned_at' => now('UTC')]);
            $this->record($proxy->assembly, $proxy->principal, 'absent', $actor, trim($reason));
            activity()->performedOn($proxy)->causedBy($actor)->withProperties(['organization_id' => $proxy->assembly->organization_id, 'residence_id' => $proxy->assembly->residence_id, 'reason' => trim($reason)])->log('governance.proxy_revoked');
            app(GovernanceNotificationService::class)->electorateEvent($proxy->principal, 'proxy_revoked', "proxy:{$proxy->id}:revoked", ['title' => 'Mandat révoqué', 'message' => 'Le mandat écrit a été révoqué. Consultez votre assemblée.'], route('owner-governance.show', $proxy->assembly));

            return $proxy->fresh();
        });
    }

    private function sameScope(Assembly $assembly, AssemblyElectorate $electorate): void
    {
        abort_unless($electorate->assembly_id === $assembly->id && $electorate->organization_id === $assembly->organization_id && $electorate->residence_id === $assembly->residence_id,404);
    }
}
