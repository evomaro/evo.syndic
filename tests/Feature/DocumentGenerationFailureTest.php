<?php

namespace Tests\Feature;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\DocumentGenerationAttempt;
use App\Models\FinancialDocument;
use App\Models\SupplierSettlement;
use App\Services\SupplierSettlementWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class DocumentGenerationFailureTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_renderer_failure_preserves_settlement_number_and_retry_recovers_single_document(): void
    {
        $context = $this->phaseThreeContext();
        $this->makePhaseThreeInvoice($context, 10000, '2026-03-01', 'validated');
        $settlement = SupplierSettlement::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'supplier_id' => $context['supplier']->id, 'financial_account_id' => $context['account']->id, 'settlement_date' => '2026-03-02', 'amount_cents' => 10000, 'method' => 'cash']);
        $this->failingRenderer();

        try {
            app(SupplierSettlementWorkflow::class)->validate($settlement, $context['user']);
            $this->fail('Renderer failure should escape after the financial transaction commits.');
        } catch (RuntimeException $exception) {
            $this->assertSame('renderer secret', $exception->getMessage());
        }

        $settlement->refresh();
        $attempt = DocumentGenerationAttempt::firstOrFail();
        $originalNumber = $attempt->number;
        $this->assertSame('validated', $settlement->status);
        $this->assertNotNull($settlement->number);
        $this->assertSame('failed', $attempt->status);
        $this->assertStringNotContainsString('secret', $attempt->failure_summary);

        $this->actingAs($context['user'])->post(route('supplier-settlements.voucher.retry', $settlement))->assertServerError();
        $this->post(route('supplier-settlements.voucher.retry', $settlement))->assertServerError();
        $this->assertSame(3, $attempt->fresh()->attempt_count);
        $this->assertSame(2, DB::table('notification_dispatches')->where('event_type', 'document_generation_failed')->count());

        $this->successfulRenderer();
        $this->post(route('supplier-settlements.voucher.retry', $settlement))->assertRedirect();
        $document = FinancialDocument::firstOrFail();
        $this->assertSame($originalNumber, $document->number);
        $this->assertSame('generated', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->resolved_at);
        $this->assertDatabaseCount('financial_documents', 1);

        $this->post(route('supplier-settlements.voucher.retry', $settlement))->assertRedirect();
        $this->assertDatabaseCount('financial_documents', 1);
        $this->assertSame($originalNumber, FinancialDocument::first()->number);
    }

    public function test_retry_and_download_reject_unauthorized_cross_residence_and_checksum_corruption(): void
    {
        $context = $this->phaseThreeContext();
        $this->makePhaseThreeInvoice($context, 10000, '2026-03-01', 'validated');
        $settlement = SupplierSettlement::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'supplier_id' => $context['supplier']->id, 'financial_account_id' => $context['account']->id, 'settlement_date' => '2026-03-02', 'amount_cents' => 10000, 'method' => 'cash']);
        $this->successfulRenderer();
        app(SupplierSettlementWorkflow::class)->validate($settlement, $context['user']);
        $document = FinancialDocument::firstOrFail();

        $manager = $this->phaseThreeContext('manager');
        $this->actingAs($manager['user'])->post(route('supplier-settlements.voucher.retry', $settlement))->assertForbidden();
        $foreign = $this->phaseThreeContext();
        $this->actingAs($foreign['user'])->post(route('supplier-settlements.voucher.retry', $settlement))->assertNotFound();

        Storage::disk('local')->put($document->path, 'corrupt');
        $this->actingAs($context['user'])->get(route('supplier-vouchers.download', $document))->assertStatus(409);
    }

    private function failingRenderer(): void
    {
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                throw new RuntimeException('renderer secret');
            }
        });
    }

    private function successfulRenderer(): void
    {
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                return '%PDF-1.4 recovered-'.$locale;
            }
        });
    }
}
