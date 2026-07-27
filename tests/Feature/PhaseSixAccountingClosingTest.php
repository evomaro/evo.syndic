<?php

namespace Tests\Feature;

use App\Models\AccountingBook;
use App\Models\AccountingClosingPackage;
use App\Models\AccountingFramework;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\FinancialExercise;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Services\AccountingClosingConfigurationService;
use App\Services\AccountingClosingReadinessService;
use App\Services\AccountingClosingWorkflowService;
use App\Services\AccountingConfigurationService;
use App\Services\AccountingPostingService;
use App\Services\FinancialExerciseLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseSixAccountingClosingTest extends TestCase
{
    use RefreshDatabase;

    private User $preparer;

    private User $reviewer;

    private User $approver;

    private User $executor;

    private Organization $organization;

    private Residence $residence;

    private AccountingBook $book;

    private FinancialExercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparer = User::factory()->create();
        $this->reviewer = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->executor = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->residence = Residence::factory()->for($this->organization)->create();
        foreach ([$this->preparer, $this->reviewer, $this->approver, $this->executor] as $user) {
            $this->organization->users()->attach($user, ['role' => 'owner', 'all_residences' => true]);
            $user->update([
                'current_organization_id' => $this->organization->id,
                'current_residence_id' => $this->residence->id,
            ]);
        }
        $framework = AccountingFramework::where('stable_code', 'MA-SYNDIC-2-23-700')->firstOrFail();
        $this->book = app(AccountingConfigurationService::class)->adopt(
            $this->organization->id,
            $this->residence->id,
            $framework,
            'full',
            '2026-01-01',
            $this->preparer,
        );
        $this->exercise = FinancialExercise::factory()->create([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => 'open',
        ]);
        app(AccountingConfigurationService::class)->configureExercise($this->exercise, $this->book, $this->preparer);
        $this->exercise->refresh();
    }

    public function test_readiness_is_technically_available_but_professional_closing_is_blocked(): void
    {
        $result = app(AccountingClosingReadinessService::class)->evaluate($this->book, $this->exercise);

        $this->assertTrue($result['technical_ready'], json_encode(
            collect($result['checks'])->where('blocks_preparation', true)->where('result', '!=', 'pass')->values()->all()
        ));
        $this->assertFalse($result['approval_ready']);
        $this->assertFalse($result['execution_ready']);
        $this->assertSame('unavailable', collect($result['checks'])->firstWhere('code', 'closing_configuration')['result']);
        $this->assertSame(0, $result['snapshot']['snapshot_entry_id']);
    }

    public function test_preparation_is_idempotent_durable_and_separates_maker_from_reviewer(): void
    {
        $service = app(AccountingClosingWorkflowService::class);
        $first = $service->prepare($this->book, $this->exercise, $this->preparer);
        $again = $service->prepare($this->book, $this->exercise, $this->preparer);

        $this->assertSame($first->id, $again->id);
        $this->assertSame('ready_for_review', $first->state);
        $this->assertSame(1, DB::table('accounting_closing_packages')->count());
        $this->assertNotNull($first->integrity_fingerprint);

        $this->expectException(ValidationException::class);
        $service->review($first, $this->preparer);
    }

    public function test_draft_entry_blocks_preparation_and_a_stale_snapshot_invalidates_review(): void
    {
        $service = app(AccountingClosingWorkflowService::class);
        $package = $service->prepare($this->book, $this->exercise, $this->preparer);
        $this->postEntry(1000, $this->preparer);

        try {
            $service->review($package, $this->reviewer);
            $this->fail('A changed ledger must invalidate the package.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('snapshot', $exception->errors());
        }
        $this->assertSame('blocked', $package->fresh()->state);

        $draft = $this->draftEntry(500);
        $readiness = app(AccountingClosingReadinessService::class)->evaluate($this->book, $this->exercise);
        $this->assertFalse($readiness['technical_ready']);
        $this->assertSame(1, collect($readiness['checks'])->firstWhere('code', 'draft_entry_count')['evidence']);
        $draft->lines()->delete();
        $draft->delete();
    }

    public function test_period_closing_is_ordered_idempotent_and_blocks_posting(): void
    {
        $service = app(AccountingClosingWorkflowService::class);
        $package = $service->prepare($this->book, $this->exercise, $this->preparer);
        $periods = $this->exercise->accountingPeriods()->orderBy('sequence')->get();

        try {
            $service->closePeriod($package, $periods[1], $this->preparer, 'Contrôle');
            $this->fail('Later period must not close first.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period', $exception->errors());
        }
        $closed = $service->closePeriod($package, $periods[0], $this->preparer, 'Contrôle mensuel');
        $again = $service->closePeriod($package, $closed, $this->preparer, 'Retry');
        $this->assertSame('locked', $again->status);
        $this->assertSame(1, DB::table('accounting_closing_period_snapshots')->count());

        $this->expectException(ValidationException::class);
        app(AccountingPostingService::class)->post($this->draftEntry(100, $periods[0]), $this->preparer);
    }

    public function test_explicit_fixture_classifications_enable_closing_and_balanced_carry_forward(): void
    {
        [$configuration, $expense, $cash, $result] = $this->approvedFixtureConfiguration();
        $posted = $this->postEntry(125000, $this->preparer, $expense, $cash);
        $this->assertSame('posted', $posted->status);

        $workflow = app(AccountingClosingWorkflowService::class);
        $initial = $workflow->prepare($this->book, $this->exercise, $this->preparer);
        $periods = $this->exercise->accountingPeriods()->orderBy('sequence')->get();
        foreach ($periods->slice(0, -1) as $period) {
            $workflow->closePeriod($initial, $period, $this->preparer, 'Clôture séquentielle');
        }
        $package = $workflow->prepare($this->book, $this->exercise, $this->preparer);
        $this->assertSame($initial->id, $package->supersedes_id);
        $package = $workflow->review($package, $this->reviewer);
        $package = $workflow->approve($package, $this->approver);
        $package = $workflow->executeClosing($package, $this->executor);

        $this->assertSame('closed', $package->state);
        $this->assertSame('closed', $this->exercise->fresh()->status);
        $closing = JournalEntry::findOrFail($package->closing_entry_id);
        $this->assertSame(125000, (int) $closing->lines()->sum('debit_minor'));
        $this->assertSame(125000, (int) $closing->lines()->sum('credit_minor'));
        $this->assertSame(125000, (int) $closing->lines()->where('ledger_account_id', $result->id)->sum('debit_minor'));

        $next = FinancialExercise::factory()->create([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'status' => 'draft',
        ]);
        app(AccountingConfigurationService::class)->configureExercise($next, $this->book, $this->preparer);
        app(FinancialExerciseLifecycleService::class)->transition($next, 'open', $this->preparer);
        $package = $workflow->executeCarryForward($package, $this->executor);
        $batch = DB::table('accounting_opening_batches')->where('id', $package->carry_forward_batch_id)->first();
        $this->assertSame('carry_forward_completed', $package->state);
        $this->assertSame('carry_forward', $batch->origin_type);
        $this->assertSame('posted', $batch->status);
        $this->assertSame(125000, (int) DB::table('accounting_opening_lines')->where('accounting_opening_batch_id', $batch->id)->sum('debit_minor'));
        $this->assertSame(125000, (int) DB::table('accounting_opening_lines')->where('accounting_opening_batch_id', $batch->id)->sum('credit_minor'));
        $this->assertFalse(DB::table('accounting_opening_lines')->where('accounting_opening_batch_id', $batch->id)->where('ledger_account_id', $expense->id)->exists());
        $this->assertTrue(DB::table('accounting_opening_lines')->where('accounting_opening_batch_id', $batch->id)->where('ledger_account_id', $cash->id)->exists());
    }

    public function test_opening_conflict_and_reopening_remain_safely_blocked(): void
    {
        $package = app(AccountingClosingWorkflowService::class)->prepare($this->book, $this->exercise, $this->preparer);
        $diagnostics = app(AccountingClosingWorkflowService::class)->reopeningDiagnostics($package);
        $this->assertFalse($diagnostics['executable']);
        $this->assertContains('package_not_closed', $diagnostics['issues']);

        $next = FinancialExercise::factory()->create([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'status' => 'draft',
        ]);
        app(AccountingConfigurationService::class)->configureExercise($next, $this->book, $this->preparer);
        DB::table('accounting_opening_batches')->insert([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'accounting_book_id' => $this->book->id,
            'financial_exercise_id' => $next->id,
            'accounting_journal_id' => $this->book->journals()->first()->id,
            'opening_date' => '2027-01-01',
            'reference' => 'MANUAL-CONFLICT',
            'status' => 'draft',
            'origin_type' => 'manual',
            'created_by' => $this->preparer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $readiness = app(AccountingClosingReadinessService::class)->evaluate($this->book, $this->exercise);
        $this->assertSame('blocked', collect($readiness['checks'])->firstWhere('code', 'opening_balance_conflict')['result']);
    }

    public function test_closed_exercise_without_downstream_dependencies_reopens_by_controlled_reversal(): void
    {
        [, $expense, $cash] = $this->approvedFixtureConfiguration();
        $this->postEntry(125000, $this->preparer, $expense, $cash);
        $workflow = app(AccountingClosingWorkflowService::class);
        $initial = $workflow->prepare($this->book, $this->exercise, $this->preparer);
        $periods = $this->exercise->accountingPeriods()->orderBy('sequence')->get();
        foreach ($periods->slice(0, -1) as $period) {
            $workflow->closePeriod($initial, $period, $this->preparer, 'Clôture séquentielle');
        }
        $package = $workflow->prepare($this->book, $this->exercise, $this->preparer);
        $package = $workflow->review($package, $this->reviewer);
        $package = $workflow->approve($package, $this->approver);
        $package = $workflow->executeClosing($package, $this->executor);
        $closingEntry = JournalEntry::findOrFail($package->closing_entry_id);

        $this->assertTrue($workflow->reopeningDiagnostics($package)['executable']);
        $reopened = $workflow->reopen($package, $this->preparer, 'Correction de clôture documentée');

        $this->assertSame('reopened', $reopened->state);
        $this->assertSame('open', $this->exercise->fresh()->status);
        $this->assertSame('reversed', $closingEntry->fresh()->status);
        $this->assertNotNull($closingEntry->fresh()->reversed_by_id);
        $this->assertSame('open', $periods->last()->fresh()->status);
        $this->assertTrue($periods->slice(0, -1)->every(fn ($period) => $period->fresh()->status === 'locked'));
        $this->assertDatabaseHas('accounting_closing_transitions', [
            'accounting_closing_package_id' => $package->id,
            'action' => 'reopen',
        ]);
        $this->assertDatabaseHas('accounting_activity_events', ['action' => 'fiscal_year_reopened']);
    }

    public function test_audit_commands_are_read_only_json_and_return_nonzero_for_violation(): void
    {
        app(AccountingClosingWorkflowService::class)->prepare($this->book, $this->exercise, $this->preparer);
        $before = DB::table('accounting_closing_packages')->count();
        $this->assertSame(1, Artisan::call('accounting:audit-closing-readiness', [
            '--organization' => $this->organization->id,
            '--residence' => $this->residence->id,
            '--json' => true,
        ]));
        $this->assertStringContainsString('"execution_ready": false', Artisan::output());
        $this->assertSame(0, Artisan::call('accounting:audit-closing-packages', [
            '--organization' => $this->organization->id,
            '--json' => true,
        ]));
        $this->assertSame(0, Artisan::call('accounting:audit-carry-forwards', [
            '--organization' => $this->organization->id,
            '--json' => true,
        ]));
        $this->assertSame($before, DB::table('accounting_closing_packages')->count());

        $period = $this->exercise->accountingPeriods()->first();
        $period->update(['status' => 'locked', 'locked_at' => now()->subDay()]);
        $entry = $this->postEntry(100, $this->preparer);
        DB::table('journal_entries')->where('id', $entry->id)->update(['accounting_period_id' => $period->id, 'posted_at' => now()]);
        $this->assertSame(1, Artisan::call('accounting:audit-closing-packages', [
            '--organization' => $this->organization->id,
            '--json' => true,
        ]));
        $this->assertStringContainsString('posting_after_period_close', Artisan::output());
    }

    public function test_routes_segregate_view_and_prepare_permissions_and_reject_foreign_package(): void
    {
        $manager = User::factory()->create();
        $this->organization->users()->attach($manager, ['role' => 'manager', 'all_residences' => true]);
        $manager->update([
            'current_organization_id' => $this->organization->id,
            'current_residence_id' => $this->residence->id,
        ]);
        $this->actingAs($manager)->get(route('accounting.closing.index'))->assertOk();
        $this->actingAs($manager)->post(route('accounting.closing.prepare', $this->exercise))->assertForbidden();

        $package = app(AccountingClosingWorkflowService::class)->prepare($this->book, $this->exercise, $this->preparer);
        $foreignOrg = Organization::factory()->create();
        $foreignResidence = Residence::factory()->for($foreignOrg)->create();
        $foreign = User::factory()->create();
        $foreignOrg->users()->attach($foreign, ['role' => 'owner', 'all_residences' => true]);
        $foreign->update([
            'current_organization_id' => $foreignOrg->id,
            'current_residence_id' => $foreignResidence->id,
        ]);
        $this->actingAs($foreign)
            ->post(route('accounting.closing.review', $package))
            ->assertNotFound();
    }

    public function test_closing_json_export_matches_snapshot_and_records_safe_activity(): void
    {
        $package = app(AccountingClosingWorkflowService::class)
            ->prepare($this->book, $this->exercise, $this->preparer);

        $this->actingAs($this->preparer)
            ->get(route('accounting.closing.export', [$package, 'json']))
            ->assertOk()
            ->assertJsonPath('package.id', $package->id)
            ->assertJsonPath('package.snapshot_entry_id', $package->snapshot_entry_id)
            ->assertJsonPath('package.integrity_fingerprint', $package->integrity_fingerprint)
            ->assertJsonPath('not_certified', true);

        $event = DB::table('accounting_activity_events')
            ->where('record_type', AccountingClosingPackage::class)
            ->where('record_id', $package->id)
            ->where('action', 'closing_evidence_exported')
            ->first();
        $this->assertNotNull($event);
        $evidence = json_decode($event->after_evidence, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('json', $evidence['format']);
        $this->assertSame($package->snapshot_entry_id, $evidence['snapshot_entry_id']);
    }

    private function approvedFixtureConfiguration(): array
    {
        $closing = AccountingJournal::create([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'accounting_book_id' => $this->book->id,
            'code' => 'CL',
            'label_fr' => 'Clôture',
            'label_ar' => 'الإقفال',
            'type' => 'closing',
            'active' => true,
            'effective_from' => '2026-01-01',
            'created_by' => $this->preparer->id,
            'updated_by' => $this->preparer->id,
        ]);
        $opening = AccountingJournal::create([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'accounting_book_id' => $this->book->id,
            'code' => 'OUV',
            'label_fr' => 'Ouverture',
            'label_ar' => 'الافتتاح',
            'type' => 'opening',
            'active' => true,
            'effective_from' => '2026-01-01',
            'created_by' => $this->preparer->id,
            'updated_by' => $this->preparer->id,
        ]);
        $accounts = $this->book->accounts()->where('posting_allowed', true)->orderBy('id')->get();
        $expense = $accounts[0];
        $cash = $accounts[1];
        $result = $accounts[2];
        $classifications = $accounts->map(fn (LedgerAccount $account) => [
            'ledger_account_id' => $account->id,
            'closing_role' => $account->is($expense) ? 'temporary_expense' : ($account->is($result) ? 'result_transfer' : 'permanent'),
            'carry_forward_eligible' => ! $account->is($expense),
            'requires_third_party_dimensions' => false,
            'requires_analytical_dimensions' => false,
        ])->all();
        $configuration = app(AccountingClosingConfigurationService::class)->create($this->book, [
            'version' => 'fixture-1',
            'effective_from' => '2026-01-01',
            'closing_journal_id' => $closing->id,
            'opening_journal_id' => $opening->id,
            'result_transfer_account_id' => $result->id,
            'classifications' => $classifications,
        ], $this->preparer);
        $configuration = app(AccountingClosingConfigurationService::class)
            ->professionalReview($configuration, $this->reviewer);
        DB::table('accounting_closing_configurations')->where('id', $configuration->id)
            ->update(['counsel_review_status' => 'approved', 'updated_at' => now()]);
        DB::table('accounting_books')->where('id', $this->book->id)
            ->update(['review_status' => 'approved', 'updated_at' => now()]);
        DB::table('accounting_frameworks')->where('id', $this->book->accounting_framework_id)
            ->update(['review_status' => 'approved', 'updated_at' => now()]);

        return [$configuration->fresh('classifications'), $expense, $cash, $result];
    }

    private function postEntry(
        int $amount,
        User $actor,
        ?LedgerAccount $debit = null,
        ?LedgerAccount $credit = null,
    ): JournalEntry {
        return app(AccountingPostingService::class)->post(
            $this->draftEntry($amount, null, $debit, $credit),
            $actor,
        );
    }

    private function draftEntry(
        int $amount,
        ?AccountingPeriod $period = null,
        ?LedgerAccount $debit = null,
        ?LedgerAccount $credit = null,
    ): JournalEntry {
        $period ??= $this->exercise->accountingPeriods()->orderBy('sequence')->get()->last();
        $accounts = $this->book->accounts()->where('posting_allowed', true)->orderBy('id')->take(2)->get();
        $debit ??= $accounts[0];
        $credit ??= $accounts[1];
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'accounting_book_id' => $this->book->id,
            'financial_exercise_id' => $this->exercise->id,
            'accounting_period_id' => $period->id,
            'accounting_journal_id' => $this->book->journals()->where('type', 'general')->firstOrFail()->id,
            'entry_date' => $period->starts_on,
            'reference' => 'CLOSE-QA',
            'description_fr' => 'Mouvement de test de clôture',
            'status' => 'draft',
            'created_by' => $this->preparer->id,
            'updated_by' => $this->preparer->id,
        ]);
        $entry->lines()->create([
            'sequence' => 1,
            'ledger_account_id' => $debit->id,
            'label' => 'Débit test',
            'debit_minor' => $amount,
            'credit_minor' => 0,
        ]);
        $entry->lines()->create([
            'sequence' => 2,
            'ledger_account_id' => $credit->id,
            'label' => 'Crédit test',
            'debit_minor' => 0,
            'credit_minor' => $amount,
        ]);

        return $entry;
    }
}
