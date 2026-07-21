<?php

namespace Tests\Feature;

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
use App\Services\FundCallScheduleService;
use App\Services\FundCallWorkflow;
use App\Services\PaymentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseTwoFinanceTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'owner'): array
    {
        $user = User::factory()->create(['preferred_language' => 'fr']);
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => true]);
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'status' => 'open']);
        $account = FinancialAccount::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'code' => 'bank', 'default_slot' => 1]);
        $category = ChargeCategory::factory()->create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'code' => 'maintenance']);
        $key = $residence->allocationKeys()->first();
        $lots = collect();
        foreach ([333.3333, 333.3333, 333.3334] as $i => $value) {
            $lot = Lot::factory()->for($residence)->create(['reference' => 'A-0'.($i + 1), 'lot_number' => (string) ($i + 1)]);
            $contact = Contact::factory()->for($organization)->create(['preferred_language' => $i === 1 ? 'ar' : 'fr']);
            $lot->ownerships()->create(['contact_id' => $contact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2024-01-01']);
            $key->values()->create(['lot_id' => $lot->id, 'value' => $value]);
            $lot->setRelation('ownerContact', $contact);
            $lots->push($lot);
        }

        return compact('user', 'organization', 'residence', 'exercise', 'account', 'category', 'key', 'lots');
    }

    private function draftCall(array $c, string $method = 'allocation_key', int $amount = 10001): FundCall
    {
        $call = FundCall::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'title' => 'Charges mensuelles', 'issue_date' => '2026-02-01', 'due_date' => '2026-02-15']);
        $call->lines()->create(['charge_category_id' => $c['category']->id, 'label' => 'Entretien', 'distribution_method' => $method, 'allocation_key_id' => $method === 'allocation_key' ? $c['key']->id : null, 'target_type' => 'all', 'amount_cents' => $amount]);

        return $call;
    }

    public function test_exercises_cannot_overlap_and_only_one_can_be_open(): void
    {
        $c = $this->context();
        $this->actingAs($c['user'])->post(route('financial-exercises.store'), ['name' => 'Overlap', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31'])->assertSessionHasErrors('starts_on');
        $draft = FinancialExercise::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $this->actingAs($c['user'])->post(route('financial-exercises.transition', $draft), ['action' => 'open'])->assertSessionHasErrors('action');
    }

    public function test_distribution_is_deterministic_and_preserves_exact_total(): void
    {
        $c = $this->context();
        $call = $this->draftCall($c);
        $validated = app(FundCallWorkflow::class)->validate($call, $c['user']);
        $this->assertSame(10001, $validated->charges->sum('amount_cents'));
        $this->assertSame([3334, 3333, 3334], $validated->charges->sortBy('lot_id')->pluck('amount_cents')->all());
        $this->assertMatchesRegularExpression('/^AF-2026-\d{4}$/', $validated->number);
        $this->assertDatabaseCount('lot_charges', 3);
    }

    public function test_equal_fixed_manual_and_selected_lot_distributions(): void
    {
        $c = $this->context();
        $equal = $this->draftCall($c, 'equal', 10000);
        app(FundCallWorkflow::class)->validate($equal, $c['user']);
        $this->assertSame([3334, 3333, 3333], $equal->charges()->orderBy('lot_id')->pluck('amount_cents')->all());
        $fixed = $this->draftCall($c, 'fixed', 0);
        $fixed->lines()->update(['fixed_amount_cents' => 2500, 'target_type' => 'lots', 'target_ids' => [$c['lots'][0]->id, $c['lots'][2]->id]]);
        app(FundCallWorkflow::class)->validate($fixed, $c['user']);
        $this->assertSame(5000, $fixed->fresh()->total_cents);
        $manual = $this->draftCall($c, 'manual', 7000);
        $manual->lines()->update(['target_type' => 'lots', 'target_ids' => [$c['lots'][0]->id, $c['lots'][1]->id], 'manual_allocations' => [['lot_id' => $c['lots'][0]->id, 'amount' => '30.00'], ['lot_id' => $c['lots'][1]->id, 'amount' => '40.00']]]);
        app(FundCallWorkflow::class)->validate($manual, $c['user']);
        $this->assertSame([3000, 4000], $manual->charges()->orderBy('lot_id')->pluck('amount_cents')->all());
    }

    public function test_validated_call_is_immutable_and_cancellation_is_controlled(): void
    {
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c), $c['user']);
        $this->actingAs($c['user'])->put(route('fund-calls.update', $call), [])->assertStatus(409);
        app(FundCallWorkflow::class)->cancel($call, $c['user'], 'Erreur de période');
        $this->assertSame('cancelled', $call->fresh()->status);
        $this->assertSame(3, $call->charges()->where('status', 'cancelled')->count());
    }

    public function test_fifo_partial_multi_lot_overpayment_credit_and_reconciliation(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $first = app(FundCallWorkflow::class)->validate($this->draftCall($c, 'equal', 9000), $c['user']);
        $second = $this->draftCall($c, 'equal', 6000);
        $second->update(['issue_date' => '2026-03-01', 'due_date' => '2026-03-15']);
        app(FundCallWorkflow::class)->validate($second, $c['user']);
        $payment = Payment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'payer_contact_id' => $c['lots'][0]->ownerContact->id, 'payment_date' => '2026-03-20', 'amount_cents' => 20000, 'method' => 'bank_transfer', 'financial_account_id' => $c['account']->id, 'idempotency_key' => 'fifo-1']);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user']);
        $this->assertSame(15000, $payment->allocated_cents);
        $this->assertSame(5000, $payment->credit_cents);
        $this->assertSame($first->charges()->count() + $second->charges()->count(), $payment->allocations->count());
        $this->assertSame(20000, $c['account']->fresh()->current_balance_cents);
        $this->assertSame(20000, FinancialAccountMovement::where('financial_account_id', $c['account']->id)->sum('amount_cents'));
    }

    public function test_credit_can_be_allocated_later_without_fake_charge(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 'equal', 3000), $c['user']);
        $payment = Payment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'payer_contact_id' => $c['lots'][0]->ownerContact->id, 'payment_date' => '2026-02-10', 'amount_cents' => 5000, 'method' => 'cash', 'financial_account_id' => $c['account']->id]);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user'], 'selected_lots', []);
        $this->assertSame(5000, $payment->credit_cents);
        app(PaymentWorkflow::class)->allocateCredit($payment, $c['user'], [['lot_charge_id' => $call->charges()->first()->id, 'amount_cents' => 1000]]);
        $this->assertSame(4000, $payment->fresh()->credit_cents);
        $this->assertDatabaseCount('lot_charges', 3);
    }

    public function test_reversal_restores_debt_and_creates_compensating_movement(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 'equal', 3000), $c['user']);
        $payment = Payment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'received_from' => 'M. Test', 'payment_date' => '2026-02-10', 'amount_cents' => 3000, 'method' => 'cash', 'financial_account_id' => $c['account']->id]);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user']);
        app(PaymentWorkflow::class)->reverse($payment, $c['user'], 'Chèque rejeté');
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame(3000, $call->charges->fresh()->sum('outstanding_cents'));
        $this->assertSame(0, $c['account']->fresh()->current_balance_cents);
        $this->assertDatabaseHas('financial_documents', ['subject_id' => $payment->id, 'status' => 'reversed']);
        $this->assertDatabaseCount('financial_account_movements', 2);
    }

    public function test_receipt_pdf_checksum_qr_verification_and_access_control(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c, 'equal', 3000), $c['user']);
        $payment = Payment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'payer_contact_id' => $c['lots'][0]->ownerContact->id, 'payment_date' => '2026-02-10', 'amount_cents' => 3000, 'method' => 'cash', 'financial_account_id' => $c['account']->id]);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user']);
        $document = $payment->documents()->first();
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame($document->checksum, hash('sha256', Storage::disk('local')->get($document->path)));
        $token = Crypt::decryptString($document->verification_token_encrypted);
        $this->get(route('receipts.verify', $token))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Finance/ReceiptVerification')->where('receipt.integrity', true)->where('receipt.status', 'valid'));
        $outsider = User::factory()->create();
        $c['organization']->users()->attach($outsider, ['role' => 'maintenance_agent', 'all_residences' => true]);
        $outsider->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        $this->actingAs($outsider)->get(route('receipts.download', $document))->assertForbidden();
    }

    public function test_schedule_dry_run_monthly_generation_and_duplicate_prevention(): void
    {
        $c = $this->context();
        $schedule = FundCallSchedule::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => 'Mensuel', 'template' => ['title' => 'Mensuel', 'lines' => [['charge_category_id' => $c['category']->id, 'label' => 'Entretien', 'distribution_method' => 'equal', 'target_type' => 'all', 'amount' => '90.00']]], 'frequency' => 'monthly', 'starts_on' => '2026-01-01', 'generation_day' => 1, 'due_offset_days' => 15, 'next_generation_on' => '2026-01-01', 'created_by' => $c['user']->id]);
        $service = app(FundCallScheduleService::class);
        $this->assertSame('dry-run', $service->generate($schedule, CarbonImmutable::parse('2026-01-01'), $c['user'], false)['status']);
        $this->assertDatabaseCount('fund_calls', 0);
        $this->assertSame('generated', $service->generate($schedule, CarbonImmutable::parse('2026-01-01'), $c['user'], true)['status']);
        $this->assertSame('not_due', $service->generate($schedule->fresh(), CarbonImmutable::parse('2026-01-01'), $c['user'], true)['status']);
        $this->assertSame('2026-02-01', $schedule->fresh()->next_generation_on->toDateString());
    }

    public function test_tenant_isolation_permissions_search_and_occupant_rejection(): void
    {
        $c = $this->context();
        $other = $this->context();
        $foreignCall = $this->draftCall($other);
        $this->actingAs($c['user'])->get(route('fund-calls.show', $foreignCall))->assertNotFound();
        $maintenance = User::factory()->create();
        $c['organization']->users()->attach($maintenance, ['role' => 'maintenance_agent', 'all_residences' => true]);
        $maintenance->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        $this->actingAs($maintenance)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($maintenance)->get(route('owner-finance.index'))->assertForbidden();
        $response = $this->actingAs($c['user'])->getJson(route('finance.search', ['q' => 'A-0']))->assertOk();
        $response->assertJsonFragment(['id' => $c['lots'][0]->id])->assertJsonMissing(['id' => $other['lots'][0]->id]);
    }

    public function test_dashboard_handles_zero_denominator(): void
    {
        $c = $this->context();
        $this->actingAs($c['user'])->get(route('finance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Finance/Overview')->where('metrics.collection_rate', 0));
    }

    public function test_closed_exercise_blocks_new_validation_and_drafts_block_closing(): void
    {
        $c = $this->context();
        $call = $this->draftCall($c);
        $this->actingAs($c['user'])->post(route('financial-exercises.transition', $c['exercise']), ['action' => 'close'])->assertSessionHasErrors('action');
        $call->delete();
        $this->actingAs($c['user'])->post(route('financial-exercises.transition', $c['exercise']), ['action' => 'close'])->assertRedirect();
        $this->assertSame('closed', $c['exercise']->fresh()->status);
        $call = $this->draftCall($c);
        $this->expectException(ValidationException::class);
        app(FundCallWorkflow::class)->validate($call, $c['user']);
    }

    public function test_payment_submission_is_idempotent_and_validated_payment_cannot_be_edited(): void
    {
        Storage::fake('local');
        $c = $this->context();
        app(FundCallWorkflow::class)->validate($this->draftCall($c), $c['user']);
        $payload = ['financial_exercise_id' => $c['exercise']->id, 'received_from' => 'Test idempotent', 'payment_date' => '2026-02-02', 'amount' => '10.00', 'method' => 'cash', 'financial_account_id' => $c['account']->id, 'allocation_mode' => 'fifo', 'validate_now' => true, 'idempotency_key' => 'same-request'];
        $this->actingAs($c['user'])->post(route('payments.store'), $payload)->assertRedirect();
        $this->actingAs($c['user'])->post(route('payments.store'), $payload)->assertRedirect();
        $this->assertSame(1, Payment::where('idempotency_key', 'same-request')->count());
        $payment = Payment::where('idempotency_key', 'same-request')->first();
        $this->actingAs($c['user'])->put(route('payments.update', $payment), $payload)->assertStatus(409);
    }

    public function test_arabic_receipt_and_financial_exports_are_generated(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $call = app(FundCallWorkflow::class)->validate($this->draftCall($c, 'equal', 3000), $c['user']);
        $payment = Payment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'payer_contact_id' => $c['lots'][1]->ownerContact->id, 'payment_date' => '2026-02-10', 'amount_cents' => 1000, 'method' => 'cash', 'financial_account_id' => $c['account']->id]);
        $payment = app(PaymentWorkflow::class)->validate($payment, $c['user']);
        $document = $payment->documents()->first();
        $this->assertSame('ar', $document->locale);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($document->path));
        $this->actingAs($c['user'])->get(route('fund-calls.pdf', $call))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($c['user'])->get(route('finance.statements.pdf', ['lot' => $c['lots'][0]->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($c['user'])->get(route('finance.statements.csv', ['lot' => $c['lots'][0]->id]))->assertOk();
        app()->setLocale('ar');
        $this->assertSame('تم تسجيل الدفعة.', __('Paiement enregistré.'));
    }

    public function test_operational_permissions_are_enforced_on_direct_routes(): void
    {
        $c = $this->context('manager');
        $call = $this->draftCall($c);
        $this->actingAs($c['user'])->get(route('finance.index'))->assertOk();
        $this->actingAs($c['user'])->post(route('fund-calls.validate', $call))->assertForbidden();
        $this->actingAs($c['user'])->get(route('financial-accounts.index'))->assertForbidden();
        $this->actingAs($c['user'])->get(route('finance.outstanding'))->assertOk();
    }
}
