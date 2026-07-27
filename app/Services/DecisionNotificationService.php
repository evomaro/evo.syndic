<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyMinuteVersion;
use App\Models\DecisionNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecisionNotificationService
{
    public function prepare(Assembly $assembly, AssemblyMinuteVersion $signed, User $actor): int
    {
        return DB::transaction(function () use ($assembly, $signed, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->with('electorate')->lockForUpdate()->firstOrFail();
            if ($assembly->minutes?->signed_version_id !== $signed->id || $signed->status !== 'signed') {
                throw ValidationException::withMessages(['minutes' => __('Le procès-verbal signé est requis.')]);
            }$deadline = $signed->signed_at->copy()->addDays(config('governance.decision_notification_days'));
            $count = 0;
            foreach ($assembly->electorate->where('eligibility_status', 'eligible') as $e) {
                $count += DecisionNotification::insertOrIgnore(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'assembly_id' => $assembly->id, 'electorate_id' => $e->id, 'signed_minutes_version_id' => $signed->id, 'recipient_name_snapshot' => $e->contact_name_snapshot, 'address_snapshot' => $e->address_snapshot, 'deadline_at' => $deadline, 'idempotency_key' => "assembly:{$assembly->id}:electorate:{$e->id}:minutes:{$signed->id}", 'created_at' => now(), 'updated_at' => now()]);
                app(GovernanceNotificationService::class)->electorateEvent($e, 'signed_minutes_available', "minutes:{$signed->id}:electorate:{$e->id}", ['title' => 'Procès-verbal signé disponible', 'message' => 'Le procès-verbal signé et les décisions sont disponibles. La notification numérique ne remplace pas la remise légale.'], route('owner-governance.show', $assembly));
            }activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'records_created' => $count, 'deadline_at' => $deadline])->log('governance.decision_notifications_prepared');

            return $count;
        });
    }

    public function deliver(DecisionNotification $notification, string $channel, string $status, User $actor, ?string $reason = null): DecisionNotification
    {
        return DB::transaction(function () use ($notification, $channel, $status, $actor, $reason) {
            $notification = DecisionNotification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();
            if ($status === 'successful' && $notification->status === 'successful') {
                return $notification;
            }if (! in_array($channel, ['registered_mail', 'bailiff', 'hand_delivery_with_receipt', 'other_legal_method'], true) || ! in_array($status, ['successful', 'failed', 'returned'], true)) {
                throw ValidationException::withMessages(['delivery' => __('Remise de décision invalide.')]);
            }$notification->attempts()->create(['channel' => $channel, 'status' => $status, 'actor_id' => $actor->id, 'attempted_at' => now('UTC'), 'failure_reason' => $reason, 'success_key' => $status === 'successful' ? 'successful' : null]);
            $notification->update(['delivery_channel' => $channel, 'status' => $status, 'attempt_count' => $notification->attempt_count + 1, 'delivered_at' => $status === 'successful' ? now('UTC') : null, 'failure_reason' => $reason]);

            return $notification->fresh();
        });
    }
}
