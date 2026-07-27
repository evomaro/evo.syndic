<?php

namespace Tests\Feature;

use App\Models\AccountingFramework;
use App\Models\AccountingPeriod;
use App\Models\FinancialExercise;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Services\AccountingConfigurationService;
use App\Services\AccountingPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PhaseSixAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Organization $organization;

    private Residence $residence;

    private $book;

    private FinancialExercise $exercise;

    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->residence = Residence::factory()->for($this->organization)->create();
        $this->organization->users()->attach($this->actor, ['role' => 'owner', 'all_residences' => true]);
        $this->actor->update(['current_organization_id' => $this->organization->id, 'current_residence_id' => $this->residence->id]);
        $framework = AccountingFramework::where('stable_code', 'MA-SYNDIC-2-23-700')->firstOrFail();
        $this->book = app(AccountingConfigurationService::class)->adopt($this->organization->id, $this->residence->id, $framework, 'full', '2026-01-01', $this->actor);
        $this->exercise = FinancialExercise::factory()->create([
            'organization_id' => $this->organization->id, 'residence_id' => $this->residence->id,
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        app(AccountingConfigurationService::class)->configureExercise($this->exercise, $this->book, $this->actor);
        $this->exercise->refresh();
        $this->period = $this->exercise->accountingPeriods()->firstOrFail();
    }

    public function test_official_framework_metadata_and_published_immutability(): void
    {
        $framework = AccountingFramework::firstOrFail();
        $this->assertSame('Bulletin officiel n° 7466, édition de traduction officielle, 18 décembre 2025, pages 3920-3939', $framework->publication_reference);
        $this->assertSame(87, $framework->templates()->count());
        $this->assertNotNull($framework->templates()->where('code', '5121')->first());
        $this->expectException(LogicException::class);
        $framework->update(['name_fr' => 'Altéré']);
    }

    public function test_balanced_posting_is_numbered_idempotent_and_immutable(): void
    {
        $entry = $this->draft(1250, 1250);
        $posted = app(AccountingPostingService::class)->post($entry, $this->actor);
        $again = app(AccountingPostingService::class)->post($posted, $this->actor);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->entry_number, $again->entry_number);
        $this->assertSame(1, JournalEntry::whereNotNull('entry_number')->count());
        $this->assertNotNull($posted->posting_fingerprint);
        $this->expectException(LogicException::class);
        $posted->update(['description_fr' => 'Altération']);
    }

    public function test_unbalanced_invalid_account_and_locked_period_are_rejected(): void
    {
        $entry = $this->draft(1250, 1200);
        try {
            app(AccountingPostingService::class)->post($entry, $this->actor);
            $this->fail('Unbalanced posting should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }
        $entry->lines()->first()->account()->update(['posting_allowed' => false]);
        try {
            app(AccountingPostingService::class)->post($entry, $this->actor);
            $this->fail('Non-posting account should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }
        $entry->lines()->first()->account()->update(['posting_allowed' => true]);
        $entry->lines()->delete();
        $entry->delete();
        app(AccountingConfigurationService::class)->lock($this->period, $this->actor, 'Contrôle mensuel');
        $this->expectException(ValidationException::class);
        app(AccountingPostingService::class)->post($this->draft(1250, 1250), $this->actor);
    }

    public function test_reversal_inverts_lines_and_prevents_double_reversal(): void
    {
        $posted = app(AccountingPostingService::class)->post($this->draft(999, 999), $this->actor);
        $reversal = app(AccountingPostingService::class)->reverse($posted, $this->period, $this->actor, 'Correction documentée');
        $this->assertSame(999, $reversal->lines->sum('credit_minor'));
        $this->assertSame(999, $reversal->lines->sum('debit_minor'));
        $this->assertSame('reversed', $posted->fresh()->status);
        $this->expectException(ValidationException::class);
        app(AccountingPostingService::class)->reverse($posted->fresh(), $this->period, $this->actor, 'Seconde tentative');
    }

    public function test_routes_are_tenant_scoped_and_audit_command_is_machine_readable(): void
    {
        $foreignOrg = Organization::factory()->create();
        $foreignResidence = Residence::factory()->for($foreignOrg)->create();
        $foreignEntry = $this->draft(100, 100);
        $foreignEntry->forceFill(['organization_id' => $foreignOrg->id, 'residence_id' => $foreignResidence->id])->saveQuietly();
        $this->actingAs($this->actor)->get(route('accounting.entries.show', $foreignEntry))->assertNotFound();
        $this->assertSame(0, Artisan::call('evosyndic:audit-accounting', ['--organization' => $this->organization->id, '--json' => true]));
        $this->assertStringContainsString('"ok": true', Artisan::output());
    }

    public function test_account_codes_preserve_leading_zeroes_and_hierarchy_rejects_cycles_and_foreign_parents(): void
    {
        $accounts = $this->book->accounts()->take(3)->get();
        $accounts[0]->update(['code' => '0012']);
        $this->assertSame('0012', $accounts[0]->fresh()->code);

        $accounts[1]->update(['parent_id' => $accounts[0]->id]);
        try {
            $accounts[0]->update(['parent_id' => $accounts[1]->id]);
            $this->fail('A hierarchy cycle should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }

        $foreignResidence = Residence::factory()->for($this->organization)->create();
        $foreign = LedgerAccount::create([
            'organization_id' => $this->organization->id,
            'residence_id' => $foreignResidence->id,
            'accounting_book_id' => $this->book->id,
            'accounting_framework_id' => $this->book->accounting_framework_id,
            'code' => '0099',
            'label_fr' => 'Hors périmètre',
            'normal_balance' => 'debit',
            'account_class' => 'test',
            'effective_from' => '2026-01-01',
        ]);
        $this->expectException(ValidationException::class);
        $accounts[2]->update(['parent_id' => $foreign->id]);
    }

    public function test_exercise_overlap_is_rejected_and_period_generation_is_idempotent(): void
    {
        app(AccountingConfigurationService::class)->configureExercise($this->exercise, $this->book, $this->actor);
        $this->assertSame(12, $this->exercise->accountingPeriods()->count());

        $overlap = FinancialExercise::factory()->create([
            'organization_id' => $this->organization->id,
            'residence_id' => $this->residence->id,
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'draft',
        ]);
        $this->expectException(ValidationException::class);
        app(AccountingConfigurationService::class)->configureExercise($overlap, $this->book, $this->actor);
    }

    public function test_zero_and_double_sided_lines_and_cross_residence_accounts_are_rejected(): void
    {
        $entry = $this->draft(100, 100);
        $entry->lines()->first()->update(['debit_minor' => 0, 'credit_minor' => 0]);
        try {
            app(AccountingPostingService::class)->post($entry, $this->actor);
            $this->fail('A zero-sided line should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $entry->lines()->first()->update(['debit_minor' => 100, 'credit_minor' => 100]);
        try {
            app(AccountingPostingService::class)->post($entry, $this->actor);
            $this->fail('A double-sided line should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $foreignResidence = Residence::factory()->for($this->organization)->create();
        $foreignBook = app(AccountingConfigurationService::class)->adopt(
            $this->organization->id,
            $foreignResidence->id,
            AccountingFramework::firstOrFail(),
            'full',
            '2026-01-01',
            $this->actor,
        );
        $entry->lines()->first()->update([
            'ledger_account_id' => $foreignBook->accounts()->firstOrFail()->id,
            'debit_minor' => 100,
            'credit_minor' => 0,
        ]);
        $this->expectException(ValidationException::class);
        app(AccountingPostingService::class)->post($entry, $this->actor);
    }

    public function test_posted_lines_are_immutable_and_integrity_audit_returns_failure_for_tampering(): void
    {
        $posted = app(AccountingPostingService::class)->post($this->draft(321, 321), $this->actor);
        try {
            $posted->lines()->first()->update(['debit_minor' => 322]);
            $this->fail('A posted line should be immutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        DB::table('journal_entry_lines')->where('id', $posted->lines()->first()->id)->update(['debit_minor' => 322]);
        $this->assertSame(1, Artisan::call('evosyndic:audit-accounting', [
            '--organization' => $this->organization->id,
            '--residence' => $this->residence->id,
            '--json' => true,
        ]));
        $this->assertStringContainsString('"ok": false', Artisan::output());
    }

    public function test_regime_assessment_records_only_explicit_facts_and_stays_pending_review(): void
    {
        $assessment = app(AccountingConfigurationService::class)->recordRegimeAssessment(
            $this->book,
            $this->exercise,
            [
                'recommended_regime' => 'simplified',
                'inputs' => ['explicit_management_choice' => 'simplified', 'annual_budget_minor' => 1250000],
                'reason_codes' => ['explicit_management_choice'],
                'rule_version' => 'internal-neutral-v1',
                'explanation_fr' => ['Proposition explicite, sans interprétation automatique.'],
                'explanation_ar' => ['اقتراح صريح دون تفسير آلي.'],
            ],
            $this->actor,
        );

        $this->assertSame('pending_professional_review', $assessment->review_status);
        $this->assertSame('simplified', $assessment->recommended_regime);
        $this->assertSame($assessment->id, $this->exercise->fresh()->accounting_regime_assessment_id);
        $this->assertDatabaseHas('accounting_activity_events', ['action' => 'regime_assessment_recorded']);

        $this->expectException(ValidationException::class);
        app(AccountingConfigurationService::class)->recordRegimeAssessment(
            $this->book,
            null,
            ['recommended_regime' => 'full', 'inputs' => [], 'reason_codes' => [], 'rule_version' => ''],
            $this->actor,
        );
    }

    public function test_published_framework_successor_is_a_draft_copy_and_history_is_linked_once(): void
    {
        $framework = AccountingFramework::firstOrFail();
        $successor = app(AccountingConfigurationService::class)->createFrameworkSuccessor(
            $framework,
            ['version' => 'QA-SUCCESSOR-1', 'effective_date' => '2027-01-01'],
            $this->actor,
        );

        $this->assertSame('draft', $successor->status);
        $this->assertSame('pending_professional_review', $successor->review_status);
        $this->assertSame(87, $successor->templates()->count());
        $this->assertSame($successor->id, $framework->fresh()->superseded_by_id);

        $this->expectException(ValidationException::class);
        app(AccountingConfigurationService::class)->createFrameworkSuccessor(
            $framework->fresh(),
            ['version' => 'QA-SUCCESSOR-2'],
            $this->actor,
        );
    }

    public function test_tenant_subaccount_crud_is_scoped_audited_and_preserves_posted_codes(): void
    {
        $service = app(AccountingConfigurationService::class);
        $parent = $this->book->accounts()->whereNotNull('template_account_id')->firstOrFail();
        $subaccount = $service->createSubaccount($this->book, [
            'parent_id' => $parent->id,
            'code' => '00120',
            'label_fr' => 'Sous-compte locataire',
            'label_ar' => 'حساب فرعي',
        ], $this->actor);
        $updated = $service->updateSubaccount($subaccount, $this->book, [
            'label_fr' => 'Sous-compte locataire mis à jour',
            'active' => false,
            'effective_to' => '2026-12-31',
        ], $this->actor);

        $this->assertFalse($updated->active);
        $this->assertSame($parent->id, $updated->parent_id);
        $this->assertDatabaseHas('accounting_activity_events', ['action' => 'tenant_subaccount_created']);
        $this->assertDatabaseHas('accounting_activity_events', ['action' => 'tenant_subaccount_updated']);

        $service->updateSubaccount($subaccount->fresh(), $this->book, ['active' => true], $this->actor);
        $posted = $this->draft(100, 100);
        $posted->lines()->first()->update(['ledger_account_id' => $subaccount->id]);
        app(AccountingPostingService::class)->post($posted, $this->actor);
        $this->expectException(LogicException::class);
        $service->updateSubaccount($subaccount->fresh(), $this->book, ['code' => '00121'], $this->actor);
    }

    public function test_journal_crud_is_scoped_audited_and_used_codes_are_immutable(): void
    {
        $service = app(AccountingConfigurationService::class);
        $journal = $service->createJournal($this->book, [
            'code' => 'QA',
            'label_fr' => 'Journal QA',
            'label_ar' => 'يومية الاختبار',
            'type' => 'general',
        ], $this->actor);
        $service->updateJournal($journal, $this->book, ['label_fr' => 'Journal QA contrôlé'], $this->actor);
        $entry = $this->draft(100, 100);
        $entry->update(['accounting_journal_id' => $journal->id]);
        app(AccountingPostingService::class)->post($entry, $this->actor);

        $this->assertDatabaseHas('accounting_activity_events', ['action' => 'accounting_journal_created']);
        $this->assertDatabaseHas('accounting_activity_events', ['action' => 'accounting_journal_updated']);
        $this->expectException(ValidationException::class);
        $service->updateJournal($journal->fresh(), $this->book, ['code' => 'QB'], $this->actor);
    }

    private function draft(int $debit, int $credit): JournalEntry
    {
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id, 'residence_id' => $this->residence->id,
            'accounting_book_id' => $this->book->id, 'financial_exercise_id' => $this->exercise->id,
            'accounting_period_id' => $this->period->id, 'accounting_journal_id' => $this->book->journals()->first()->id,
            'entry_date' => $this->period->starts_on, 'description_fr' => 'Test manuel', 'status' => 'draft',
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
        ]);
        $accounts = $this->book->accounts()->take(2)->get();
        $entry->lines()->create(['sequence' => 1, 'ledger_account_id' => $accounts[0]->id, 'label' => 'Débit', 'debit_minor' => $debit, 'credit_minor' => 0]);
        $entry->lines()->create(['sequence' => 2, 'ledger_account_id' => $accounts[1]->id, 'label' => 'Crédit', 'debit_minor' => 0, 'credit_minor' => $credit]);

        return $entry;
    }
}
