<?php

namespace Tests\Feature;

use App\Models\AccountingFramework;
use App\Models\FinancialExercise;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Services\AccountingConfigurationService;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseSixAccountingReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Organization $organization;

    private Residence $residence;

    private $book;

    private FinancialExercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->residence = Residence::factory()->for($this->organization)->create();
        $this->organization->users()->attach($this->actor, ['role' => 'owner', 'all_residences' => true]);
        $this->actor->update(['current_organization_id' => $this->organization->id, 'current_residence_id' => $this->residence->id]);
        $this->book = app(AccountingConfigurationService::class)->adopt(
            $this->organization->id, $this->residence->id,
            AccountingFramework::where('stable_code', 'MA-SYNDIC-2-23-700')->firstOrFail(),
            'full', '2026-01-01', $this->actor
        );
        $this->exercise = FinancialExercise::factory()->create([
            'organization_id' => $this->organization->id, 'residence_id' => $this->residence->id,
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        app(AccountingConfigurationService::class)->configureExercise($this->exercise, $this->book, $this->actor);
        $this->exercise->refresh();
    }

    public function test_journal_includes_only_posted_movements_and_keeps_reversals_balanced(): void
    {
        $this->draft(999, '2026-01-10', 'Brouillon non publié');
        $posted = app(AccountingPostingService::class)->post($this->draft(1200, '2026-01-11'), $this->actor);
        app(AccountingPostingService::class)->reverse(
            $posted, $this->exercise->accountingPeriods()->firstOrFail(), $this->actor, 'Correction documentée'
        );

        $report = $this->report('journal');
        $this->assertCount(2, $report['rows']);
        $this->assertSame(2400, $report['totals']['debit_minor']);
        $this->assertSame(2400, $report['totals']['credit_minor']);
        $this->assertTrue($report['totals']['balanced']);
        $this->assertNotNull(collect($report['rows'])->firstWhere('reversal_of_id', $posted->id));
        $this->assertSame(
            collect($report['rows'])->pluck('entry_date')->sort()->values()->all(),
            collect($report['rows'])->pluck('entry_date')->values()->all()
        );
    }

    public function test_general_ledger_account_ledger_and_trial_balance_share_exact_totals(): void
    {
        app(AccountingPostingService::class)->post($this->draft(1000, '2026-01-15'), $this->actor);
        app(AccountingPostingService::class)->post($this->draft(250, '2026-02-15'), $this->actor);
        $account = $this->book->accounts()->orderBy('id')->firstOrFail();
        $filters = ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'];

        $ledger = $this->report('general-ledger', $filters);
        $row = collect($ledger['rows'])->firstWhere('account_id', $account->id);
        $this->assertSame(1000, $row['opening_debit_minor']);
        $this->assertSame(250, $row['period_debit_minor']);
        $this->assertSame(1250, $row['closing_debit_minor']);

        $accountLedger = $this->report('account-ledger', $filters + ['ledger_account_id' => $account->id]);
        $this->assertSame(1000, $accountLedger['totals']['opening_debit_minor']);
        $this->assertSame(1250, $accountLedger['rows'][0]->running_debit_minor);
        $this->assertSame($account->code, $accountLedger['account']['code']);

        $trial = $this->report('trial-balance', $filters);
        $this->assertTrue($trial['totals']['balanced']);
        $this->assertSame($trial['totals']['period_debit_minor'], $trial['totals']['period_credit_minor']);
        $this->assertSame($trial['totals']['closing_debit_minor'], $trial['totals']['closing_credit_minor']);
    }

    public function test_reports_are_book_scoped_and_cross_residence_filters_are_rejected(): void
    {
        app(AccountingPostingService::class)->post($this->draft(500, '2026-03-01'), $this->actor);
        $foreignResidence = Residence::factory()->for($this->organization)->create();
        $foreignBook = app(AccountingConfigurationService::class)->adopt(
            $this->organization->id, $foreignResidence->id, AccountingFramework::firstOrFail(), 'full', '2026-01-01', $this->actor
        );

        $this->actingAs($this->actor)
            ->get(route('accounting.reports.index', ['report' => 'journal']))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Accounting/Reports')->where('report.pagination.total', 1));
        $this->actingAs($this->actor)
            ->get(route('accounting.reports.index', ['report' => 'journal', 'accounting_book_id' => $foreignBook->id]))
            ->assertNotFound();
    }

    public function test_csv_export_uses_same_snapshot_and_records_safe_activity_without_mutating_entries(): void
    {
        app(AccountingPostingService::class)->post($this->draft(777, '2026-04-01', '=unsafe'), $this->actor);
        $before = DB::table('journal_entries')->get()->map(fn ($row) => (array) $row)->all();

        $response = $this->actingAs($this->actor)->get(route('accounting.reports.export', [
            'format' => 'csv', 'report' => 'journal', 'financial_exercise_id' => $this->exercise->id,
        ]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=unsafe", $response->streamedContent());
        $this->assertSame($before, DB::table('journal_entries')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseHas('accounting_activity_events', ['action' => 'report_exported', 'record_id' => $this->book->id]);
    }

    public function test_json_export_uses_the_same_pinned_snapshot_and_safe_rows(): void
    {
        $entry = app(AccountingPostingService::class)->post($this->draft(777, '2026-04-01', '=unsafe'), $this->actor);

        $this->actingAs($this->actor)->get(route('accounting.reports.export', [
            'format' => 'json',
            'report' => 'journal',
            'financial_exercise_id' => $this->exercise->id,
            'snapshot_entry_id' => $entry->id,
        ]))
            ->assertOk()
            ->assertJsonPath('report.snapshot_entry_id', $entry->id)
            ->assertJsonPath('rows.0.description_fr', "'=unsafe")
            ->assertJsonPath('not_certified', true);
    }

    public function test_reconciliation_and_period_summary_are_balanced_and_read_only(): void
    {
        app(AccountingPostingService::class)->post($this->draft(321, '2026-05-01'), $this->actor);
        $before = DB::table('journal_entries')->count();
        $reconciliation = $this->report('reconciliation');
        $periods = $this->report('period-summary');

        $this->assertSame(0, $reconciliation['totals']['difference_minor']);
        $this->assertSame(1, $periods['totals']['entry_count']);
        $this->assertSame(321, $periods['totals']['debit_minor']);
        $this->assertSame(321, $periods['totals']['credit_minor']);
        $this->assertSame($before, DB::table('journal_entries')->count());
    }

    public function test_report_integrity_command_is_json_read_only_and_returns_nonzero_for_unbalanced_tampering(): void
    {
        $entry = app(AccountingPostingService::class)->post($this->draft(654, '2026-06-01'), $this->actor);
        $this->assertSame(0, Artisan::call('evosyndic:audit-accounting-reports', [
            '--organization' => $this->organization->id, '--residence' => $this->residence->id, '--json' => true,
        ]));
        $this->assertStringContainsString('"ok": true', Artisan::output());

        DB::table('journal_entry_lines')->where('id', $entry->lines()->first()->id)->update(['debit_minor' => 655]);
        $this->assertSame(1, Artisan::call('evosyndic:audit-accounting-reports', [
            '--organization' => $this->organization->id, '--residence' => $this->residence->id, '--json' => true,
        ]));
        $this->assertStringContainsString('trial_balance_unbalanced', Artisan::output());
    }

    public function test_xlsx_and_arabic_pdf_exports_are_authorized_and_non_certifying(): void
    {
        app(AccountingPostingService::class)->post($this->draft(888, '2026-07-01'), $this->actor);
        $xlsx = $this->actingAs($this->actor)->get(route('accounting.reports.export', [
            'format' => 'xlsx', 'report' => 'trial-balance', 'financial_exercise_id' => $this->exercise->id,
        ]));
        $xlsx->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actor->update(['preferred_language' => 'ar']);
        $pdf = $this->actingAs($this->actor->fresh())->get(route('accounting.reports.export', [
            'format' => 'pdf', 'report' => 'journal', 'financial_exercise_id' => $this->exercise->id,
        ]));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_report_and_export_permissions_do_not_follow_operational_permissions(): void
    {
        $limited = User::factory()->create();
        $this->organization->users()->attach($limited, ['role' => 'maintenance_agent', 'all_residences' => true]);
        $limited->update(['current_organization_id' => $this->organization->id, 'current_residence_id' => $this->residence->id]);
        $activityBefore = DB::table('accounting_activity_events')->count();

        $this->actingAs($limited)->get(route('accounting.reports.index', ['report' => 'journal']))->assertForbidden();
        $this->actingAs($limited)->get(route('accounting.reports.export', ['format' => 'csv', 'report' => 'journal']))->assertForbidden();
        $this->assertSame($activityBefore, DB::table('accounting_activity_events')->count());
    }

    private function report(string $type, array $filters = []): array
    {
        return app(AccountingReportService::class)->generate($this->book, $this->exercise, [
            'report' => $type, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
            ...$filters,
        ]);
    }

    private function draft(int $amount, string $date, string $description = 'Test rapport'): JournalEntry
    {
        $period = $this->exercise->accountingPeriods()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->firstOrFail();
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id, 'residence_id' => $this->residence->id,
            'accounting_book_id' => $this->book->id, 'financial_exercise_id' => $this->exercise->id,
            'accounting_period_id' => $period->id, 'accounting_journal_id' => $this->book->journals()->first()->id,
            'entry_date' => $date, 'description_fr' => $description, 'status' => 'draft',
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
        ]);
        $accounts = $this->book->accounts()->orderBy('id')->take(2)->get();
        $entry->lines()->create(['sequence' => 1, 'ledger_account_id' => $accounts[0]->id, 'label' => 'Débit', 'debit_minor' => $amount, 'credit_minor' => 0]);
        $entry->lines()->create(['sequence' => 2, 'ledger_account_id' => $accounts[1]->id, 'label' => 'Crédit', 'debit_minor' => 0, 'credit_minor' => $amount]);

        return $entry;
    }
}
