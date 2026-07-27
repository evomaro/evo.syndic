<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\ResidenceAnnouncement;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnnouncementService
{
    public function __construct(private ManagerNotificationService $managerNotifications) {}

    public function publish(ResidenceAnnouncement $announcement, User $actor): ResidenceAnnouncement
    {
        return DB::transaction(function () use ($announcement, $actor) {
            $announcement = ResidenceAnnouncement::query()->whereKey($announcement->id)->with(['lots', 'buildings', 'contacts'])->lockForUpdate()->firstOrFail();
            if (! in_array($announcement->status, ['draft', 'scheduled'], true)) {
                return $announcement;
            }
            $users = $this->recipients($announcement);
            $snapshot = ['user_ids' => $users->pluck('id')->all(), 'lot_ids' => $announcement->lots->pluck('id')->all(), 'building_ids' => $announcement->buildings->pluck('id')->all(), 'contact_ids' => $announcement->contacts->pluck('id')->all(), 'resolved_at' => now()->toIso8601String()];
            $announcement->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id, 'audience_snapshot' => $snapshot, 'publication_failure_resolved_at' => $announcement->publication_failed_at ? now() : $announcement->publication_failure_resolved_at, 'publication_failed_at' => null, 'publication_failure_code' => null, 'publication_failure_summary' => null]);
            foreach ($users as $user) {
                $preference = NotificationPreference::firstOrCreate(['user_id' => $user->id, 'organization_id' => $announcement->organization_id]);
                if (in_array('announcement.published', $preference->muted_events ?? [], true)) {
                    continue;
                }
                $requestedChannels = array_values(array_filter([$preference->database_enabled ? 'database' : null, $preference->email_enabled ? 'mail' : null]));
                $channels = collect($requestedChannels)->filter(function (string $channel) use ($user, $announcement) {
                    return DB::table('notification_dispatches')->insertOrIgnore(['user_id' => $user->id, 'event_key' => "announcement.published:{$announcement->id}", 'channel' => $channel, 'dispatched_at' => now(), 'created_at' => now(), 'updated_at' => now()]) === 1;
                })->values()->all();
                if ($channels) {
                    $user->notify(new PortalNotification(['type' => 'announcement.published', 'organization_id' => $announcement->organization_id, 'residence_id' => $announcement->residence_id, 'title' => $announcement->title, 'message' => str($announcement->body)->limit(180)->toString(), 'url' => route('portal.index')], $channels));
                }
            }
            activity()->performedOn($announcement)->causedBy($actor)->withProperties(['organization_id' => $announcement->organization_id, 'residence_id' => $announcement->residence_id, 'recipients' => $users->count()])->log('announcement.published');

            return $announcement->fresh();
        });
    }

    public function publishDue(?User $actor = null): int
    {
        $due = ResidenceAnnouncement::query()->where('status', 'scheduled')->where('scheduled_for', '<=', now())->orderBy('id')->get();
        if ($due->isEmpty()) {
            return 0;
        }
        $count = 0;
        $due->each(function ($item) use ($actor, &$count) {
            if ($this->attempt($item, $actor ?? User::query()->findOrFail($item->created_by))) {
                $count++;
            }
        });

        return $count;
    }

    public function attempt(ResidenceAnnouncement $announcement, User $actor): bool
    {
        $announcement->increment('publication_attempts');
        $announcement->update(['last_publication_attempt_at' => now()]);
        try {
            $this->publish($announcement, $actor);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $announcement->refresh()->update(['publication_failed_at' => now(), 'publication_failure_code' => 'scheduled_publication_failed', 'publication_failure_summary' => __('La publication a échoué. Vérifiez le contenu et réessayez.')]);
            if ($announcement->publication_attempts >= 3) {
                $this->managerNotifications->dispatch($announcement->organization, $announcement->residence, 'scheduled_publication_failed', "announcement:{$announcement->id}:publication", ['title' => 'Échec de publication planifiée', 'message' => 'L’annonce :title nécessite une intervention.', 'parameters' => ['title' => $announcement->title], 'data' => ['announcement_id' => $announcement->id]], route('announcements.index'), true);
            }

            return false;
        }
    }

    private function recipients(ResidenceAnnouncement $announcement)
    {
        $ids = DB::table('contact_user')->join('lot_ownerships', 'lot_ownerships.contact_id', '=', 'contact_user.contact_id')->join('lots', 'lots.id', '=', 'lot_ownerships.lot_id')
            ->where('contact_user.organization_id', $announcement->organization_id)->whereNull('contact_user.revoked_at')->where('lots.residence_id', $announcement->residence_id)
            ->whereDate('lot_ownerships.starts_on', '<=', today())->where(fn ($q) => $q->whereNull('lot_ownerships.ends_on')->orWhereDate('lot_ownerships.ends_on', '>=', today()));
        if ($announcement->audience === 'selected_lots') {
            $ids->whereIn('lots.id', $announcement->lots->pluck('id'));
        } elseif ($announcement->audience === 'selected_buildings') {
            $ids->whereIn('lots.building_id', $announcement->buildings->pluck('id'));
        } elseif ($announcement->audience === 'selected_contacts') {
            $ids->whereIn('contact_user.contact_id', $announcement->contacts->pluck('id'));
        }

        return User::query()->whereIn('id', $ids->distinct()->pluck('contact_user.user_id'))->get();
    }
}
