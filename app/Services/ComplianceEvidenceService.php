<?php

namespace App\Services;

use App\Models\ComplianceEvidence;
use App\Models\ComplianceEvidenceVersion;
use App\Models\ComplianceObligation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ComplianceEvidenceService
{
    private const ALLOWED = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function store(ComplianceObligation $obligation, string $type, string $title, UploadedFile $file, User $actor, ?int $submissionId = null, ?ComplianceEvidence $evidence = null): ComplianceEvidenceVersion
    {
        $mime = $file->getMimeType() ?: '';
        $extension = strtolower($file->getClientOriginalExtension());
        if (! isset(self::ALLOWED[$mime]) || self::ALLOWED[$mime] !== $extension || $file->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => __('Type, extension ou taille de fichier non autorisé.')]);
        }

        return DB::transaction(function () use ($obligation, $type, $title, $file, $actor, $submissionId, $evidence, $mime, $extension) {
            $obligation = ComplianceObligation::query()->lockForUpdate()->findOrFail($obligation->id);
            if ($submissionId && ! $obligation->submissions()->whereKey($submissionId)->exists()) {
                throw ValidationException::withMessages(['submission_id' => __('La soumission ne correspond pas à cette obligation.')]);
            }
            $evidence ??= ComplianceEvidence::create([
                'organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id,
                'obligation_id' => $obligation->id, 'submission_id' => $submissionId,
                'type' => $type, 'title' => $title, 'created_by' => $actor->id,
            ]);
            if ($evidence->obligation_id !== $obligation->id || $evidence->organization_id !== $obligation->organization_id || $evidence->residence_id !== $obligation->residence_id) {
                throw ValidationException::withMessages(['evidence' => __('La preuve ne correspond pas au périmètre de l’obligation.')]);
            }
            $evidence = ComplianceEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
            $version = (int) $evidence->versions()->max('version') + 1;
            $safe = sprintf('evidence-%d-v%d-%s.%s', $evidence->id, $version, bin2hex(random_bytes(8)), $extension);
            $path = $file->storeAs("compliance/{$obligation->organization_id}/".($obligation->residence_id ?? 'organization')."/{$obligation->id}/{$evidence->id}", $safe, 'local');
            $bytes = Storage::disk('local')->get($path);
            $model = ComplianceEvidenceVersion::create([
                'evidence_id' => $evidence->id, 'version' => $version, 'name' => $file->getClientOriginalName(),
                'disk' => 'local', 'path' => $path, 'mime_type' => $mime, 'size' => strlen($bytes),
                'checksum' => hash('sha256', $bytes), 'uploaded_by' => $actor->id,
            ]);
            activity()->performedOn($obligation)->causedBy($actor)->withProperties(['organization_id' => $obligation->organization_id, 'residence_id' => $obligation->residence_id, 'evidence_id' => $evidence->id, 'version' => $version, 'checksum' => $model->checksum])->log('compliance.evidence_versioned');

            return $model;
        }, 3);
    }

    public function assertIntegrity(ComplianceEvidenceVersion $version): void
    {
        if (! Storage::disk($version->disk)->exists($version->path) || ! hash_equals($version->checksum, hash('sha256', Storage::disk($version->disk)->get($version->path)))) {
            abort(409, __('Le fichier ne passe pas le contrôle d’intégrité.'));
        }
    }
}
