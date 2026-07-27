<?php

namespace App\Services;

use App\Models\SupplierContract;
use App\Models\SupplierContractAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierContractAttachmentService
{
    public function upload(SupplierContract $contract, UploadedFile $file, User $actor, bool $reusable, ?SupplierContractAttachment $replaces = null): SupplierContractAttachment
    {
        return DB::transaction(function () use ($contract, $file, $actor, $reusable, $replaces) {
            if ($replaces) {
                abort_unless($replaces->supplier_contract_id === $contract->id && $replaces->status === 'active', 404);
                $replaces->update(['status' => 'archived', 'archived_at' => now(), 'archived_by' => $actor->id]);
            }
            $version = (int) $contract->attachments()->lockForUpdate()->max('version') + 1;
            $extension = strtolower($file->getClientOriginalExtension());
            $stem = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'document';
            $name = mb_substr($stem, 0, 120).'.'.$extension;
            $path = $file->storeAs("supplier-contracts/{$contract->organization_id}/{$contract->id}", Str::uuid().'.'.$extension, 'local');
            $bytes = Storage::disk('local')->get($path);
            $attachment = $contract->attachments()->create(['version' => $version, 'name' => $name, 'disk' => 'local', 'path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $actor->id, 'reusable_on_renewal' => $reusable, 'status' => 'active', 'replaces_id' => $replaces?->id]);
            activity()->performedOn($attachment)->causedBy($actor)->withProperties(['organization_id' => $contract->organization_id, 'residence_id' => $contract->residence_id, 'contract_id' => $contract->id, 'version' => $version])->log($replaces ? 'supplier_contract_attachment.replaced' : 'supplier_contract_attachment.uploaded');

            return $attachment;
        });
    }

    public function download(SupplierContractAttachment $attachment, User $actor): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);
        // Archiving removes an attachment from the active contract view, but it
        // must not destroy access to the immutable historical version.
        abort_unless(in_array($attachment->status, ['active', 'archived'], true) && $disk->exists($attachment->path), 404);
        abort_unless(hash_equals($attachment->checksum, hash('sha256', $disk->get($attachment->path))), 409, __('Le fichier ne correspond plus à son empreinte de sécurité.'));
        activity()->performedOn($attachment)->causedBy($actor)->withProperties(['organization_id' => $attachment->contract->organization_id, 'residence_id' => $attachment->contract->residence_id, 'contract_id' => $attachment->contract->id])->log('supplier_contract_attachment.downloaded');

        return $disk->download($attachment->path, $attachment->name, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function archive(SupplierContractAttachment $attachment, User $actor): void
    {
        abort_unless($attachment->contract->status === 'active' && $attachment->status === 'active', 422);
        $attachment->update(['status' => 'archived', 'archived_at' => now(), 'archived_by' => $actor->id]);
        activity()->performedOn($attachment)->causedBy($actor)->withProperties(['organization_id' => $attachment->contract->organization_id, 'residence_id' => $attachment->contract->residence_id, 'contract_id' => $attachment->contract->id])->log('supplier_contract_attachment.archived');
    }
}
