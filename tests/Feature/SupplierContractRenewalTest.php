<?php

namespace Tests\Feature;

use App\Models\SupplierContract;
use App\Services\SupplierContractAttachmentService;
use App\Services\SupplierContractRenewalService;
use App\Services\SupplierContractWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class SupplierContractRenewalTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    public static function renewalDates(): array
    {
        return [
            '28 day February' => ['2023-02-28', 'monthly', '2023-03-01', '2023-03-31'],
            'leap year February 29' => ['2024-02-29', 'monthly', '2024-03-01', '2024-03-31'],
            'April 30' => ['2026-04-30', 'monthly', '2026-05-01', '2026-05-31'],
            'month ending on 31st' => ['2026-05-31', 'monthly', '2026-06-01', '2026-06-30'],
            'end of year' => ['2026-12-31', 'monthly', '2027-01-01', '2027-01-31'],
            'quarterly' => ['2026-03-31', 'quarterly', '2026-04-01', '2026-06-30'],
            'yearly leap transition' => ['2024-02-29', 'yearly', '2024-03-01', '2025-02-28'],
        ];
    }

    #[DataProvider('renewalDates')]
    public function test_successor_date_boundaries_are_deterministic(string $endsOn, string $frequency, string $expectedStart, string $expectedEnd): void
    {
        $context = $this->phaseThreeContext();
        $contract = $this->contract($context, ['ends_on' => $endsOn, 'billing_frequency' => $frequency]);

        app(SupplierContractRenewalService::class)->dispatch(CarbonImmutable::parse($endsOn), [], true);
        $successor = $contract->renewals()->firstOrFail();

        $this->assertSame($expectedStart, $successor->starts_on->toDateString());
        $this->assertSame($expectedEnd, $successor->ends_on->toDateString());
        $this->assertSame('expired', $contract->fresh()->status);
        $this->assertSame($contract->id, $successor->renewed_from_id);
    }

    public static function processingDates(): array
    {
        return ['before window' => ['2026-05-20', 0], 'first day in window' => ['2026-05-21', 1], 'on end date' => ['2026-05-31', 1]];
    }

    #[DataProvider('processingDates')]
    public function test_configured_processing_window(string $date, int $eligible): void
    {
        $context = $this->phaseThreeContext();
        $contract = $this->contract($context, ['ends_on' => '2026-05-31', 'notice_days' => 10]);

        $result = app(SupplierContractRenewalService::class)->dispatch(CarbonImmutable::parse($date), [], false);

        $this->assertSame($eligible, $result['eligible']);
        $this->assertSame(0, $contract->renewals()->count());
    }

    public static function ineligibleContracts(): array
    {
        return ['already renewed' => ['renewed'], 'terminated contract' => ['terminated'], 'incomplete frequency' => ['incomplete']];
    }

    #[DataProvider('ineligibleContracts')]
    public function test_duplicate_terminated_and_incomplete_contracts(string $case): void
    {
        $context = $this->phaseThreeContext();
        $contract = $this->contract($context, $case === 'incomplete' ? ['billing_frequency' => 'one_off'] : []);
        if ($case === 'renewed') {
            app(SupplierContractWorkflow::class)->renew($contract, $context['user'], '2026-06-01', '2026-06-30', 'Manual renewal');
        } elseif ($case === 'terminated') {
            app(SupplierContractWorkflow::class)->terminate($contract, $context['user'], 'Contract terminated');
        }

        $result = app(SupplierContractRenewalService::class)->dispatch(CarbonImmutable::parse('2026-05-31'), [], true);

        if ($case === 'incomplete') {
            $this->assertSame(1, $result['failed']);
            $this->assertSame(0, $contract->renewals()->count());
        } else {
            $this->assertSame(0, $result['eligible']);
            $this->assertLessThanOrEqual(1, $contract->renewals()->count());
        }
    }

    public function test_approved_fields_and_only_reusable_attachments_are_copied_without_termination_metadata(): void
    {
        $context = $this->phaseThreeContext();
        $contract = $this->contract($context, ['description' => 'Approved scope', 'amount_cents' => 12345, 'expense_category_id' => $context['category']->id, 'termination_reason' => 'must not copy', 'terminated_on' => '2025-01-01']);
        $attachments = app(SupplierContractAttachmentService::class);
        $attachments->upload($contract, UploadedFile::fake()->createWithContent('reusable.pdf', '%PDF reusable'), $context['user'], true);
        $attachments->upload($contract, UploadedFile::fake()->createWithContent('temporary.pdf', '%PDF temporary'), $context['user'], false);

        app(SupplierContractRenewalService::class)->dispatch(CarbonImmutable::parse('2026-05-31'), [], true);
        $successor = $contract->renewals()->firstOrFail();

        $this->assertSame($context['supplier']->id, $successor->supplier_id);
        $this->assertSame($context['category']->id, $successor->expense_category_id);
        $this->assertSame(12345, $successor->amount_cents);
        $this->assertSame('monthly', $successor->billing_frequency);
        $this->assertNull($successor->terminated_on);
        $this->assertNull($successor->termination_reason);
        $this->assertSame(['reusable.pdf'], $successor->attachments()->pluck('name')->all());
    }

    public function test_repeated_automatic_and_manual_calls_share_duplicate_guard(): void
    {
        $context = $this->phaseThreeContext();
        $automatic = $this->contract($context);
        $service = app(SupplierContractRenewalService::class);
        $service->dispatch(CarbonImmutable::parse('2026-05-31'), [], true);
        $service->dispatch(CarbonImmutable::parse('2026-05-31'), [], true);
        $this->assertSame(1, $automatic->renewals()->count());

        $manual = $this->contract($context, ['reference' => 'MANUAL']);
        $workflow = app(SupplierContractWorkflow::class);
        $first = $workflow->renew($manual, $context['user'], '2026-06-01', '2026-06-30', 'Manual renewal');
        $second = $workflow->renew($manual, $context['user'], '2026-06-01', '2026-06-30', 'Repeated manual renewal');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $manual->renewals()->count());
    }

    private function contract(array $context, array $overrides = []): SupplierContract
    {
        return SupplierContract::create(array_merge([
            'organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id,
            'supplier_id' => $context['supplier']->id, 'reference' => 'AUTO-'.str()->random(8), 'title' => 'Maintenance',
            'starts_on' => '2026-05-01', 'ends_on' => '2026-05-31', 'billing_frequency' => 'monthly',
            'renewal_type' => 'automatic', 'notice_days' => 0, 'status' => 'active',
        ], $overrides));
    }
}
