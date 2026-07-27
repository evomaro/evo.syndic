<?php

namespace App\Services;

use App\Exceptions\FinancialDocumentIntegrityException;
use App\Models\FinancialDocument;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReceiptService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FinancialDocumentRenderer $renderer,
        private FinancialDocumentChecksumService $checksums,
        private FinancialDocumentMutationGuard $mutationGuard,
    ) {}

    public function generate(Payment $payment, User $actor): FinancialDocument
    {
        return DB::transaction(fn () => $this->generateLocked($payment, $actor));
    }

    private function generateLocked(Payment $payment, User $actor): FinancialDocument
    {
        $payment->load(['residence.organization', 'residence.media', 'payer', 'account', 'allocations.charge.fundCall', 'allocations.lot']);
        $locale = $payment->payer?->preferred_language === 'ar' ? 'ar' : 'fr';
        $document = FinancialDocument::query()->where('subject_type', Payment::class)->where('subject_id', $payment->id)
            ->where('type', 'receipt')->where('version', 1)->lockForUpdate()->first();
        if ($document && Storage::disk($document->disk)->exists($document->path)) {
            $bytes = Storage::disk($document->disk)->get($document->path);
            if ($this->checksums->matches($document->checksum, $bytes)) {
                return $document;
            }

            throw new FinancialDocumentIntegrityException('Receipt bytes do not match the finalized checksum. Use the controlled checksum recovery command.');
        }

        $token = $document ? Crypt::decryptString($document->verification_token_encrypted) : Str::random(64);
        $number = $document?->number ?? $this->numbers->next($payment->residence, 'REC', (int) $payment->payment_date->format('Y'));
        $bytes = $this->renderer->receipt($payment, $number, $locale, $token);
        $storageKey = substr(hash('sha256', $token), 0, 16);
        $path = $document?->path ?? "finance/receipts/{$payment->residence_id}/{$number}-v1-{$locale}-{$storageKey}.pdf";
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new FinancialDocumentIntegrityException('Receipt storage failed.');
        }
        $attributes = [
            'organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id,
            'type' => 'receipt', 'number' => $number, 'subject_type' => Payment::class, 'subject_id' => $payment->id,
            'locale' => $locale, 'path' => $path, 'checksum' => $this->checksums->checksum($bytes),
            'checksum_version' => FinancialDocumentChecksumService::VERSION,
            'verification_token_hash' => hash('sha256', $token), 'generated_at' => now(), 'generated_by' => $actor->id,
            'verification_token_encrypted' => Crypt::encryptString($token),
        ];
        if ($document) {
            $this->mutationGuard->authorized(fn () => $document->update($attributes));
        } else {
            $document = FinancialDocument::create($attributes);
        }
        activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id, 'number' => $number])->log('receipt.generated');

        return $document;
    }
}
