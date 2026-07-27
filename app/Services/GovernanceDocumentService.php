<?php

namespace App\Services;

use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GovernanceDocumentService
{
    public function storeVersion(GovernanceDocument $document, UploadedFile $file, User $actor): GovernanceDocumentVersion
    {
        return DB::transaction(function () use ($document, $file, $actor) {
            $document = GovernanceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status === 'archived' || $document->assembly->status === 'closed') {
                throw ValidationException::withMessages(['file' => __('Ce document historique ne peut plus être modifié.')]);
            }
            $version = (int) $document->versions()->max('version') + 1;
            $safe = bin2hex(random_bytes(16)).'.'.strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs("governance/{$document->residence_id}/{$document->assembly_id}/documents/{$document->id}", $safe, 'local');
            $bytes = Storage::disk('local')->get($path);
            $model = $document->versions()->create(['version' => $version, 'name' => basename($file->getClientOriginalName()), 'path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $actor->id, 'replaces_version_id' => $document->published_version_id]);
            activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $document->organization_id, 'residence_id' => $document->residence_id, 'version' => $version, 'checksum' => $model->checksum])->log('governance.document_versioned');

            return $model;
        });
    }

    public function publish(GovernanceDocument $document, GovernanceDocumentVersion $version, User $actor): GovernanceDocument
    {
        return DB::transaction(function () use ($document, $version, $actor) {
            $document = GovernanceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_unless($version->governance_document_id === $document->id, 404);
            if (! in_array($document->assembly->status, ['draft', 'preparing'], true)) {
                throw ValidationException::withMessages(['document' => __('La version documentaire de l’assemblée est figée.')]);
            }$document->update(['status' => 'published', 'published_version_id' => $version->id, 'published_at' => now('UTC'), 'published_by' => $actor->id]);
            activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $document->organization_id, 'residence_id' => $document->residence_id, 'version' => $version->version])->log('governance.document_published');
            foreach ($document->assembly->electorate as $e) {
                app(GovernanceNotificationService::class)->electorateEvent($e, 'supporting_document_available', "governance-document:{$document->id}:version:{$version->id}:electorate:{$e->id}", ['title' => 'Document d’assemblée disponible', 'message' => 'Un document protégé est disponible dans votre espace copropriétaire.'], route('owner-governance.show', $document->assembly));
            }

return $document->fresh();
        });
    }

    public function authorizeOwner(GovernanceDocumentVersion $version, User $user): bool
    {
        $document = $version->document()->with('assembly')->first();
        if (! $document || $document->published_version_id !== $version->id || $document->status !== 'published' || $document->audience !== 'owners') {
            return false;
        }

        return (bool) app(GovernancePortalAccessService::class)->electorate($document->assembly, $user);
    }

    public function archive(GovernanceDocument $document, User $actor, string $reason): GovernanceDocument
    {
        return DB::transaction(function () use ($document, $actor, $reason) {
            $document = GovernanceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status === 'archived') {
                return $document;
            }
            if (mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['reason' => __('Un motif détaillé est requis pour archiver ce document.')]);
            }
            $document->update(['status' => 'archived', 'archived_at' => now('UTC'), 'archived_by' => $actor->id, 'archive_reason' => trim($reason)]);
            activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $document->organization_id, 'residence_id' => $document->residence_id, 'reason' => trim($reason)])->log('governance.document_archived');

            return $document->fresh();
        });
    }

    public function download(GovernanceDocumentVersion $version, User $user, bool $staff = false)
    {
        if (! $staff && ! $this->authorizeOwner($version, $user)) {
            abort(404);
        }$disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path) && hash_equals($version->checksum, hash('sha256', $disk->get($version->path))), 409);
        DB::table('governance_document_accesses')->insert(['governance_document_version_id' => $version->id, 'user_id' => $user->id, 'action' => 'downloaded', 'ip_hash' => request()->ip() ? hash('sha256', request()->ip()) : null, 'accessed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $disk->download($version->path, $version->name, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
