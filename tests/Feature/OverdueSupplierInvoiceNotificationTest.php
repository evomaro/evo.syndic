<?php

namespace Tests\Feature;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\SupplierCreditNote;
use App\Models\SupplierSettlement;
use App\Notifications\PortalNotification;
use App\Services\CreditNoteWorkflow;
use App\Services\OverdueSupplierInvoiceNotificationService;
use App\Services\SupplierInvoiceWorkflow;
use App\Services\SupplierSettlementWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class OverdueSupplierInvoiceNotificationTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                return '%PDF test';
            }
        });
    }

    public static function evaluationDates(): array
    {
        return [
            'not yet due' => ['2026-04-02', null], 'due today' => ['2026-04-01', null],
            'one day overdue' => ['2026-03-31', 'new'], 'exactly seven days' => ['2026-03-25', '7'],
            'eight days' => ['2026-03-24', '7'], 'exactly thirty days' => ['2026-03-02', '30'],
            'exactly sixty days' => ['2026-01-31', '60'], 'exactly ninety days' => ['2026-01-01', '90'],
            'more than ninety days' => ['2025-12-31', '90'],
        ];
    }

    #[DataProvider('evaluationDates')]
    public function test_evaluation_date_boundaries(string $dueDate, ?string $expectedStage): void
    {
        $context = $this->phaseThreeContext();
        $this->makePhaseThreeInvoice($context, 10000, $dueDate, 'validated');

        $result = app(OverdueSupplierInvoiceNotificationService::class)->dispatch(CarbonImmutable::parse('2026-04-01'), [], true);

        $this->assertSame($expectedStage === null ? 0 : 1, $result['events']);
        if ($expectedStage === null) {
            Notification::assertNothingSent();
        } else {
            Notification::assertSentTo($context['user'], PortalNotification::class, fn ($notification) => $notification->payload['stage'] === $expectedStage);
        }
    }

    public static function excludedStatuses(): array
    {
        return ['fully settled' => ['settled'], 'fully credited' => ['credited'], 'cancelled' => ['cancelled'], 'draft' => ['draft']];
    }

    #[DataProvider('excludedStatuses')]
    public function test_non_payable_or_non_validated_invoice_is_excluded(string $state): void
    {
        $context = $this->phaseThreeContext();
        $invoice = $this->makePhaseThreeInvoice($context, 10000, '2026-01-01', $state === 'draft' ? 'draft' : 'validated');
        if ($state === 'settled') {
            $settlement = SupplierSettlement::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'supplier_id' => $context['supplier']->id, 'financial_account_id' => $context['account']->id, 'settlement_date' => '2026-02-01', 'amount_cents' => 10000, 'method' => 'cash']);
            app(SupplierSettlementWorkflow::class)->validate($settlement, $context['user']);
        } elseif ($state === 'credited') {
            $credit = SupplierCreditNote::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'supplier_id' => $context['supplier']->id, 'credit_date' => '2026-02-01', 'amount_cents' => 10000]);
            app(CreditNoteWorkflow::class)->validate($credit, $context['user'], [['supplier_invoice_id' => $invoice->id, 'amount_cents' => 10000]]);
        } elseif ($state === 'cancelled') {
            app(SupplierInvoiceWorkflow::class)->cancel($invoice, $context['user'], 'Facture fournisseur annulée');
        }

        $result = app(OverdueSupplierInvoiceNotificationService::class)->dispatch(CarbonImmutable::parse('2026-04-01'), [], true);

        $this->assertSame(0, $result['events']);
        Notification::assertNothingSent();
    }

    public function test_partially_settled_invoice_remains_eligible(): void
    {
        $context = $this->phaseThreeContext();
        $invoice = $this->makePhaseThreeInvoice($context, 10000, '2026-03-25', 'validated');
        $settlement = SupplierSettlement::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'supplier_id' => $context['supplier']->id, 'financial_account_id' => $context['account']->id, 'settlement_date' => '2026-03-28', 'amount_cents' => 1000, 'method' => 'cash']);
        app(SupplierSettlementWorkflow::class)->validate($settlement, $context['user']);

        app(OverdueSupplierInvoiceNotificationService::class)->dispatch(CarbonImmutable::parse('2026-04-01'), [], true);

        $this->assertSame('partial', $invoice->fresh()->status);
        Notification::assertSentTo($context['user'], PortalNotification::class, fn ($notification) => $notification->payload['stage'] === '7');
    }

    public function test_repeated_execution_is_idempotent_and_changed_due_date_creates_new_key(): void
    {
        $context = $this->phaseThreeContext();
        $invoice = $this->makePhaseThreeInvoice($context, 10000, '2026-03-31', 'validated');
        $service = app(OverdueSupplierInvoiceNotificationService::class);
        $date = CarbonImmutable::parse('2026-04-01');
        $service->dispatch($date, [], true);
        $service->dispatch($date, [], true);
        $this->assertSame(2, DB::table('notification_dispatches')->count());

        $invoice->withoutEvents(fn () => $invoice->update(['due_date' => '2026-03-30']));
        $service->dispatch($date, [], true);

        $this->assertSame(4, DB::table('notification_dispatches')->count());
        $this->assertSame(2, DB::table('notification_dispatches')->distinct()->count('event_key'));
    }

    public function test_dry_run_reports_candidates_without_writes(): void
    {
        $context = $this->phaseThreeContext();
        $this->makePhaseThreeInvoice($context, 10000, '2026-03-31', 'validated');

        $result = app(OverdueSupplierInvoiceNotificationService::class)->dispatch(CarbonImmutable::parse('2026-04-01'), [], false);

        $this->assertSame(1, $result['events']);
        $this->assertSame(2, $result['deliveries']);
        $this->assertDatabaseCount('notification_dispatches', 0);
        Notification::assertNothingSent();
    }
}
