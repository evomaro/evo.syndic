<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ChargeCategory;
use App\Models\Contact;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\FundCallSchedule;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Residence;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FundCallWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EssentialExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_existing_organizations_default_to_pro_and_new_mode_is_shared(): void
    {
        $organization = Organization::create(['name' => 'Pro', 'code' => 'PRO', 'type' => 'professional_syndic']);

        $this->assertSame('pro', $organization->fresh()->experience_mode);
        $this->assertSame('essential', Organization::factory()->essential()->create()->experience_mode);
    }

    public function test_essential_recurring_schedules_default_to_automatic_validation(): void
    {
        $context = $this->context('essential');
        $schedule = FundCallSchedule::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'name' => 'Mensuelle',
            'template' => ['title' => 'Mensuelle', 'lines' => []],
            'frequency' => 'monthly',
            'starts_on' => '2026-09-01',
            'generation_day' => 1,
            'due_offset_days' => 15,
            'next_generation_on' => '2026-09-01',
            'created_by' => $context['user']->id,
        ]);

        $this->assertTrue($schedule->auto_validate);
    }

    public function test_essential_routes_are_server_guarded_while_pro_routes_are_unchanged(): void
    {
        $essential = $this->context('essential');
        $this->actingAs($essential['user'])->get(route('dashboard'))->assertRedirect(route('essential.dashboard'));
        $this->actingAs($essential['user'])->get(route('essential.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Essential/Dashboard')->where('tenant.organization.experience_mode', 'essential')->has('auth.capabilities'));
        $this->actingAs($essential['user'])->get(route('accounting.index'))->assertForbidden();

        $pro = $this->context('pro');
        $this->actingAs($pro['user'])->get(route('accounting.index'))->assertOk();
    }

    public function test_dashboard_range_aggregates_charges_and_uses_historical_closing_balances(): void
    {
        $context = $this->context('essential');
        [$february] = $this->charges($context);
        $this->actingAs($context['user'])->post(route('essential.cotisations.generate'), [
            'residence_id' => $context['residence']->id,
            'period' => '2026-03',
            'amount' => '100.00',
            'distribution_method' => 'equal',
        ])->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('essential.payments.store'), [
            'lot_charge_id' => $february->id,
            'financial_exercise_id' => $context['exercise']->id,
            'financial_account_id' => $context['bank']->id,
            'payment_date' => '2026-02-10',
            'amount' => '50.00',
            'method' => 'bank_transfer',
            'idempotency_key' => 'dashboard-range-payment',
        ])->assertSessionHasNoErrors();

        $this->actingAs($context['user'])->get(route('essential.dashboard', [
            'from_period' => '2026-02',
            'to_period' => '2026-03',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Essential/Dashboard')
            ->where('range.from.value', '2026-02')
            ->where('range.to.value', '2026-03')
            ->where('range.normalized', false)
            ->where('summary.expected_cents', 20000)
            ->where('summary.collected_cents', 5000)
            ->where('summary.remaining_cents', 15000)
            ->where('summary.bank_cents', 105000)
            ->where('summary.cash_cents', 0)
            ->where('summary.balance_as_of', '2026-03-31'));

        $this->actingAs($context['user'])->get(route('essential.dashboard', [
            'from_period' => '2026-03',
            'to_period' => '2026-02',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('range.from.value', '2026-02')
            ->where('range.to.value', '2026-03')
            ->where('range.normalized', true)
            ->where('summary.expected_cents', 20000));
    }

    public function test_dashboard_range_defaults_to_current_month_and_accepts_legacy_period_links(): void
    {
        $context = $this->context('essential');
        $this->charges($context);
        $current = now()->format('Y-m');

        $this->actingAs($context['user'])->get(route('essential.dashboard'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('range.from.value', $current)
                ->where('range.to.value', $current));
        $this->actingAs($context['user'])->get(route('essential.dashboard', ['period' => '2026-02']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('range.from.value', '2026-02')
                ->where('range.to.value', '2026-02')
                ->where('summary.expected_cents', 10000));
    }

    public function test_only_authorized_organization_manager_can_switch_mode(): void
    {
        $context = $this->context('essential');
        $manager = User::factory()->create();
        $context['organization']->users()->attach($manager, ['role' => 'manager', 'all_residences' => true]);
        $manager->update(['current_organization_id' => $context['organization']->id, 'current_residence_id' => $context['residence']->id]);

        $this->actingAs($manager)->patch(route('essential.experience.update'), ['experience_mode' => 'pro'])->assertForbidden();
        $this->actingAs($context['user'])->patch(route('essential.experience.update'), ['experience_mode' => 'pro'])->assertRedirect(route('dashboard'));
        $this->assertSame('pro', $context['organization']->fresh()->experience_mode);
    }

    public function test_cotisation_filters_and_statuses_use_allocated_amounts(): void
    {
        $context = $this->context('essential');
        [$first, $second] = $this->charges($context);
        $payment = Payment::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'received_from' => 'Test', 'payment_date' => '2026-02-10', 'amount_cents' => 2500, 'method' => 'cash', 'financial_account_id' => $context['cash']->id]);
        $payment->allocations()->create(['lot_charge_id' => $first->id, 'lot_id' => $first->lot_id, 'amount_cents' => 2500, 'allocation_order' => 1, 'allocated_on' => '2026-02-10', 'allocated_by' => $context['user']->id]);

        $this->actingAs($context['user'])->get(route('essential.cotisations', ['period' => '2026-02', 'building_id' => $first->lot->building_id, 'status' => 'partial']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Essential/Cotisations')->has('cotisations.data', 1)->where('cotisations.data.0.id', $first->id)->where('cotisations.data.0.status', 'partial')->where('cotisations.data.0.paid_cents', 2500)->where('cotisations.data.0.remaining_cents', 2500));
        $this->actingAs($context['user'])->get(route('essential.cotisations', ['period' => '2026-02', 'status' => 'unpaid']))
            ->assertInertia(fn (Assert $page) => $page->has('cotisations.data', 1)->where('cotisations.data.0.id', $second->id)->where('cotisations.data.0.status', 'unpaid'));
    }

    public function test_transfer_is_balanced_idempotent_and_does_not_change_operational_totals(): void
    {
        $context = $this->context('essential');
        $payload = ['residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'source_account_id' => $context['bank']->id, 'destination_account_id' => $context['cash']->id, 'transferred_on' => '2026-02-10', 'amount' => '250.00', 'idempotency_key' => 'transfer-1'];

        $this->actingAs($context['user'])->post(route('essential.transfers.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('essential.transfers.store'), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_transfers', 1);
        $this->assertDatabaseCount('financial_account_movements', 2);
        $this->assertSame(75000, $context['bank']->fresh()->current_balance_cents);
        $this->assertSame(25000, $context['cash']->fresh()->current_balance_cents);
        $this->assertDatabaseHas('financial_account_movements', ['financial_account_id' => $context['bank']->id, 'direction' => 'debit', 'operational_kind' => 'account_transfer', 'amount_cents' => 25000]);
        $this->assertDatabaseHas('financial_account_movements', ['financial_account_id' => $context['cash']->id, 'direction' => 'credit', 'operational_kind' => 'account_transfer', 'amount_cents' => 25000]);
        $this->assertSame(0, Payment::query()->sum('amount_cents'));
    }

    public function test_inline_full_and_partial_payments_are_atomic_and_idempotent(): void
    {
        $context = $this->context('essential');
        [$first, $second] = $this->charges($context);
        $full = ['lot_charge_id' => $first->id, 'financial_exercise_id' => $context['exercise']->id, 'financial_account_id' => $context['bank']->id, 'payment_date' => '2026-02-10', 'amount' => '50.00', 'method' => 'bank_transfer', 'idempotency_key' => 'essential-payment-full'];

        $this->actingAs($context['user'])->post(route('essential.payments.store'), $full)->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('essential.payments.store'), $full)->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('essential.payments.store'), [...$full, 'lot_charge_id' => $second->id, 'financial_account_id' => $context['cash']->id, 'amount' => '10.00', 'method' => 'cash', 'idempotency_key' => 'essential-payment-partial'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseCount('financial_account_movements', 2);
        $this->assertSame('paid', $first->fresh()->status);
        $this->assertSame('partial', $second->fresh()->status);
        $this->assertSame(105000, $context['bank']->fresh()->current_balance_cents);
        $this->assertSame(1000, $context['cash']->fresh()->current_balance_cents);
    }

    public function test_ownerless_lot_shows_assignment_action_and_payment_returns_inline_validation_error(): void
    {
        $context = $this->context('essential');
        [$charge] = $this->charges($context);
        $charge->lot->ownerships()->delete();
        $charge->update(['billed_contact_id' => null]);

        $this->actingAs($context['user'])->get(route('essential.cotisations', ['period' => '2026-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManageLots', true)
                ->where('cotisations.data.0.can_record_payment', false)
                ->where('cotisations.data.0.resident', null));

        $this->actingAs($context['user'])->postJson(route('essential.payments.preview'), [
            'residence_id' => $context['residence']->id,
            'lot_id' => $charge->lot_id,
            'allocation_mode' => 'fifo',
            'amount' => '50.00',
        ])->assertOk();

        $this->actingAs($context['user'])->post(route('essential.payments.store'), [
            'lot_charge_id' => $charge->id,
            'lot_id' => $charge->lot_id,
            'allocation_mode' => 'fifo',
            'financial_exercise_id' => $context['exercise']->id,
            'financial_account_id' => $context['bank']->id,
            'payment_date' => '2026-02-10',
            'amount' => '50.00',
            'method' => 'bank_transfer',
            'idempotency_key' => 'ownerless-payment',
        ])->assertSessionHasErrors([
            'payer' => 'Aucun contact de facturation n’est défini pour ce lot.',
        ]);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('financial_account_movements', 0);
    }

    public function test_simplified_expense_creates_invoice_and_cash_movement_once(): void
    {
        $context = $this->context('essential');
        $category = ExpenseCategory::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'name' => 'Nettoyage', 'code' => 'cleaning']);
        $supplier = Supplier::create(['organization_id' => $context['organization']->id, 'legal_name' => 'Atlas Services', 'active' => true, 'preferred_language' => 'fr']);
        $context['cash']->update(['opening_balance_cents' => 50000]);
        $payload = ['residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $category->id, 'supplier_id' => $supplier->id, 'financial_account_id' => $context['cash']->id, 'date' => '2026-02-12', 'description' => 'Nettoyage mensuel', 'amount' => '120.00', 'method' => 'cash', 'receipt' => UploadedFile::fake()->create('recu.pdf', 20, 'application/pdf'), 'idempotency_key' => 'essential-expense-1'];

        $this->actingAs($context['user'])->post(route('essential.expenses.store'), $payload)->assertSessionHasNoErrors();
        $payload['receipt'] = UploadedFile::fake()->create('recu.pdf', 20, 'application/pdf');
        $this->actingAs($context['user'])->post(route('essential.expenses.store'), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('supplier_invoices', 1);
        $this->assertDatabaseHas('supplier_invoices', ['organization_id' => $context['organization']->id, 'status' => 'paid', 'total_cents' => 12000]);
        $this->assertDatabaseCount('supplier_settlements', 1);
        $this->assertDatabaseHas('financial_account_movements', ['financial_account_id' => $context['cash']->id, 'direction' => 'debit', 'operational_kind' => 'supplier_settlement', 'amount_cents' => 12000]);
        $this->assertSame(38000, $context['cash']->fresh()->current_balance_cents);
    }

    public function test_cross_tenant_payment_and_expense_identifiers_are_rejected_without_orphans(): void
    {
        $context = $this->context('essential');
        $other = $this->context('essential');
        [$foreignCharge] = $this->charges($other);
        $foreignCategory = ExpenseCategory::create(['organization_id' => $other['organization']->id, 'residence_id' => $other['residence']->id, 'name' => 'Foreign', 'code' => 'foreign']);
        $supplier = Supplier::create(['organization_id' => $context['organization']->id, 'legal_name' => 'Local', 'active' => true, 'preferred_language' => 'fr']);

        $this->actingAs($context['user'])->post(route('essential.payments.store'), ['lot_charge_id' => $foreignCharge->id, 'financial_exercise_id' => $context['exercise']->id, 'financial_account_id' => $context['bank']->id, 'payment_date' => '2026-02-10', 'amount' => '10.00', 'method' => 'cash', 'idempotency_key' => 'foreign-payment'])->assertNotFound();
        $this->actingAs($context['user'])->post(route('essential.expenses.store'), ['residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $foreignCategory->id, 'supplier_id' => $supplier->id, 'financial_account_id' => $context['cash']->id, 'date' => '2026-02-12', 'description' => 'Invalid', 'amount' => '10.00', 'method' => 'cash', 'receipt' => UploadedFile::fake()->create('recu.pdf', 20, 'application/pdf'), 'idempotency_key' => 'foreign-expense'])->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('supplier_invoices', 0);
        $this->assertDatabaseCount('supplier_settlements', 0);
        $this->assertDatabaseCount('financial_account_movements', 0);
    }

    public function test_failed_and_cross_tenant_transfers_leave_balances_unchanged(): void
    {
        $context = $this->context('essential');
        $other = $this->context('essential');
        $base = ['residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'source_account_id' => $context['bank']->id, 'transferred_on' => '2026-02-10', 'amount' => '2000.00', 'idempotency_key' => 'failed'];

        $this->actingAs($context['user'])->post(route('essential.transfers.store'), $base + ['destination_account_id' => $context['cash']->id])->assertSessionHasErrors('amount');
        $this->actingAs($context['user'])->post(route('essential.transfers.store'), [...$base, 'amount' => '10.00', 'destination_account_id' => $other['cash']->id, 'idempotency_key' => 'foreign'])->assertSessionHasErrors('destination_account_id');
        $this->assertDatabaseCount('financial_transfers', 0);
        $this->assertDatabaseCount('financial_account_movements', 0);
        $this->assertSame(100000, $context['bank']->fresh()->current_balance_cents);
    }

    public function test_reports_are_tenant_scoped_and_csv_formula_cells_are_escaped(): void
    {
        $context = $this->context('essential');
        [$charge] = $this->charges($context, '=CMD()');
        $other = $this->context('essential');
        $this->charges($other, 'Other tenant');

        $this->actingAs($context['user'])->get(route('essential.reports', ['type' => 'unpaid', 'period' => '2026-02']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Essential/Reports')->has('rows', 2)->where('totalCents', 10000));
        $csv = $this->actingAs($context['user'])->get(route('essential.reports.export', ['type' => 'unpaid', 'period' => '2026-02']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=CMD()", $csv);
        $this->assertStringNotContainsString('Other tenant', $csv);
        $this->assertStringContainsString('100.00', $csv);
        $this->assertSame($context['organization']->id, $charge->organization_id);
    }

    public function test_unpaid_report_defaults_to_the_residences_latest_charge_period(): void
    {
        $context = $this->context('essential');
        $this->charges($context);

        $this->actingAs($context['user'])->get(route('essential.reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Essential/Reports')
                ->where('period.value', '2026-02')
                ->where('periodHasCharges', true)
                ->has('rows', 2));
    }

    public function test_essential_user_can_preview_generate_and_safely_cancel_a_cotisation(): void
    {
        $context = $this->context('essential');
        $this->charges($context);
        $payload = [
            'residence_id' => $context['residence']->id,
            'period' => '2026-03',
            'amount' => '120.00',
            'distribution_method' => 'equal',
        ];

        $this->actingAs($context['user'])->postJson(route('essential.cotisations.preview'), $payload)
            ->assertOk()
            ->assertJsonPath('total_cents', 12000)
            ->assertJsonCount(2, 'allocations');

        $this->actingAs($context['user'])->post(route('essential.cotisations.generate'), $payload)
            ->assertRedirect(route('essential.cotisations', ['period' => '2026-03', 'residence_id' => $context['residence']->id]));
        $call = FundCall::query()->whereDate('issue_date', '2026-03-01')->firstOrFail();
        $this->assertSame('validated', $call->status);
        $this->assertSame(12000, (int) $call->charges()->sum('amount_cents'));
        $this->assertDatabaseCount('financial_account_movements', 0);

        $this->actingAs($context['user'])->post(route('essential.cotisations.cancel', $call), [
            'reason' => 'Montant saisi par erreur',
        ])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $call->fresh()->status);
        $this->assertSame(2, $call->charges()->where('status', 'cancelled')->count());
    }

    public function test_fifo_multi_month_payment_creates_one_movement_and_future_credit_is_auto_applied(): void
    {
        $context = $this->context('essential');
        [$february] = $this->charges($context);
        foreach (['2026-03', '2026-04'] as $period) {
            $this->actingAs($context['user'])->post(route('essential.cotisations.generate'), [
                'residence_id' => $context['residence']->id,
                'period' => $period,
                'amount' => '100.00',
                'distribution_method' => 'equal',
            ])->assertSessionHasNoErrors();
        }
        $march = Lot::query()->findOrFail($february->lot_id)->charges()->whereDate('issue_date', '2026-03-01')->firstOrFail();
        $april = Lot::query()->findOrFail($february->lot_id)->charges()->whereDate('issue_date', '2026-04-01')->firstOrFail();

        $preview = $this->actingAs($context['user'])->postJson(route('essential.payments.preview'), [
            'residence_id' => $context['residence']->id,
            'lot_id' => $february->lot_id,
            'allocation_mode' => 'fifo',
            'amount' => '175.00',
        ])->assertOk();
        $preview->assertJsonPath('allocated_cents', 15000)->assertJsonPath('credit_cents', 2500)->assertJsonCount(3, 'allocations');

        $this->actingAs($context['user'])->post(route('essential.payments.store'), [
            'lot_charge_id' => $february->id,
            'lot_id' => $february->lot_id,
            'allocation_mode' => 'fifo',
            'financial_exercise_id' => $context['exercise']->id,
            'financial_account_id' => $context['bank']->id,
            'payment_date' => '2026-04-10',
            'amount' => '175.00',
            'method' => 'bank_transfer',
            'idempotency_key' => 'multi-month-fifo',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('idempotency_key', 'multi-month-fifo')->firstOrFail();
        $this->assertSame(1, $payment->movements()->where('operational_kind', 'payment_receipt')->count());
        $this->assertSame(3, $payment->allocations()->count());
        $this->assertSame(2500, $payment->credit_cents);
        $this->assertSame('paid', $february->fresh()->status);
        $this->assertSame('paid', $march->fresh()->status);
        $this->assertSame('paid', $april->fresh()->status);
        $this->actingAs($context['user'])->post(
            route('essential.cotisations.cancel', $february->fund_call_id),
            ['reason' => 'Tentative après encaissement'],
        )->assertSessionHasErrors('status');

        $this->actingAs($context['user'])->post(route('essential.cotisations.generate'), [
            'residence_id' => $context['residence']->id,
            'period' => '2026-05',
            'amount' => '100.00',
            'distribution_method' => 'equal',
        ])->assertSessionHasNoErrors();
        $may = Lot::query()->findOrFail($february->lot_id)->charges()->whereDate('issue_date', '2026-05-01')->firstOrFail();
        $this->assertSame('partial', $may->status);
        $this->assertSame(2500, $may->allocated_cents);
        $this->assertSame(0, $payment->fresh()->credit_cents);
        $this->assertSame(1, $payment->movements()->where('operational_kind', 'payment_receipt')->count());
    }

    public function test_month_range_preview_only_includes_generated_outstanding_months(): void
    {
        $context = $this->context('essential');
        [$february] = $this->charges($context);
        $this->actingAs($context['user'])->post(route('essential.cotisations.generate'), [
            'residence_id' => $context['residence']->id,
            'period' => '2026-03',
            'amount' => '100.00',
            'distribution_method' => 'equal',
        ])->assertSessionHasNoErrors();

        $this->actingAs($context['user'])->postJson(route('essential.payments.preview'), [
            'residence_id' => $context['residence']->id,
            'lot_id' => $february->lot_id,
            'allocation_mode' => 'range',
            'from_period' => '2026-02',
            'to_period' => '2026-03',
        ])->assertOk()
            ->assertJsonPath('total_cents', 10000)
            ->assertJsonPath('credit_cents', 0)
            ->assertJsonCount(2, 'allocations');

        $this->actingAs($context['user'])->post(route('essential.payments.store'), [
            'residence_id' => $context['residence']->id,
            'lot_charge_id' => $february->id,
            'lot_id' => $february->lot_id,
            'allocation_mode' => 'range',
            'from_period' => '2026-02',
            'to_period' => '2026-03',
            'financial_exercise_id' => $context['exercise']->id,
            'financial_account_id' => $context['bank']->id,
            'payment_date' => '2026-03-10',
            'amount' => '100.00',
            'method' => 'bank_transfer',
            'idempotency_key' => 'multi-month-range',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('idempotency_key', 'multi-month-range')->firstOrFail();
        $march = Lot::query()->findOrFail($february->lot_id)->charges()->whereDate('issue_date', '2026-03-01')->firstOrFail();
        $this->assertSame(1, $payment->movements()->where('operational_kind', 'payment_receipt')->count());
        $this->assertSame(2, $payment->allocations()->count());
        $this->assertSame('paid', $february->fresh()->status);
        $this->assertSame('paid', $march->fresh()->status);
    }

    private function context(string $mode): array
    {
        Storage::fake('local');
        $user = User::factory()->create(['preferred_language' => 'fr']);
        $organization = Organization::factory()->create(['experience_mode' => $mode]);
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $organization->users()->attach($user, ['role' => 'owner', 'all_residences' => true]);
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'status' => 'open']);
        $bank = FinancialAccount::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Banque', 'type' => 'bank', 'opening_balance_cents' => 100000]);
        $cash = FinancialAccount::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Caisse', 'type' => 'cash', 'opening_balance_cents' => 0]);

        return compact('user', 'organization', 'residence', 'exercise', 'bank', 'cash');
    }

    private function charges(array $context, string $firstContactName = 'Résident A'): array
    {
        $category = ChargeCategory::factory()->create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id]);
        $call = FundCall::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'title' => 'Cotisations février', 'issue_date' => '2026-02-01', 'due_date' => '2026-02-15']);
        $line = $call->lines()->create(['charge_category_id' => $category->id, 'label' => 'Mensuelle', 'distribution_method' => 'equal', 'target_type' => 'all', 'amount_cents' => 10000]);
        foreach (['A', 'B'] as $index => $name) {
            $building = Building::factory()->for($context['residence'])->create(['name' => "Bâtiment $name"]);
            $lot = Lot::factory()->for($context['residence'])->create(['building_id' => $building->id, 'reference' => "APT-$name"]);
            $contact = Contact::factory()->for($context['organization'])->create(['first_name' => $index === 0 ? $firstContactName : 'Résident B', 'last_name' => '']);
            $lot->ownerships()->create(['contact_id' => $contact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2024-01-01']);
        }
        $validated = app(FundCallWorkflow::class)->validate($call, $context['user']);

        return $validated->charges()->with('lot')->orderBy('lot_id')->get()->all();
    }
}
