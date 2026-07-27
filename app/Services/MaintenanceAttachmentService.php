<?php

namespace App\Services;

use App\Models\MaintenanceAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceAttachmentService
{
    public function upload(Model $entity, UploadedFile $file, string $kind, string $visibility, User $actor, ?MaintenanceAttachment $replaces = null): MaintenanceAttachment
    {
        return DB::transaction(function () use ($entity, $file, $kind, $visibility, $actor, $replaces) {
            if ($replaces) {
                abort_unless($replaces->attachable_type === $entity->getMorphClass() && $replaces->attachable_id === $entity->id && ! $replaces->archived_at, 404);
                $replaces->update(['archived_at' => now('UTC'), 'archived_by' => $actor->id]);
            }
            $extension = strtolower($file->getClientOriginalExtension());
            $safe = mb_substr(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'document', 0, 120).'.'.$extension;
            $path = $file->storeAs('maintenance/'.$entity->organization_id.'/'.$entity->getTable().'/'.$entity->id, Str::uuid().'.'.$extension, 'local');
            $bytes = Storage::disk('local')->get($path);
            $attachment = $entity->attachments()->create([
                'organization_id' => $entity->organization_id, 'residence_id' => $entity->residence_id, 'kind' => $kind, 'visibility' => $visibility,
                'version' => (int) $entity->attachments()->max('version') + 1, 'name' => $safe, 'disk' => 'local', 'path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $actor->id,
            ]);
            activity()->performedOn($attachment)->causedBy($actor)->withProperties(['organization_id' => $attachment->organization_id, 'residence_id' => $attachment->residence_id, 'kind' => $kind, 'visibility' => $visibility])->log($replaces ? 'maintenance_attachment.replaced' : 'maintenance_attachment.uploaded');

            return $attachment;
        });
    }

    public function download(MaintenanceAttachment $attachment, User $actor): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless(! $attachment->archived_at && $disk->exists($attachment->path), 404);
        abort_unless(hash_equals($attachment->checksum, hash('sha256', $disk->get($attachment->path))), 409, __('Le fichier ne correspond plus à son empreinte de sécurité.'));

        return $disk->download($attachment->path, $attachment->name, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
