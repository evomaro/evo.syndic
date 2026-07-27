<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Support\Facades\DB;

class ManagerNotificationService
{
    public function dispatch(Organization $organization, Residence $residence, string $eventType, string $eventKey, array $copy, string $url, bool $apply = true): int
    {
        $sent = 0;
        $users = User::query()->whereHas('organizations', fn ($query) => $query->where('organizations.id', $organization->id))
            ->where(function ($query) use ($organization, $residence) {
                $query->whereHas('organizations', fn ($memberships) => $memberships->where('organizations.id', $organization->id)->where('all_residences', true))
                    ->orWhereHas('residences', fn ($residences) => $residences->whereKey($residence->id));
            })->get();

        foreach ($users as $user) {
            if (! $user->canInOrganization('view_expenses', $organization)) {
                continue;
            }
            $preference = NotificationPreference::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->first();
            if (in_array($eventType, $preference?->muted_events ?? [], true)) {
                continue;
            }
            $channels = array_filter(['database' => $preference?->database_enabled ?? true, 'mail' => $preference?->email_enabled ?? true]);
            foreach (array_keys($channels) as $channel) {
                if (! $apply) {
                    $sent++;

                    continue;
                }
                $inserted = DB::table('notification_dispatches')->insertOrIgnore([
                    'user_id' => $user->id, 'organization_id' => $organization->id, 'residence_id' => $residence->id,
                    'event_key' => $eventKey, 'event_type' => $eventType, 'channel' => $channel, 'status' => 'queued',
                    'attempt_count' => 1, 'last_attempted_at' => now(), 'dispatched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! $inserted) {
                    $retry = DB::table('notification_dispatches')->where('user_id', $user->id)->where('event_key', $eventKey)->where('channel', $channel)->where('status', 'failed');
                    if (! $retry->exists()) {
                        continue;
                    }
                    $retry->update(['status' => 'queued', 'attempt_count' => DB::raw('attempt_count + 1'), 'last_attempted_at' => now(), 'last_error' => null, 'updated_at' => now()]);
                }
                $locale = in_array($user->preferred_language, ['fr', 'ar'], true) ? $user->preferred_language : 'fr';
                $payload = [
                    'type' => $eventType, 'organization_id' => $organization->id, 'residence_id' => $residence->id,
                    'language' => $locale, 'title' => __($copy['title'], [], $locale), 'message' => __($copy['message'], $copy['parameters'] ?? [], $locale), 'url' => $url,
                ] + ($copy['data'] ?? []);
                $user->notify(new PortalNotification($payload, [$channel], $eventKey));
                $sent++;
            }
        }

        return $sent;
    }
}
