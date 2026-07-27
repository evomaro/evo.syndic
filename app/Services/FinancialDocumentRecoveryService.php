<?php

namespace App\Services;

use App\Exceptions\FinancialDocumentIntegrityException;
use App\Models\ChecksumRepairHistory;
use App\Models\FinancialDocument;
use App\Models\Payment;
use App\Models\SupplierSettlement;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinancialDocumentRecoveryService
{
    public const CLASSIFICATION = 'document_checksum_mismatch';

    public function __construct(
        private FinancialDocumentChecksumService $checksums,
        private FinancialDocumentRenderer $renderer,
        private FinancialDocumentMutationGuard $mutationGuard,
    ) {}

    public function inspect(FinancialDocument $document): array
    {
        $document->loadMissing('subject');
        $disk = Storage::disk($document->disk);
        $exists = $disk->exists($document->path);
        $bytes = $exists ? $disk->get($document->path) : null;
        $observedChecksum = $bytes === null ? null : $this->checksums->checksum($bytes);
        $evidence = $this->evidence($document, $observedChecksum, $bytes === null ? null : strlen($bytes));
        $reason = $this->refusalReason($document, $exists, $observedChecksum);

        return [
            'record_id' => $document->id,
            'organization_id' => $document->organization_id,
            'residence_id' => $document->residence_id,
            'type' => $document->type,
            'number' => $document->number,
            'stored_checksum' => $document->checksum,
            'observed_checksum' => $observedChecksum,
            'checksum_version' => $document->checksum_version ?: FinancialDocumentChecksumService::VERSION,
            'classification' => $reason === null ? self::CLASSIFICATION : ($observedChecksum === $document->checksum ? 'valid' : 'ambiguous'),
            'repairable' => $reason === null,
            'refusal_reason' => $reason,
            'evidence_fingerprint' => $this->checksums->evidenceFingerprint($evidence),
            'evidence_summary' => $this->safeEvidenceSummary($document, $exists, $observedChecksum),
        ];
    }

    public function repair(
        int $documentId,
        ?string $expectedEvidence = null,
        ?string $executionId = null,
        string $actorIdentity = 'system:checksum-repair-command',
        bool $strictEvidence = false,
    ): array {
        $fileState = null;

        try {
            return DB::transaction(function () use ($documentId, $expectedEvidence, $executionId, $actorIdentity, $strictEvidence, &$fileState) {
                $document = FinancialDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
                $this->lockAuthoritativeRelations($document);
                $inspection = $this->inspect($document);

                if ($inspection['classification'] === 'valid' && ! $strictEvidence) {
                    return $inspection + ['outcome' => 'already_valid', 'history_id' => null];
                }

                if ($expectedEvidence !== null && ! hash_equals($expectedEvidence, $inspection['evidence_fingerprint'])) {
                    throw ValidationException::withMessages(['evidence' => 'Checksum repair evidence changed after dry-run.']);
                }

                if ($inspection['classification'] === 'valid') {
                    return $inspection + ['outcome' => 'already_valid', 'history_id' => null];
                }

                if (! $inspection['repairable']) {
                    throw ValidationException::withMessages(['record' => $inspection['refusal_reason'] ?: 'Checksum repair refused.']);
                }

                $disk = Storage::disk($document->disk);
                $fileState = [
                    'disk' => $document->disk,
                    'path' => $document->path,
                    'bytes' => $disk->get($document->path),
                    'touched' => false,
                ];
                $newBytes = $this->renderAuthoritativeDocument($document);
                $newChecksum = $this->checksums->checksum($newBytes);
                $repairKey = hash('sha256', implode('|', [
                    FinancialDocument::class,
                    $document->id,
                    $document->checksum,
                    $inspection['observed_checksum'],
                    $newChecksum,
                    FinancialDocumentChecksumService::VERSION,
                ]));

                if (! $disk->put($document->path, $newBytes)) {
                    throw new FinancialDocumentIntegrityException('Recovered financial document could not be stored.');
                }
                $fileState['touched'] = true;

                if (! $disk->exists($document->path) || ! $this->checksums->matches($newChecksum, $disk->get($document->path))) {
                    throw new FinancialDocumentIntegrityException('Recovered financial document failed post-write validation.');
                }

                $oldChecksum = $document->checksum;
                $oldVersion = $document->checksum_version ?: FinancialDocumentChecksumService::VERSION;
                $this->mutationGuard->authorized(function () use ($document, $newChecksum): void {
                    $document->forceFill([
                        'checksum' => $newChecksum,
                        'checksum_version' => FinancialDocumentChecksumService::VERSION,
                    ])->save();
                });

                $history = ChecksumRepairHistory::query()->create([
                    'record_type' => FinancialDocument::class,
                    'record_id' => $document->id,
                    'organization_id' => $document->organization_id,
                    'residence_id' => $document->residence_id,
                    'old_checksum' => $oldChecksum,
                    'observed_checksum' => $inspection['observed_checksum'],
                    'new_checksum' => $newChecksum,
                    'old_checksum_version' => $oldVersion,
                    'new_checksum_version' => FinancialDocumentChecksumService::VERSION,
                    'classification' => self::CLASSIFICATION,
                    'canonical_payload_fingerprint' => $inspection['evidence_fingerprint'],
                    'evidence_summary' => $inspection['evidence_summary'],
                    'repair_key' => $repairKey,
                    'command_execution_id' => $executionId ?: (string) Str::uuid(),
                    'actor_identity' => $actorIdentity,
                    'created_at' => now('UTC'),
                ]);

                $storedBytes = $disk->get($document->path);
                if (! $this->checksums->matches($document->fresh()->checksum, $storedBytes)) {
                    throw new FinancialDocumentIntegrityException('Recovered checksum did not pass final validation.');
                }

                return $this->inspect($document->fresh()) + [
                    'outcome' => 'repaired',
                    'history_id' => $history->id,
                    'old_checksum' => $oldChecksum,
                    'new_checksum' => $newChecksum,
                ];
            }, 3);
        } catch (Throwable $exception) {
            if (($fileState['touched'] ?? false) === true) {
                Storage::disk($fileState['disk'])->put($fileState['path'], $fileState['bytes']);
            }

            throw $exception;
        }
    }

    private function refusalReason(FinancialDocument $document, bool $exists, ?string $observedChecksum): ?string
    {
        if (! in_array($document->type, ['receipt', 'supplier_voucher'], true)) {
            return 'Unsupported financial document type.';
        }
        if (! $exists || $observedChecksum === null) {
            return 'Original document bytes are missing; use the explicit missing-document recovery workflow.';
        }
        if ($observedChecksum === $document->checksum) {
            return 'Document checksum is already valid.';
        }
        if (! $document->subject) {
            return 'Authoritative financial subject is missing.';
        }
        if ($document->subject->organization_id !== $document->organization_id || $document->subject->residence_id !== $document->residence_id) {
            return 'Document and financial subject scope do not match.';
        }
        if (! in_array($document->subject->status, ['validated', 'reversed'], true)) {
            return 'Financial subject is not finalized.';
        }
        if (
            ($document->type === 'receipt' && ! $document->subject instanceof Payment)
            || ($document->type === 'supplier_voucher' && ! $document->subject instanceof SupplierSettlement)
        ) {
            return 'Document type and financial subject type do not match.';
        }

        try {
            $token = Crypt::decryptString($document->verification_token_encrypted);
        } catch (Throwable) {
            return 'Verification token cannot be authenticated.';
        }

        if (! hash_equals($document->verification_token_hash, hash('sha256', $token))) {
            return 'Verification token evidence is inconsistent.';
        }

        return null;
    }

    private function renderAuthoritativeDocument(FinancialDocument $document): string
    {
        $token = Crypt::decryptString($document->verification_token_encrypted);

        if ($document->type === 'receipt') {
            /** @var Payment $payment */
            $payment = $document->subject;

            return $this->renderer->receipt($payment, $document->number, $document->locale, $token);
        }

        /** @var SupplierSettlement $settlement */
        $settlement = $document->subject;

        return $this->renderer->voucher($settlement, $document->number, $document->locale, $token);
    }

    private function lockAuthoritativeRelations(FinancialDocument $document): void
    {
        $subject = $document->subject()->lockForUpdate()->first();
        $document->setRelation('subject', $subject);

        if ($subject instanceof Payment) {
            $subject->allocations()->orderBy('allocation_order')->orderBy('id')->lockForUpdate()->get();
            $subject->load(['residence.organization', 'residence.media', 'payer', 'account', 'allocations.charge.fundCall', 'allocations.lot']);
        }

        if ($subject instanceof SupplierSettlement) {
            $subject->allocations()->orderBy('allocation_order')->orderBy('id')->lockForUpdate()->get();
            $subject->load(['residence.organization', 'supplier', 'account', 'allocations.invoice']);
        }
    }

    private function evidence(FinancialDocument $document, ?string $observedChecksum, ?int $observedSize): array
    {
        $subject = $document->subject;
        $base = [
            'contract' => FinancialDocumentChecksumService::VERSION,
            'document' => [
                'id' => (int) $document->id,
                'organization_id' => (int) $document->organization_id,
                'residence_id' => (int) $document->residence_id,
                'type' => $document->type,
                'number' => $document->number,
                'locale' => $document->locale,
                'status' => $document->status,
                'version' => (int) $document->version,
                'disk' => $document->disk,
                'path' => $document->path,
                'stored_checksum' => $document->checksum,
                'observed_checksum' => $observedChecksum,
                'observed_size' => $observedSize,
                'verification_token_hash' => $document->verification_token_hash,
                'created_at' => $document->created_at,
                'updated_at' => $document->updated_at,
            ],
            'subject' => [
                'type' => $document->subject_type,
                'id' => (int) $document->subject_id,
                'organization_id' => (int) ($subject?->organization_id ?? 0),
                'residence_id' => (int) ($subject?->residence_id ?? 0),
                'number' => $subject?->number,
                'status' => $subject?->status,
                'amount_cents' => $subject ? (int) $subject->amount_cents : null,
            ],
        ];

        if ($subject instanceof Payment) {
            $base['subject'] += [
                'payment_date' => $subject->payment_date,
                'payer_contact_id' => $subject->payer_contact_id ? (int) $subject->payer_contact_id : null,
                'received_from' => $subject->received_from,
                'method' => $subject->method,
                'financial_account_id' => (int) $subject->financial_account_id,
                'allocations' => $subject->allocations->sortBy([['allocation_order', 'asc'], ['id', 'asc']])->map(fn ($allocation) => [
                    'id' => (int) $allocation->id,
                    'lot_charge_id' => (int) $allocation->lot_charge_id,
                    'lot_id' => (int) $allocation->lot_id,
                    'amount_cents' => (int) $allocation->amount_cents,
                    'allocation_order' => (int) $allocation->allocation_order,
                    'reversed_at' => $allocation->reversed_at,
                ])->values()->all(),
            ];
        }

        if ($subject instanceof SupplierSettlement) {
            $base['subject'] += [
                'settlement_date' => $subject->settlement_date,
                'supplier_id' => (int) $subject->supplier_id,
                'financial_account_id' => (int) $subject->financial_account_id,
                'method' => $subject->method,
                'bank_reference' => $subject->bank_reference,
                'cheque_number' => $subject->cheque_number,
                'allocations' => $subject->allocations->sortBy([['allocation_order', 'asc'], ['id', 'asc']])->map(fn ($allocation) => [
                    'id' => (int) $allocation->id,
                    'supplier_invoice_id' => (int) $allocation->supplier_invoice_id,
                    'supplier_invoice_line_id' => (int) $allocation->supplier_invoice_line_id,
                    'amount_cents' => (int) $allocation->amount_cents,
                    'allocation_order' => (int) $allocation->allocation_order,
                    'reversed_at' => $allocation->reversed_at,
                ])->values()->all(),
            ];
        }

        return $base;
    }

    private function safeEvidenceSummary(FinancialDocument $document, bool $exists, ?string $observedChecksum): array
    {
        return [
            'document_number' => $document->number,
            'document_type' => $document->type,
            'subject_type' => class_basename($document->subject_type),
            'subject_id' => (int) $document->subject_id,
            'subject_number' => $document->subject?->number,
            'subject_status' => $document->subject?->status,
            'subject_amount_cents' => $document->subject ? (int) $document->subject->amount_cents : null,
            'file_exists' => $exists,
            'observed_checksum' => $observedChecksum,
            'stored_checksum' => $document->checksum,
            'verification_token_hash_matches_ciphertext' => $this->tokenEvidenceMatches($document),
        ];
    }

    private function tokenEvidenceMatches(FinancialDocument $document): bool
    {
        try {
            return hash_equals($document->verification_token_hash, hash('sha256', Crypt::decryptString($document->verification_token_encrypted)));
        } catch (Throwable) {
            return false;
        }
    }
}
