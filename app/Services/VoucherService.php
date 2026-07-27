<?php

namespace App\Services;

use App\Exceptions\FinancialDocumentIntegrityException;
use App\Models\DocumentGenerationAttempt;
use App\Models\FinancialDocument;
use App\Models\SupplierSettlement;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class VoucherService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FinancialDocumentRenderer $renderer,
        private FinancialDocumentChecksumService $checksums,
        private FinancialDocumentMutationGuard $mutationGuard,
        private ManagerNotificationService $notifications,
    ) {}

    public function generate(SupplierSettlement $settlement, User $actor): FinancialDocument
    {
        $result = DB::transaction(fn () => $this->generateLocked($settlement, $actor), 3);
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    private function generateLocked(SupplierSettlement $settlement, User $actor): FinancialDocument|Throwable
    {
        $settlement = SupplierSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
        $settlement->load(['residence.organization', 'supplier', 'account', 'allocations.invoice']);
        $document = FinancialDocument::query()->where('subject_type', SupplierSettlement::class)->where('subject_id', $settlement->id)
            ->where('type', 'supplier_voucher')->where('version', 1)->lockForUpdate()->first();
        if ($document && Storage::disk($document->disk)->exists($document->path)) {
            $bytes = Storage::disk($document->disk)->get($document->path);
            if ($this->checksums->matches($document->checksum, $bytes)) {
                return $document;
            }

            throw new FinancialDocumentIntegrityException('Voucher bytes do not match the finalized checksum. Use the controlled checksum recovery command.');
        }
        $attempt = DocumentGenerationAttempt::query()->firstOrCreate(
            ['document_type' => 'supplier_voucher', 'subject_type' => SupplierSettlement::class, 'subject_id' => $settlement->id, 'version' => 1],
            ['organization_id' => $settlement->organization_id, 'residence_id' => $settlement->residence_id, 'locale' => $settlement->supplier->preferred_language === 'ar' ? 'ar' : 'fr', 'status' => 'pending', 'requested_by' => $actor->id]
        );
        $number = $document?->number ?? $attempt->number;
        if (! $number) {
            $number = $this->numbers->next($settlement->residence, 'VCH', (int) $settlement->settlement_date->format('Y'));
            $attempt->update(['number' => $number]);
        }
        $token = $document ? Crypt::decryptString($document->verification_token_encrypted) : Str::random(64);
        $locale = $settlement->supplier->preferred_language === 'ar' ? 'ar' : 'fr';
        $attempt->increment('attempt_count');
        $attempt->update(['status' => 'pending', 'last_attempted_at' => now(), 'failed_at' => null, 'failure_code' => null, 'failure_summary' => null]);
        try {
            $bytes = $this->renderer->voucher($settlement, $number, $locale, $token);
            $storageKey = substr(hash('sha256', $token), 0, 16);
            $path = $document?->path ?? "finance/vouchers/{$settlement->residence_id}/{$number}-v1-{$locale}-{$storageKey}.pdf";
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new FinancialDocumentIntegrityException('Voucher storage failed.');
            }
        } catch (Throwable $exception) {
            report($exception);
            $attempt->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => 'renderer_or_storage_failed', 'failure_summary' => __('La génération du document a échoué. Réessayez après vérification du service PDF.')]);
            if ($attempt->attempt_count >= 3) {
                $this->notifications->dispatch($settlement->residence->organization, $settlement->residence, 'document_generation_failed', "document:supplier-voucher:{$settlement->id}:v1", ['title' => 'Échec de génération de document', 'message' => 'Le justificatif du règlement :number nécessite une nouvelle tentative.', 'parameters' => ['number' => $settlement->number], 'data' => ['settlement_id' => $settlement->id]], route('supplier-settlements.show', $settlement), true);
            }

            return $exception;
        }
        $attributes = ['organization_id' => $settlement->organization_id, 'residence_id' => $settlement->residence_id, 'type' => 'supplier_voucher', 'number' => $number,
            'subject_type' => SupplierSettlement::class, 'subject_id' => $settlement->id, 'locale' => $locale, 'version' => 1, 'disk' => 'local', 'path' => $path,
            'checksum' => $this->checksums->checksum($bytes), 'checksum_version' => FinancialDocumentChecksumService::VERSION,
            'verification_token_hash' => hash('sha256', $token), 'verification_token_encrypted' => Crypt::encryptString($token),
            'status' => 'valid', 'generated_at' => now(), 'generated_by' => $actor->id];
        if ($document) {
            $this->mutationGuard->authorized(fn () => $document->update($attributes));
        } else {
            $document = FinancialDocument::create($attributes);
        }
        $attempt->update(['status' => 'generated', 'resolved_at' => now(), 'failed_at' => null, 'failure_code' => null, 'failure_summary' => null]);
        activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $settlement->organization_id, 'residence_id' => $settlement->residence_id, 'number' => $number])->log('supplier_voucher.generated');

        return $document;
    }
}
