<?php

namespace Tests\Feature;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\ChargeCategory;
use App\Models\Contact;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\FundCallSchedule;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Residence;
use App\Models\User;
use App\Services\CollectionAuditService;
use App\Services\ContactUserLinkService;
use App\Services\DompdfReceiptRenderer;
use App\Services\FinancialExerciseLifecycleService;
use App\Services\FundCallScheduleService;
use App\Services\FundCallWorkflow;
use App\Services\LotStatementService;
use App\Services\PaymentWorkflow;
use App\Services\ReceiptService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PhaseTwoHardeningTest extends TestCase
{
    use RefreshDatabase;

    public static function moneyCases(): array
    {
        return [
            'one centime' => ['0.01', 1], 'comma decimal' => ['1250,50', 125050],
            'space grouping' => ['1 250,50', 125050], 'narrow no-break grouping' => ["1\u{202F}250,50", 125050],
            'large amount' => ['999999999999999.99', 99999999999999999],
        ];
    }

    public static function invalidMoneyCases(): array
    {
        return [['1.234'], ['1 25,00'], ['1,2,3'], ['12.'], ['1e3'], ['1000000000000000.00']];
    }

    public static function agingCases(): array
    {
        return [[0, 'current'], [1, '1-30'], [30, '1-30'], [31, '31-60'], [60, '31-60'], [61, '61-90'], [90, '61-90'], [91, '>90']];
    }

    public static function cancellationCases(): array
    {
        return [['unpaid', true], ['partial', false], ['paid', false]];
    }

    public static function scheduleDateCases(): array
    {
        return [
            'month end' => ['monthly', null, '2026-02-28'],
            'quarter end' => ['quarterly', null, '2026-04-30'],
            'semiannual end' => ['semiannual', null, '2026-07-31'],
            'custom two months' => ['custom', 2, '2026-03-31'],
        ];
    }

    #[DataProvider('moneyCases')]
    public function test_money_parser_accepts_unambiguous_boundary_inputs(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::cents($input));
    }

    #[DataProvider('invalidMoneyCases')]
    public function test_money_parser_rejects_ambiguous_precision_and_overflow(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::cents($input);
    }

    #[DataProvider('agingCases')]
    public function test_aging_boundaries_are_mutually_exclusive(int $days, string $bucket): void
    {
        $c = $this->context();
        $today = CarbonImmutable::parse('2026-07-21');
        $this->travelTo($today);
        $call = $this->draftCall($c, 100, '=Aging');
        $call->update(['issue_date' => '2026-01-01', 'due_date' => $today->subDays($days)->toDateString()]);
        app(FundCallWorkflow::class)->validate($call, $c['user']);

        $this->actingAs($c['user'])->get(route('finance.outstanding'))->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('charges.data.0.aging', $bucket),
        );
    }

    public function test_receipt_renderer_failure_rolls_back_and_retry_is_idempotent(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        $payment = $this->payment($c, 1000);
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                throw new RuntimeException('renderer unavailable');
            }
        });

        try {
            app(PaymentWorkflow::class)->validate($payment, $c['user']);
            $this->fail('The renderer failure should bubble out.');
        } catch (RuntimeException $exception) {
            $this->assertSame('renderer unavailable', $exception->getMessage());
        }
        $this->assertSame('draft', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('financial_account_movements', 0);
        $this->assertDatabaseCount('financial_documents', 0);

        app()->forgetInstance(ReceiptPdfRenderer::class);
        app()->bind(ReceiptPdfRenderer::class, DompdfReceiptRenderer::class);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user']);
        $document = $payment->documents->first();
        $number = $document->number;
        $tokenHash = $document->verification_token_hash;
        app(ReceiptService::class)->generate($payment, $c['user']);
        $this->assertSame(1, $payment->documents()->count());
        $this->assertSame($number, $payment->documents()->first()->number);
        $this->assertSame($tokenHash, $payment->documents()->first()->verification_token_hash);
    }

    public function test_missing_receipt_is_detected_and_regenerated_without_renumbering(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1000), $c['user']);
        $document = $payment->documents()->first();
        $number = $document->number;
        Storage::disk('local')->delete($document->path);

        $audit = app(CollectionAuditService::class)->audit(['payment' => $payment->id]);
        $this->assertFalse($audit['ok']);
        $this->assertContains('receipt_file_missing', collect($audit['violations'])->pluck('code'));
        $this->actingAs($c['user'])->post(route('receipts.retry', $payment))->assertRedirect();
        $this->assertSame($number, $payment->documents()->first()->number);
        Storage::disk('local')->assertExists($document->path);
        $this->assertTrue(app(CollectionAuditService::class)->audit(['payment' => $payment->id])['ok']);
    }

    #[DataProvider('cancellationCases')]
    public function test_fund_call_cancellation_never_orphans_allocations(string $state, bool $allowed): void
    {
        Storage::fake('local');
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        if ($state !== 'unpaid') {
            $amount = $state === 'partial' ? 500 : 1000;
            app(PaymentWorkflow::class)->validate($this->payment($c, $amount), $c['user'], 'manual', [], [['lot_charge_id' => $call->charges()->first()->id, 'amount_cents' => $amount]]);
        }

        if (! $allowed) {
            $this->expectException(ValidationException::class);
        }
        app(FundCallWorkflow::class)->cancel($call, $c['user'], 'Correction justifiée');
        if ($allowed) {
            $this->assertSame('cancelled', $call->fresh()->status);
        }
    }

    public function test_credit_reversal_records_actor_reason_restoration_and_prevents_second_reversal(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1500), $c['user'], 'selected_lots', []);
        app(PaymentWorkflow::class)->allocateCredit($payment, $c['user'], [['lot_charge_id' => $call->charges()->first()->id, 'amount_cents' => 1000]]);
        app(PaymentWorkflow::class)->reverse($payment, $c['user'], 'Encaissement rejeté');

        $allocation = $payment->allocations()->first();
        $this->assertSame($c['user']->id, $allocation->reversed_by);
        $this->assertSame('Encaissement rejeté', $allocation->reversal_reason);
        $this->assertSame(1000, $allocation->restored_charge_cents);
        $this->assertSame(0, $c['account']->fresh()->current_balance_cents);
        $this->assertSame('reversed', $payment->documents()->first()->status);
        $this->expectException(ValidationException::class);
        app(PaymentWorkflow::class)->reverse($payment, $c['user'], 'Deuxième tentative');
    }

    public function test_exercise_close_readiness_reopen_and_closed_write_protection(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1500), $c['user']);
        $document = $payment->documents()->first();
        Storage::disk('local')->delete($document->path);
        $this->actingAs($c['user'])->post(route('financial-exercises.transition', $c['exercise']), ['action' => 'close'])->assertSessionHasErrors('action');
        app(ReceiptService::class)->generate($payment, $c['user']);
        $this->actingAs($c['user'])->post(route('financial-exercises.transition', $c['exercise']), ['action' => 'close'])->assertRedirect();
        $this->assertSame('closed', $c['exercise']->fresh()->status);
        try {
            app(PaymentWorkflow::class)->reverse($payment, $c['user'], 'Closed period attempt');
            $this->fail('A closed period must reject reversal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('exercise', $exception->errors());
        }
        $this->actingAs($c['user'])->post(route('financial-exercises.transition', $c['exercise']), ['action' => 'reopen'])->assertSessionHasErrors('reason');
        $this->actingAs($c['user'])->post(route('financial-exercises.transition', $c['exercise']), ['action' => 'reopen', 'reason' => 'Correction contrôlée'])->assertRedirect();
        $this->assertSame('open', $c['exercise']->fresh()->status);
        $this->assertSame('Correction contrôlée', $c['exercise']->fresh()->metadata['reopen_history'][0]['reason']);
        $this->assertDatabaseHas('activity_log', ['description' => 'financial_exercise.reopen']);
    }

    public function test_identified_advance_credit_closes_and_allocates_across_exercises_without_rewriting_receipt(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-02-10');
        $c = $this->context();
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1500), $c['user'], 'selected_lots', []);
        $document = $payment->documents()->first();
        $receiptKeys = ['id', 'number', 'checksum', 'path', 'generated_at', 'updated_at'];
        $receiptState = collect($receiptKeys)->mapWithKeys(fn ($key) => [$key => $document->getRawOriginal($key)])->all();

        app(FinancialExerciseLifecycleService::class)->transition($c['exercise'], 'close', $c['user']);
        $this->assertSame('closed', $c['exercise']->fresh()->status);
        $this->assertSame(1500, $payment->fresh()->credit_cents);

        $nextExercise = FinancialExercise::factory()->create([
            'organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id,
            'name' => 'Exercice 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'open',
        ]);
        $next = $this->draftCall(array_replace($c, ['exercise' => $nextExercise]), 1000, 'Charge 2027');
        $next->update(['issue_date' => '2027-02-01', 'due_date' => '2027-02-15']);
        $next = app(FundCallWorkflow::class)->validate($next, $c['user']);
        $charge = $next->charges()->first();

        $this->travelTo('2027-02-10');
        app(PaymentWorkflow::class)->allocateCredit($payment, $c['user'], [['lot_charge_id' => $charge->id, 'amount_cents' => 500]]);
        $allocation = $payment->allocations()->first();
        $this->assertSame('2027-02-10', $allocation->allocated_on->toDateString());
        $this->assertSame($c['exercise']->id, $payment->fresh()->financial_exercise_id);
        $this->assertSame('2026-02-10', $payment->fresh()->payment_date->toDateString());
        $this->assertSame($nextExercise->id, $charge->financial_exercise_id);
        $freshDocument = $document->fresh();
        $this->assertSame($receiptState, collect($receiptKeys)->mapWithKeys(fn ($key) => [$key => $freshDocument->getRawOriginal($key)])->all());
        $this->assertSame(1000, $payment->fresh()->credit_cents);

        $statement = app(LotStatementService::class)->build($c['residence']->id, $c['lot']->id);
        $this->assertSame(500, $statement['closing_balance_cents']);
        $this->assertTrue($statement['transactions']->contains(fn ($row) => $row['type'] === 'payment' && $row['date'] === '2027-02-10'));
        $this->actingAs($c['user'])->get(route('finance.index', ['from' => '2027-01-01', 'to' => '2027-12-31']))->assertInertia(fn (Assert $page) => $page
            ->where('metrics.called_cents', 1000)->where('metrics.collected_cents', 500)->where('metrics.outstanding_cents', 500)->where('metrics.credit_cents', 0));

        app(PaymentWorkflow::class)->reverse($payment, $c['user'], 'Remboursement avance 2026');
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame($nextExercise->id, $payment->movements()->where('operational_kind', 'payment_reversal')->value('financial_exercise_id'));
        $this->assertSame(1000, $charge->fresh()->outstanding_cents);
        $this->assertSame(1000, app(LotStatementService::class)->build($c['residence']->id, $c['lot']->id)['closing_balance_cents']);
        $this->assertTrue(app(CollectionAuditService::class)->audit(['payment' => $payment->id])['ok']);
    }

    public function test_unidentified_payment_is_not_credit_blocks_closing_and_can_be_identified_by_authorized_workflow(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $payment = $this->payment($c, 1200);
        $payment->update(['payer_contact_id' => null, 'received_from' => 'Versement à identifier']);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user']);

        $this->assertSame(0, $payment->credit_cents);
        $this->assertSame(1200, $payment->unallocated_cents);
        $this->assertCount(0, $payment->allocations);
        $this->assertNotEmpty(app(FinancialExerciseLifecycleService::class)->closeReadiness($c['exercise']));

        app(PaymentWorkflow::class)->identifyPayer($payment, $c['user'], $c['contact']->id);
        $this->assertSame(1200, $payment->fresh()->credit_cents);
        $this->assertSame([], app(FinancialExerciseLifecycleService::class)->closeReadiness($c['exercise']));
        $this->assertDatabaseHas('activity_log', ['description' => 'payment.payer_identified']);
    }

    public function test_inconsistent_payment_blocks_closing_and_is_reported_by_audit(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1000), $c['user'], 'selected_lots', []);
        $payment->movements()->delete();

        $issues = app(FinancialExerciseLifecycleService::class)->closeReadiness($c['exercise']);
        $audit = app(CollectionAuditService::class)->audit(['exercise' => $c['exercise']->id]);
        $this->assertNotEmpty($issues);
        $this->assertFalse($audit['ok']);
        $this->assertContains('movement_payment_mismatch', collect($audit['violations'])->pluck('code'));
    }

    public function test_advance_credit_rejects_cross_residence_and_lots_not_owned_by_its_payer(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1000), $c['user'], 'selected_lots', []);
        $other = $this->context();
        $foreignCharge = app(FundCallWorkflow::class)->validate($this->draftCall($other, 500), $other['user'])->charges()->first();
        try {
            app(PaymentWorkflow::class)->allocateCredit($payment, $c['user'], [['lot_charge_id' => $foreignCharge->id, 'amount_cents' => 100]]);
            $this->fail('Cross-residence credit allocation must fail.');
        } catch (ModelNotFoundException $exception) {
            $this->assertTrue(true);
        }

        $newContact = Contact::factory()->for($c['organization'])->create();
        $c['lot']->ownerships()->first()->update(['ends_on' => '2026-01-31']);
        $c['lot']->ownerships()->create(['contact_id' => $newContact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2026-02-01']);
        $charge = app(FundCallWorkflow::class)->validate($this->draftCall($c, 500, 'Après transfert'), $c['user'])->charges()->first();
        $this->expectException(ValidationException::class);
        app(PaymentWorkflow::class)->allocateCredit($payment, $c['user'], [['lot_charge_id' => $charge->id, 'amount_cents' => 100]]);
    }

    public function test_contact_user_links_are_admin_governed_tenant_scoped_and_revocable(): void
    {
        $c = $this->context();
        $linkedUser = User::factory()->create();
        $c['organization']->users()->attach($linkedUser, ['role' => 'manager', 'all_residences' => true]);
        $linkedUser->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        app(ContactUserLinkService::class)->link($c['contact'], $linkedUser, $c['user'], $c['organization']);
        $this->actingAs($linkedUser)->get(route('owner-finance.index'))->assertOk();
        app(ContactUserLinkService::class)->revoke($c['contact'], $linkedUser, $c['user'], $c['organization']);
        $this->actingAs($linkedUser)->get(route('owner-finance.index'))->assertForbidden();
        $this->assertDatabaseHas('contact_user', ['contact_id' => $c['contact']->id, 'user_id' => $linkedUser->id, 'revoked_by' => $c['user']->id]);
        $this->assertSame(2, Activity::whereIn('description', ['contact_user.linked', 'contact_user.revoked'])->count());

        $other = $this->context();
        $this->expectException(NotFoundHttpException::class);
        app(ContactUserLinkService::class)->link($other['contact'], $linkedUser, $c['user'], $c['organization']);
    }

    public function test_statement_opening_closing_order_and_csv_formula_protection(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $first = app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000, '=INJECT'), $c['user']);
        $second = $this->draftCall($c, 500, 'Mars');
        $second->update(['issue_date' => '2026-03-01', 'due_date' => '2026-03-15']);
        app(FundCallWorkflow::class)->validate($second, $c['user']);
        app(PaymentWorkflow::class)->validate($this->payment($c, 400, '2026-02-10'), $c['user']);
        $statement = app(LotStatementService::class)->build($c['residence']->id, $c['lot']->id, '2026-03-01', '2026-03-31');
        $this->assertSame(600, $statement['opening_balance_cents']);
        $this->assertSame(1100, $statement['closing_balance_cents']);
        $this->assertSame(['charge'], $statement['transactions']->pluck('type')->all());
        $response = $this->actingAs($c['user'])->get(route('finance.statements.csv', ['lot' => $c['lot']->id]));
        $response->assertOk();
        $this->assertStringContainsString("'=INJECT", $response->streamedContent());
        $this->assertSame(1000, $first->total_cents);

        $payment = Payment::first();
        app(PaymentWorkflow::class)->reverse($payment, $c['user'], 'Correction du relevé');
        app(FundCallWorkflow::class)->cancel($first, $c['user'], 'Charge annulée après extourne');
        $after = app(LotStatementService::class)->build($c['residence']->id, $c['lot']->id);
        $this->assertSame(500, $after['closing_balance_cents']);
        $this->assertSame(['payment', 'charge', 'payment_reversal'], $after['transactions']->pluck('type')->all());
    }

    #[DataProvider('scheduleDateCases')]
    public function test_schedule_month_end_and_custom_interval_boundaries(string $frequency, ?int $customMonths, string $expected): void
    {
        $c = $this->context();
        $schedule = $this->schedule($c, $frequency, $customMonths);
        $result = app(FundCallScheduleService::class)->generate($schedule, CarbonImmutable::parse('2026-01-31'), $c['user'], true);
        $this->assertSame('generated', $result['status']);
        $this->assertSame($expected, $schedule->fresh()->next_generation_on->toDateString());
        $this->assertSame($schedule->template, $schedule->generations()->first()->template_snapshot);
    }

    public function test_schedule_failure_is_recorded_and_retryable_without_duplicate_call(): void
    {
        $c = $this->context();
        $schedule = $this->schedule($c, 'monthly');
        $template = $schedule->template;
        $template['lines'][0]['amount'] = 'invalid';
        $schedule->update(['template' => $template]);
        try {
            app(FundCallScheduleService::class)->generate($schedule->fresh(), CarbonImmutable::parse('2026-01-31'), $c['user'], true);
            $this->fail('Invalid schedule generation should fail.');
        } catch (InvalidArgumentException) {
            $this->assertNotNull($schedule->fresh()->last_failed_at);
        }
        $template['lines'][0]['amount'] = '10.00';
        $schedule->update(['template' => $template]);
        $this->assertSame('generated', app(FundCallScheduleService::class)->generate($schedule->fresh(), CarbonImmutable::parse('2026-01-31'), $c['user'], true)['status']);
        $this->assertDatabaseCount('schedule_generations', 1);
        $this->assertDatabaseCount('fund_calls', 1);
        $this->assertNull($schedule->fresh()->last_failed_at);
        $schedule->update(['active' => false]);
        $this->assertSame('not_due', app(FundCallScheduleService::class)->generate($schedule->fresh(), CarbonImmutable::parse('2026-02-28'), $c['user'], true)['status']);
    }

    public function test_owner_transfer_visibility_separates_periods_inherited_debt_and_receipts(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $formerUser = User::factory()->create();
        $newUser = User::factory()->create();
        $c['organization']->users()->attach($formerUser, ['role' => 'maintenance_agent', 'all_residences' => true]);
        $c['organization']->users()->attach($newUser, ['role' => 'maintenance_agent', 'all_residences' => true]);
        foreach ([$formerUser, $newUser] as $user) {
            $user->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        }
        $newContact = Contact::factory()->for($c['organization'])->create();
        app(ContactUserLinkService::class)->link($c['contact'], $formerUser, $c['user'], $c['organization']);
        app(ContactUserLinkService::class)->link($newContact, $newUser, $c['user'], $c['organization']);
        $first = app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000, 'Avant transfert'), $c['user']);
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 300), $c['user']);
        $c['lot']->ownerships()->first()->update(['ends_on' => '2026-05-31']);
        $c['lot']->ownerships()->create(['contact_id' => $newContact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2026-06-01']);
        $second = $this->draftCall($c, 500, 'Après transfert');
        $second->update(['issue_date' => '2026-06-10', 'due_date' => '2026-06-20']);
        app(FundCallWorkflow::class)->validate($second, $c['user']);

        $this->actingAs($formerUser)->get(route('owner-finance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('lots', 1)->has('lots.0.charges', 1)->where('lots.0.is_current_owner', false)->where('lots.0.balance_cents', 700));
        $this->actingAs($newUser)->get(route('owner-finance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('lots', 1)->has('lots.0.charges', 1)->where('lots.0.is_current_owner', true)->where('lots.0.balance_cents', 1200)->where('lots.0.inherited_debt_cents', 700));
        $document = $payment->documents()->first();
        $this->actingAs($newUser)->get(route('receipts.download', $document))->assertForbidden();
        $this->actingAs($formerUser)->get(route('receipts.download', $document))->assertOk();
        $this->assertSame(1000, $first->total_cents);
    }

    public function test_collection_audit_detects_overallocation_and_is_read_only(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1000), $c['user']);
        $payment->allocations()->create(['lot_charge_id' => $call->charges()->first()->id, 'lot_id' => $c['lot']->id, 'amount_cents' => 1, 'allocation_order' => 99, 'allocated_by' => $c['user']->id]);
        $before = DB::table('payment_allocations')->count();
        $result = app(CollectionAuditService::class)->audit(['residence' => $c['residence']->id]);
        $this->assertFalse($result['ok']);
        $this->assertEqualsCanonicalizing(['payment_overallocated', 'charge_overallocated'], collect($result['violations'])->pluck('code')->filter(fn ($code) => str_contains($code, 'overallocated'))->all());
        $this->assertSame($before, DB::table('payment_allocations')->count());
        $this->assertSame(1, Artisan::call('evosyndic:audit-collections', ['--payment' => $payment->id, '--json' => true]));
        $this->assertStringContainsString('payment_overallocated', Artisan::output());
    }

    public function test_finance_dashboard_reconciles_called_collected_outstanding_credit_and_account(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        app(PaymentWorkflow::class)->validate($this->payment($c, 400), $c['user']);
        $this->actingAs($c['user'])->get(route('finance.index', ['from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.called_cents', 1000)->where('metrics.collected_cents', 400)->where('metrics.outstanding_cents', 600)
            ->where('metrics.credit_cents', 0)->where('metrics.collection_rate', 40)->where('accounts.0.balance_cents', 400));
    }

    public function test_qr_verification_is_private_neutral_and_rate_limited(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        $payment = $this->payment($c, 1000);
        $payment->update(['bank_reference' => 'SECRET-BANK', 'cheque_number' => 'SECRET-CHEQUE']);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user']);
        $document = $payment->documents()->first();
        $token = Crypt::decryptString($document->verification_token_encrypted);
        $response = $this->get(route('receipts.verify', $token))->assertOk();
        $response->assertDontSee('SECRET-BANK')->assertDontSee('SECRET-CHEQUE')->assertDontSee($c['contact']->primary_email ?? 'not-present');
        $this->get(route('receipts.verify', str_repeat('A', 64)))->assertNotFound()->assertInertia(fn (Assert $page) => $page->where('receipt', null));
        for ($i = 0; $i < 29; $i++) {
            $this->get(route('receipts.verify', $token));
        }
        $this->get(route('receipts.verify', $token))->assertTooManyRequests();
    }

    public function test_database_constraints_reject_duplicate_operational_movements(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 1000), $c['user']);
        $payment = app(PaymentWorkflow::class)->validate($this->payment($c, 1000), $c['user']);
        $original = $payment->movements()->first();
        $this->expectException(QueryException::class);
        FinancialAccountMovement::create($original->only(['organization_id', 'residence_id', 'financial_account_id', 'financial_exercise_id', 'payment_id', 'direction', 'operational_kind', 'amount_cents', 'occurred_on', 'source_type', 'source_id', 'description', 'created_by']));
    }

    private function context(): array
    {
        $user = User::factory()->create(['preferred_language' => 'fr']);
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $organization->users()->attach($user, ['role' => 'owner', 'all_residences' => true]);
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'status' => 'open']);
        $account = FinancialAccount::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'code' => 'bank', 'default_slot' => 1]);
        $category = ChargeCategory::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'code' => 'maintenance']);
        $lot = Lot::factory()->for($residence)->create(['reference' => 'A-01']);
        $contact = Contact::factory()->for($organization)->create();
        $lot->ownerships()->create(['contact_id' => $contact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2024-01-01']);
        $residence->allocationKeys()->first()->values()->create(['lot_id' => $lot->id, 'value' => 1000]);

        return compact('user', 'organization', 'residence', 'exercise', 'account', 'category', 'lot', 'contact');
    }

    private function draftCall(array $c, int $amount, string $label = 'Entretien'): FundCall
    {
        $call = FundCall::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'title' => 'Charges', 'issue_date' => '2026-02-01', 'due_date' => '2026-02-15']);
        $call->lines()->create(['charge_category_id' => $c['category']->id, 'label' => $label, 'distribution_method' => 'equal', 'target_type' => 'all', 'amount_cents' => $amount]);

        return $call;
    }

    private function payment(array $c, int $amount, string $date = '2026-02-10'): Payment
    {
        return Payment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'payer_contact_id' => $c['contact']->id, 'payment_date' => $date, 'amount_cents' => $amount, 'method' => 'bank_transfer', 'financial_account_id' => $c['account']->id]);
    }

    private function schedule(array $c, string $frequency, ?int $customMonths = null): FundCallSchedule
    {
        return FundCallSchedule::create([
            'organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => 'Boundary',
            'template' => ['title' => 'Scheduled', 'lines' => [['charge_category_id' => $c['category']->id, 'label' => 'Scheduled', 'distribution_method' => 'equal', 'target_type' => 'all', 'amount' => '10.00']]],
            'frequency' => $frequency, 'custom_interval_months' => $customMonths, 'starts_on' => '2026-01-31', 'generation_day' => 31,
            'due_offset_days' => 15, 'next_generation_on' => '2026-01-31', 'created_by' => $c['user']->id,
        ]);
    }
}
