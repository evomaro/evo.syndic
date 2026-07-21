<?php

namespace App\Services;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\FinancialDocument;
use App\Models\Payment;
use App\Models\User;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ReceiptService
{
    public function __construct(private DocumentNumberService $numbers, private ReceiptPdfRenderer $renderer) {}

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
            if (hash_equals($document->checksum, hash('sha256', $bytes))) {
                return $document;
            }
        }

        $token = $document ? Crypt::decryptString($document->verification_token_encrypted) : Str::random(64);
        $number = $document?->number ?? $this->numbers->next($payment->residence, 'REC', (int) $payment->payment_date->format('Y'));
        $verificationUrl = route('receipts.verify', ['token' => $token]);
        if (app()->environment('production') && ! str_starts_with($verificationUrl, 'https://')) {
            throw new RuntimeException('Receipt verification URL must use HTTPS in production.');
        }
        $qr = (new PngWriter)->write(new QrCode(data: $verificationUrl, size: 180, margin: 4))->getDataUri();
        $html = view('pdf.receipt', compact('payment', 'number', 'locale', 'qr'))->render();
        $bytes = $this->renderer->render($html, $locale);
        $path = $document?->path ?? "finance/receipts/{$payment->residence_id}/{$number}-v1-{$locale}.pdf";
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('Receipt storage failed.');
        }
        $attributes = [
            'organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id,
            'type' => 'receipt', 'number' => $number, 'subject_type' => Payment::class, 'subject_id' => $payment->id,
            'locale' => $locale, 'path' => $path, 'checksum' => hash('sha256', $bytes),
            'verification_token_hash' => hash('sha256', $token), 'generated_at' => now(), 'generated_by' => $actor->id,
            'verification_token_encrypted' => Crypt::encryptString($token),
        ];
        if ($document) {
            $document->update($attributes);
        } else {
            $document = FinancialDocument::create($attributes);
        }
        activity()->performedOn($document)->causedBy($actor)->withProperties(['organization_id' => $payment->organization_id, 'residence_id' => $payment->residence_id, 'number' => $number])->log('receipt.generated');

        return $document;
    }
}
