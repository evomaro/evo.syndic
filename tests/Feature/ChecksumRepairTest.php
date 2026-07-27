<?php

namespace Tests\Feature;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\ChecksumRepairHistory;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialDocument;
use App\Models\Payment;
use App\Models\SupplierSettlement;
use App\Services\CollectionAuditService;
use App\Services\ExpenseAuditService;
use App\Services\FinancialDocumentChecksumService;
use App\Services\FinancialDocumentMutationGuard;
use App\Services\FinancialDocumentRecoveryService;
use App\Services\FinancialDocumentRenderer;
use App\Services\SupplierSettlementWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class ChecksumRepairTest extends TestCase
{
    use CreatesPhaseThreeContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                return '%PDF-1.4 deterministic-'.$locale.'-'.hash('sha256', $html);
            }
        });
    }

    public function test_checksum_contract_is_deterministic_locale_independent_and_canonical(): void
    {
        $service = app(FinancialDocumentChecksumService::class);
        $date = CarbonImmutable::parse('2026-07-24 12:30:45.123456', 'Africa/Casablanca');
        $first = [
            'null' => null,
            'empty' => '',
            'amount_cents' => 12345,
            'date' => $date,
            'relationships' => [['id' => 1, 'amount_cents' => 100], ['id' => 2, 'amount_cents' => 200]],
            'nested' => ['z' => 2, 'a' => 1],
        ];
        $second = [
            'nested' => ['a' => 1, 'z' => 2],
            'relationships' => [['amount_cents' => 100, 'id' => 1], ['amount_cents' => 200, 'id' => 2]],
            'date' => $date->utc(),
            'amount_cents' => 12345,
            'empty' => '',
            'null' => null,
        ];

        $this->assertSame(hash('sha256', 'document bytes'), $service->checksum('document bytes'));
        $this->assertSame($service->canonicalJson($first), $service->canonicalJson($second));
        $this->assertSame($service->evidenceFingerprint($first), $service->evidenceFingerprint($second));
        $this->assertNotSame($service->evidenceFingerprint(['value' => null]), $service->evidenceFingerprint(['value' => '']));
        $this->assertNotSame(
            $service->evidenceFingerprint(['relationships' => [['id' => 1], ['id' => 2]]]),
            $service->evidenceFingerprint(['relationships' => [['id' => 2], ['id' => 1]]]),
        );
        $this->expectException(InvalidArgumentException::class);
        $service->canonicalJson(['amount' => 123.45]);
    }

    public function test_dry_run_is_read_only_apply_is_explicit_targeted_and_idempotent(): void
    {
        [$document, $payment] = $this->corruptReceipt();
        $businessKeys = ['amount_cents', 'payment_date', 'payer_contact_id', 'financial_account_id', 'status'];
        $businessBefore = collect($businessKeys)->mapWithKeys(fn (string $key) => [$key => $payment->getRawOriginal($key)])->all();
        $foreign = $this->validReceipt($this->phaseThreeContext());
        $foreignBytes = Storage::disk('local')->get($foreign->path);

        $this->assertSame(0, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--record' => $document->id,
            '--json' => true,
        ]));
        $dryRun = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('dry-run', $dryRun['mode']);
        $this->assertSame(1, $dryRun['repairable']);
        $this->assertSame('document_checksum_mismatch', $dryRun['records'][0]['classification']);
        $this->assertSame(0, ChecksumRepairHistory::count());
        $this->assertNotSame($document->checksum, $this->fileChecksum($document));

        $this->assertSame(0, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--record' => $document->id,
            '--expected-evidence' => $dryRun['records'][0]['evidence_fingerprint'],
            '--apply' => true,
            '--json' => true,
        ]));
        $applied = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $applied['repaired']);
        $this->assertSame($document->fresh()->checksum, $this->fileChecksum($document));
        $this->assertSame(FinancialDocumentChecksumService::VERSION, $document->fresh()->checksum_version);
        $this->assertSame(1, ChecksumRepairHistory::count());
        $freshPayment = $payment->fresh();
        $this->assertSame($businessBefore, collect($businessKeys)->mapWithKeys(fn (string $key) => [$key => $freshPayment->getRawOriginal($key)])->all());
        $this->assertSame($foreignBytes, Storage::disk('local')->get($foreign->path));

        $this->assertSame(0, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--record' => $document->id,
            '--apply' => true,
            '--json' => true,
        ]));
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $second['repaired']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, ChecksumRepairHistory::count());
        $this->assertTrue(app(CollectionAuditService::class)->audit(['payment' => $payment->id])['ok']);
    }

    public function test_voucher_repair_preserves_business_allocations_and_audit_consistency(): void
    {
        [$document, $settlement] = $this->corruptVoucher();
        $allocations = $settlement->allocations()->orderBy('id')->get()->map->only([
            'supplier_invoice_id',
            'supplier_invoice_line_id',
            'amount_cents',
            'allocation_order',
            'reversed_at',
        ])->all();
        $businessKeys = ['amount_cents', 'settlement_date', 'supplier_id', 'financial_account_id', 'status'];
        $business = collect($businessKeys)->mapWithKeys(fn (string $key) => [$key => $settlement->getRawOriginal($key)])->all();

        $this->assertSame(0, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--record' => $document->id,
            '--voucher-only' => true,
            '--apply' => true,
            '--json' => true,
        ]));
        $freshSettlement = $settlement->fresh();
        $this->assertSame($business, collect($businessKeys)->mapWithKeys(fn (string $key) => [$key => $freshSettlement->getRawOriginal($key)])->all());
        $this->assertSame($allocations, $settlement->allocations()->orderBy('id')->get()->map->only(array_keys($allocations[0]))->all());
        $this->assertTrue(app(ExpenseAuditService::class)->run()['ok']);
        $this->assertSame('document_checksum_mismatch', ChecksumRepairHistory::firstOrFail()->classification);
        $this->assertSame($document->id, ChecksumRepairHistory::first()->record_id);
    }

    public function test_ambiguous_and_cross_scope_records_are_refused_or_excluded(): void
    {
        [$document] = $this->corruptReceipt();
        $other = $this->phaseThreeContext();
        $otherDocument = $this->validReceipt($other);
        $otherDocument->forceFill(['verification_token_encrypted' => 'not-authenticated'])->saveQuietly();
        Storage::disk('local')->put($otherDocument->path, 'foreign bytes');

        $this->assertSame(1, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--record' => $otherDocument->id,
            '--apply' => true,
            '--json' => true,
        ]));
        $refused = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $refused['refused']);
        $this->assertSame(0, ChecksumRepairHistory::count());

        $this->assertSame(0, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--organization' => $document->organization_id,
            '--residence' => $document->residence_id,
            '--receipt-only' => true,
            '--json' => true,
        ]));
        $scoped = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$document->id], collect($scoped['records'])->pluck('record_id')->all());
    }

    public function test_stale_dry_run_evidence_is_refused(): void
    {
        [$document] = $this->corruptReceipt();
        $inspection = app(FinancialDocumentRecoveryService::class)->inspect($document);
        Storage::disk('local')->put($document->path, 'different foreign bytes');

        $this->assertSame(1, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--record' => $document->id,
            '--expected-evidence' => $inspection['evidence_fingerprint'],
            '--apply' => true,
            '--json' => true,
        ]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('refused', $result['records'][0]['outcome']);
        $this->assertStringContainsString('changed after dry-run', $result['records'][0]['error']);
        $this->assertSame(0, ChecksumRepairHistory::count());
    }

    public function test_post_write_failure_rolls_back_database_history_and_file(): void
    {
        [$document] = $this->corruptReceipt();
        $beforeBytes = Storage::disk('local')->get($document->path);
        $beforeChecksum = $document->checksum;
        app()->instance(FinancialDocumentMutationGuard::class, new class extends FinancialDocumentMutationGuard
        {
            public function authorized(callable $callback): mixed
            {
                throw new RuntimeException('forced post-write failure');
            }
        });

        try {
            app(FinancialDocumentRecoveryService::class)->repair($document->id);
            $this->fail('Recovery should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced post-write failure', $exception->getMessage());
        }

        $this->assertSame($beforeBytes, Storage::disk('local')->get($document->path));
        $this->assertSame($beforeChecksum, $document->fresh()->checksum);
        $this->assertSame(0, ChecksumRepairHistory::count());
    }

    public function test_repair_history_and_finalized_document_fields_are_immutable(): void
    {
        [$document] = $this->corruptReceipt();
        app(FinancialDocumentRecoveryService::class)->repair($document->id);
        $history = ChecksumRepairHistory::firstOrFail();

        try {
            $document->fresh()->update(['checksum' => str_repeat('a', 64)]);
            $this->fail('Checksum mutation should be blocked.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(LogicException::class);
        $history->update(['classification' => 'stale_checksum']);
    }

    public function test_legacy_checksum_version_defaults_without_rewriting_valid_records(): void
    {
        $context = $this->phaseThreeContext();
        $document = $this->validReceipt($context);
        $before = collect(['checksum', 'updated_at'])->mapWithKeys(fn (string $key) => [$key => $document->getRawOriginal($key)])->all();

        $this->assertSame(FinancialDocumentChecksumService::VERSION, $document->checksum_version);
        $this->assertSame(0, Artisan::call('evosyndic:repair-financial-document-checksums', [
            '--record' => $document->id,
            '--apply' => true,
            '--json' => true,
        ]));
        $fresh = $document->fresh();
        $this->assertSame($before, collect(array_keys($before))->mapWithKeys(fn (string $key) => [$key => $fresh->getRawOriginal($key)])->all());
        $this->assertSame(0, ChecksumRepairHistory::count());
    }

    private function corruptReceipt(): array
    {
        $context = $this->phaseThreeContext();
        $document = $this->validReceipt($context);
        $payment = $document->subject;
        Storage::disk('local')->put($document->path, 'foreign database receipt bytes');

        return [$document->fresh(), $payment->fresh()];
    }

    private function validReceipt(array $context): FinancialDocument
    {
        $payment = Payment::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'financial_exercise_id' => $context['exercise']->id,
            'received_from' => 'Checksum QA',
            'payment_date' => '2026-07-01',
            'amount_cents' => 10000,
            'method' => 'cash',
            'financial_account_id' => $context['account']->id,
            'status' => 'validated',
            'number' => 'PAY-'.str()->uuid(),
            'validated_at' => now(),
            'validated_by' => $context['user']->id,
        ]);
        FinancialAccountMovement::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'financial_account_id' => $context['account']->id,
            'financial_exercise_id' => $context['exercise']->id,
            'payment_id' => $payment->id,
            'direction' => 'credit',
            'operational_kind' => 'payment_receipt',
            'amount_cents' => $payment->amount_cents,
            'occurred_on' => $payment->payment_date,
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'description' => 'Checksum QA payment',
            'created_by' => $context['user']->id,
        ]);
        $token = str()->random(64);
        $number = 'REC-'.str()->upper(str()->random(10));
        $bytes = app(FinancialDocumentRenderer::class)->receipt(
            $payment->load(['residence.organization', 'residence.media', 'payer', 'account', 'allocations.charge.fundCall', 'allocations.lot']),
            $number,
            'fr',
            $token,
        );
        $path = "checksum-tests/{$number}.pdf";
        Storage::disk('local')->put($path, $bytes);

        return FinancialDocument::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'type' => 'receipt',
            'number' => $number,
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'locale' => 'fr',
            'version' => 1,
            'disk' => 'local',
            'path' => $path,
            'checksum' => hash('sha256', $bytes),
            'checksum_version' => FinancialDocumentChecksumService::VERSION,
            'verification_token_hash' => hash('sha256', $token),
            'verification_token_encrypted' => Crypt::encryptString($token),
            'status' => 'valid',
            'generated_at' => now(),
            'generated_by' => $context['user']->id,
        ]);
    }

    private function corruptVoucher(): array
    {
        $context = $this->phaseThreeContext();
        $invoice = $this->makePhaseThreeInvoice($context, 10000, '2026-07-15', 'validated');
        $settlement = SupplierSettlement::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'financial_exercise_id' => $context['exercise']->id,
            'supplier_id' => $context['supplier']->id,
            'financial_account_id' => $context['account']->id,
            'settlement_date' => '2026-07-10',
            'amount_cents' => 10000,
            'method' => 'cash',
        ]);
        $settlement = app(SupplierSettlementWorkflow::class)->validate($settlement, $context['user'], 'manual', [[
            'supplier_invoice_id' => $invoice->id,
            'supplier_invoice_line_id' => $invoice->lines()->first()->id,
            'amount_cents' => 10000,
        ]]);
        $document = $settlement->documents()->firstOrFail();
        Storage::disk('local')->put($document->path, 'foreign database voucher bytes');

        return [$document->fresh(), $settlement->fresh()];
    }

    private function fileChecksum(FinancialDocument $document): string
    {
        return hash('sha256', Storage::disk($document->disk)->get($document->path));
    }
}
