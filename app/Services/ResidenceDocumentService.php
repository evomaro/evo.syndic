<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\ResidenceDocument;
use App\Models\ResidenceDocumentVersion;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResidenceDocumentService
{
    public function __construct(private ManagerNotificationService $managerNotifications) {}

    public function storeVersion(ResidenceDocument $document, UploadedFile $file, User $actor): ResidenceDocumentVersion
    {
        return DB::transaction(function () use ($document, $file, $actor) {
            $document = ResidenceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status === 'archived') {
                throw ValidationException::withMessages(['file' => __('Un document archivé ne peut pas recevoir de version.')]);
            }
            $version = (int) $document->versions()->max('version') + 1;
            $path = $file->store("residence-documents/{$document->residence_id}/{$document->id}", 'local');
            $bytes = Storage::disk('local')->get($path);
            $model = $document->versions()->create(['version' => $version, 'name' => $file->getClientOriginalName(), 'disk' => 'local', 'path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $actor->id]);
            activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $document->organization_id, 'residence_id' => $document->residence_id, 'version' => $version])->log('residence_document.versioned');

            return $model;
        });
    }

    public function accessibleTo(ResidenceDocument $document, User $user, bool $staff): bool
    {
        if ($staff) {
            return true;
        }
        if ($document->status !== 'published' || ($document->expires_at && $document->expires_at->isPast()) || ! in_array($document->audience, ['all_residents', 'selected_buildings', 'selected_lots', 'selected_contacts'], true)) {
            return false;
        }
        $ownerships = DB::table('lot_ownerships')->join('lots', 'lots.id', '=', 'lot_ownerships.lot_id')->join('contact_user', 'contact_user.contact_id', '=', 'lot_ownerships.contact_id')
            ->where('contact_user.user_id', $user->id)->where('contact_user.organization_id', $document->organization_id)->whereNull('contact_user.revoked_at')
            ->where('lots.residence_id', $document->residence_id)->whereDate('lot_ownerships.starts_on', '<=', today())->where(fn ($q) => $q->whereNull('lot_ownerships.ends_on')->orWhereDate('lot_ownerships.ends_on', '>=', today()));
        if ($document->audience === 'all_residents') {
            return $ownerships->exists();
        }

        if ($document->audience === 'selected_contacts') {
            return DB::table('contact_user')->where('user_id', $user->id)->where('organization_id', $document->organization_id)
                ->whereNull('revoked_at')->whereIn('contact_id', $document->contacts()->pluck('contacts.id'))->exists();
        }

        if ($document->audience === 'selected_buildings') {
            return $ownerships->whereIn('lots.building_id', $document->buildings()->pluck('buildings.id'))->exists();
        }

        return $ownerships->whereIn('lots.id', $document->lots()->pluck('lots.id'))->exists();
    }

    public function attemptPublish(ResidenceDocument $document, User $actor): bool
    {
        $document->increment('publication_attempts');
        $document->update(['last_publication_attempt_at' => now()]);
        try {
            $this->publish($document, $actor);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $document->refresh()->update(['publication_failed_at' => now(), 'publication_failure_code' => 'scheduled_publication_failed', 'publication_failure_summary' => __('La publication du document a échoué. Vérifiez sa version et réessayez.')]);
            if ($document->publication_attempts >= 3) {
                $this->managerNotifications->dispatch($document->organization, $document->residence, 'scheduled_publication_failed', "residence-document:{$document->id}:publication", ['title' => 'Échec de publication planifiée', 'message' => 'Le document :title nécessite une intervention.', 'parameters' => ['title' => $document->title], 'data' => ['document_id' => $document->id]], route('documents.index'), true);
            }

            return false;
        }
    }

    public function publish(ResidenceDocument $document, User $actor): ResidenceDocument
    {
        return DB::transaction(function () use ($document, $actor) {
            $document = ResidenceDocument::query()->whereKey($document->id)->with(['versions', 'residence', 'organization'])->lockForUpdate()->firstOrFail();
            $publishedVersion = $document->versions->sortByDesc('version')->first();
            if (! $publishedVersion) {
                throw ValidationException::withMessages(['file' => __('Une version est requise avant publication.')]);
            }
            $document->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id, 'published_version_id' => $publishedVersion->id, 'publication_failure_resolved_at' => $document->publication_failed_at ? now() : $document->publication_failure_resolved_at, 'publication_failed_at' => null, 'publication_failure_code' => null, 'publication_failure_summary' => null]);
            User::query()->whereHas('contacts', fn ($query) => $query->where('contacts.organization_id', $document->organization_id))->each(function (User $user) use ($document) {
                if (! $this->accessibleTo($document, $user, false)) {
                    return;
                }
                $preference = NotificationPreference::firstOrCreate(['user_id' => $user->id, 'organization_id' => $document->organization_id]);
                if (in_array('document.published', $preference->muted_events ?? [], true)) {
                    return;
                }
                $channels = array_values(array_filter([$preference->database_enabled ? 'database' : null, $preference->email_enabled ? 'mail' : null]));
                $channels = collect($channels)->filter(fn (string $channel) => DB::table('notification_dispatches')->insertOrIgnore(['user_id' => $user->id, 'event_key' => "document.published:{$document->id}:{$document->published_version_id}", 'channel' => $channel, 'dispatched_at' => now(), 'created_at' => now(), 'updated_at' => now()]) === 1)->values()->all();
                if ($channels) {
                    $user->notify(new PortalNotification(['type' => 'document.published', 'organization_id' => $document->organization_id, 'residence_id' => $document->residence_id, 'title' => __('Nouveau document disponible'), 'message' => $document->title, 'url' => route('portal.index')], $channels));
                }
            });
            activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $document->organization_id, 'residence_id' => $document->residence_id, 'version' => $publishedVersion->version])->log('residence_document.published');

            return $document->fresh();
        });
    }

    public function publishDue(): array
    {
        $published = 0;
        $failed = 0;
        ResidenceDocument::query()->where('status', 'scheduled')->where('scheduled_for', '<=', now())->orderBy('id')->each(function ($document) use (&$published, &$failed) {
            $actor = User::query()->find($document->created_by);
            if ($actor && $this->attemptPublish($document, $actor)) {
                $published++;
            } else {
                $failed++;
            }
        });

        return compact('published', 'failed');
    }
}
