<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyElectorate;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Support\Facades\DB;

class GovernanceNotificationService
{
    public function userEvent(User $user, Assembly $assembly, string $eventType, string $eventKey, array $copy, string $url): int
    {
        if (! $user->belongsToOrganization($assembly->organization_id)) {
            return 0;
        }$pref = NotificationPreference::where('user_id', $user->id)->where('organization_id', $assembly->organization_id)->first();
        if (in_array($eventType, $pref?->muted_events ?? [], true)) {
            return 0;
        }$sent = 0;
        foreach (array_keys(array_filter(['database' => $pref?->database_enabled ?? true, 'mail' => $pref?->email_enabled ?? true])) as $channel) {
            if (! DB::table('governance_notification_dispatches')->insertOrIgnore(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'assembly_id' => $assembly->id, 'user_id' => $user->id, 'event_type' => $eventType, 'event_key' => $eventKey, 'channel' => $channel, 'status' => 'queued', 'attempt_count' => 1, 'last_attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()])) {
                continue;
            }$locale = $user->preferred_language === 'ar' ? 'ar' : 'fr';
            $user->notify(new PortalNotification(['type' => $eventType, 'organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'language' => $locale, 'title' => __($copy['title'], [], $locale), 'message' => __($copy['message'], $copy['parameters'] ?? [], $locale), 'url' => $url], [$channel], $eventKey));
            $sent++;
        }

return $sent;
    }

    public function electorateEvent(AssemblyElectorate $electorate, string $eventType, string $eventKey, array $copy, string $url): int
    {
        $sent = 0;
        $assembly = $electorate->assembly;
        $users = User::query()->whereHas('contacts', fn ($q) => $q->where('contacts.id', $electorate->contact_id)->where('contact_user.organization_id', $electorate->organization_id)->whereNull('contact_user.revoked_at'))->whereHas('organizations', fn ($q) => $q->where('organizations.id', $electorate->organization_id))->get();
        foreach ($users as $user) {
            $stillOwner = DB::table('lot_ownerships')->join('lots', 'lots.id', '=', 'lot_ownerships.lot_id')->join('contact_user', 'contact_user.contact_id', '=', 'lot_ownerships.contact_id')->where('contact_user.user_id', $user->id)->whereNull('contact_user.revoked_at')->where('lots.residence_id', $electorate->residence_id)->whereDate('lot_ownerships.starts_on', '<=', today())->where(fn ($q) => $q->whereNull('lot_ownerships.ends_on')->orWhereDate('lot_ownerships.ends_on', '>=', today()))->exists();
            if (! $stillOwner) {
                continue;
            }$pref = NotificationPreference::where('user_id', $user->id)->where('organization_id', $electorate->organization_id)->first();
            if (in_array($eventType, $pref?->muted_events ?? [], true)) {
                continue;
            }
            foreach (array_keys(array_filter(['database' => $pref?->database_enabled ?? true, 'mail' => $pref?->email_enabled ?? true])) as $channel) {
                $inserted = DB::table('governance_notification_dispatches')->insertOrIgnore(['organization_id' => $electorate->organization_id, 'residence_id' => $electorate->residence_id, 'assembly_id' => $assembly->id, 'user_id' => $user->id, 'event_type' => $eventType, 'event_key' => $eventKey, 'channel' => $channel, 'status' => 'queued', 'attempt_count' => 1, 'last_attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                if (! $inserted) {
                    continue;
                }$locale = $user->preferred_language === 'ar' ? 'ar' : 'fr';
                $user->notify(new PortalNotification(['type' => $eventType, 'organization_id' => $electorate->organization_id, 'residence_id' => $electorate->residence_id, 'language' => $locale, 'title' => __($copy['title'], [], $locale), 'message' => __($copy['message'], $copy['parameters'] ?? [], $locale), 'url' => $url], [$channel], $eventKey));
                $sent++;
            }
        }

return $sent;
    }
}
