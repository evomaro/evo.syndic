<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\AccountingClosingConfiguration;
use App\Models\AccountingClosingPackage;
use App\Models\FinancialExercise;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingClosingReadinessService
{
    public function __construct(
        private readonly AccountingReportService $reports,
        private readonly AccountingIntegrityAuditService $accountingAudit,
        private readonly SourcePostingIntegrityAuditService $sourceAudit,
        private readonly AccountingReportIntegrityAuditService $reportAudit,
    ) {}

    public function evaluate(
        AccountingBook $book,
        FinancialExercise $exercise,
        ?AccountingClosingPackage $package = null,
    ): array {
        $evaluatedAt = now()->toIso8601String();
        $snapshot = $this->snapshot($book, $exercise);
        $configuration = AccountingClosingConfiguration::query()
            ->with('classifications')
            ->where('accounting_book_id', $book->id)
            ->where('status', 'approved')
            ->whereDate('effective_from', '<=', $exercise->ends_on)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        $trial = $this->reports->generate($book, $exercise, [
            'report' => 'trial-balance',
            'date_from' => $exercise->starts_on->toDateString(),
            'date_to' => $exercise->ends_on->toDateString(),
            'snapshot_entry_id' => $snapshot['snapshot_entry_id'],
        ]);
        $accounting = $this->accountingAudit->run([
            'organization' => $book->organization_id,
            'residence' => $book->residence_id,
        ]);
        $sources = $this->sourceAudit->audit([
            'organization' => $book->organization_id,
            'residence' => $book->residence_id,
            'from' => $exercise->starts_on->toDateString(),
            'to' => $exercise->ends_on->toDateString(),
        ]);
        $report = $this->reportAudit->audit([
            'organization' => $book->organization_id,
            'residence' => $book->residence_id,
            'exercise' => $exercise->id,
        ]);

        $periods = $exercise->accountingPeriods()->orderBy('sequence')->get();
        $draftCount = DB::table('journal_entries')
            ->where('accounting_book_id', $book->id)
            ->where('financial_exercise_id', $exercise->id)
            ->where('status', 'draft')
            ->count();
        $failedCount = DB::table('accounting_source_postings')
            ->where('accounting_book_id', $book->id)
            ->where('status', 'failed')
            ->whereBetween('created_at', [$exercise->starts_on->startOfDay(), $exercise->ends_on->endOfDay()])
            ->count();
        $missingSources = collect($sources['violations'])
            ->where('classification', 'finalized_source_missing_posting')->count();
        $classificationCount = $configuration?->classifications
            ->where('review_status', 'approved')->count() ?? 0;
        $activePostingAccounts = $book->accounts()->where('active', true)->where('posting_allowed', true)->count();
        $classificationComplete = $configuration
            && $classificationCount === $activePostingAccounts
            && $activePostingAccounts > 0;
        $carryComplete = $classificationComplete
            && $configuration->classifications->where('review_status', 'approved')
                ->where('requires_third_party_dimensions', true)->isEmpty()
            && $configuration->classifications->where('review_status', 'approved')
                ->where('requires_analytical_dimensions', true)->isEmpty();
        $resultConfigured = $configuration
            && $configuration->result_transfer_account_id
            && $configuration->classifications
                ->where('ledger_account_id', $configuration->result_transfer_account_id)
                ->where('closing_role', 'result_transfer')
                ->where('review_status', 'approved')
                ->isNotEmpty();
        $professionalApproved = $configuration?->professional_review_status === 'approved'
            && $book->review_status === 'approved'
            && $book->framework()->value('review_status') === 'approved';
        $periodsComplete = $this->periodsAreComplete($exercise, $periods);
        $earlierPeriodsClosed = $periods->count() > 0
            && $periods->slice(0, -1)->every(fn ($period) => $period->status === 'locked')
            && $periods->last()->status === 'open';
        $sequenceGaps = $this->sequenceGaps($book, $exercise, $snapshot['snapshot_entry_id']);
        $nextExercise = FinancialExercise::query()
            ->where('accounting_book_id', $book->id)
            ->whereDate('starts_on', $exercise->ends_on->copy()->addDay())
            ->first();
        $openingConflict = $nextExercise
            ? DB::table('accounting_opening_batches')->where('accounting_book_id', $book->id)
                ->where('financial_exercise_id', $nextExercise->id)->exists()
            : false;
        $laterActivity = DB::table('journal_entries')->where('accounting_book_id', $book->id)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereDate('entry_date', '>', $exercise->ends_on)
            ->count();
        $operationalIssues = app(FinancialExerciseLifecycleService::class)->closeReadiness($exercise);

        $checks = collect([
            $this->check('book_scope', (int) $exercise->accounting_book_id === (int) $book->id
                && (int) $exercise->organization_id === (int) $book->organization_id
                && (int) $exercise->residence_id === (int) $book->residence_id, 1, true, true, true),
            $this->check('fiscal_year_dates', $exercise->starts_on->lte($exercise->ends_on), [
                'starts_on' => $exercise->starts_on->toDateString(),
                'ends_on' => $exercise->ends_on->toDateString(),
            ], true, true, true),
            $this->check('period_completeness', $periodsComplete, $periods->count(), true, true, true),
            $this->check('earlier_periods_closed', $earlierPeriodsClosed, [
                'locked' => $periods->where('status', 'locked')->count(),
                'total' => $periods->count(),
                'final_period_status' => $periods->last()?->status,
            ], false, true, true),
            $this->check('draft_entry_count', $draftCount === 0, $draftCount, true, true, true),
            $this->check('failed_posting_count', $failedCount === 0, $failedCount, true, true, true),
            $this->check('missing_source_posting_count', $missingSources === 0, $missingSources, false, true, true),
            $this->check('ledger_integrity', $accounting['ok'], count($accounting['violations']), true, true, true),
            $this->check('trial_balance_equality', (bool) ($trial['totals']['balanced'] ?? false), $trial['totals'], true, true, true),
            $this->check('journal_sequence_integrity', $sequenceGaps === [], $sequenceGaps, false, true, true),
            $this->check('fingerprint_checksum_integrity', collect($accounting['violations'])->whereIn(
                'classification',
                ['fingerprint_mismatch', 'duplicate_entry_number']
            )->isEmpty(), $accounting['counts'], true, true, true),
            $this->check('reversal_consistency', collect($accounting['violations'])
                ->where('classification', 'invalid_reversal_chain')->isEmpty(), $accounting['counts'], true, true, true),
            $this->check('source_integrity', $sources['ok'], $sources['violation_count'], false, true, true),
            $this->check('report_integrity', $report['ok'], $report['violation_count'], true, true, true),
            $this->check('operational_close_readiness', $operationalIssues === [], $operationalIssues, true, true, true),
            $this->check('closing_configuration', (bool) $configuration, $configuration?->id, false, true, true, ! $configuration),
            $this->check('account_classification_completeness', $classificationComplete, [
                'approved' => $classificationCount,
                'required' => $activePostingAccounts,
            ], false, true, true, ! $configuration),
            $this->check('closing_result_account', (bool) $resultConfigured, $configuration?->result_transfer_account_id, false, true, true, ! $configuration),
            $this->check('carry_forward_classification', $carryComplete, [
                'dimension_blockers' => $configuration?->classifications
                    ->filter(fn ($item) => $item->requires_third_party_dimensions || $item->requires_analytical_dimensions)
                    ->count() ?? null,
            ], false, false, true, ! $configuration),
            $this->check('unsupported_currency', ($configuration?->currency ?? 'MAD') === 'MAD', $configuration?->currency ?? 'MAD', true, true, true),
            $this->check('opening_balance_conflict', ! $openingConflict, $openingConflict ? 1 : 0, false, false, true),
            $this->check('existing_closing_posting', ! DB::table('journal_entries')
                ->where('accounting_book_id', $book->id)
                ->where('financial_exercise_id', $exercise->id)
                ->where('source_type', 'accounting_closing_package')
                ->whereIn('status', ['posted', 'reversed'])->exists(), 0, false, false, true),
            $this->check('later_fiscal_year_activity', $laterActivity === 0, $laterActivity, false, false, true),
            $this->check('accountant_professional_approval', $professionalApproved, [
                'configuration' => $configuration?->professional_review_status,
                'book' => $book->review_status,
                'framework' => $book->framework()->value('review_status'),
            ], false, true, true, ! $configuration),
            $this->check('counsel_approval', $configuration?->counsel_review_status === 'approved', $configuration?->counsel_review_status, false, true, true, ! $configuration),
        ])->map(function (array $check) use ($evaluatedAt, $snapshot) {
            $check['evaluated_at'] = $evaluatedAt;
            $check['snapshot_entry_id'] = $snapshot['snapshot_entry_id'];

            return $check;
        })->values();

        return [
            'technical_ready' => ! $checks->contains(fn ($check) => $check['blocks_preparation'] && $check['result'] !== 'pass'),
            'approval_ready' => ! $checks->contains(fn ($check) => $check['blocks_approval'] && $check['result'] !== 'pass'),
            'execution_ready' => ! $checks->contains(fn ($check) => $check['blocks_execution'] && $check['result'] !== 'pass'),
            'evaluated_at' => $evaluatedAt,
            'snapshot' => $snapshot,
            'snapshot_fingerprint' => $this->fingerprint($snapshot),
            'configuration_id' => $configuration?->id,
            'checks' => $checks->all(),
            'trial_balance_totals' => $trial['totals'],
            'package_id' => $package?->id,
        ];
    }

    public function snapshot(AccountingBook $book, FinancialExercise $exercise): array
    {
        $entries = DB::table('journal_entries as e')
            ->leftJoin('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.organization_id', $book->organization_id)
            ->where('e.residence_id', $book->residence_id)
            ->where('e.accounting_book_id', $book->id)
            ->where('e.financial_exercise_id', $exercise->id)
            ->whereIn('e.status', ['posted', 'reversed']);
        $totals = (clone $entries)->selectRaw(
            'COUNT(DISTINCT e.id) as entry_count, COALESCE(SUM(l.debit_minor), 0) as debit_minor, '.
            'COALESCE(SUM(l.credit_minor), 0) as credit_minor, COALESCE(MAX(e.id), 0) as snapshot_entry_id, '.
            'MAX(e.entry_date) as latest_entry_date'
        )->first();
        $sequences = DB::table('accounting_journal_sequences as s')
            ->join('accounting_journals as j', 'j.id', '=', 's.accounting_journal_id')
            ->where('j.accounting_book_id', $book->id)
            ->where('s.financial_exercise_id', $exercise->id)
            ->orderBy('j.code')
            ->get(['j.code', 's.next_value'])
            ->map(fn ($row) => ['journal' => $row->code, 'next_value' => (int) $row->next_value])
            ->all();

        return [
            'organization_id' => $book->organization_id,
            'residence_id' => $book->residence_id,
            'accounting_book_id' => $book->id,
            'financial_exercise_id' => $exercise->id,
            'snapshot_entry_id' => (int) $totals->snapshot_entry_id,
            'posted_entry_count' => (int) $totals->entry_count,
            'debit_minor' => (int) $totals->debit_minor,
            'credit_minor' => (int) $totals->credit_minor,
            'latest_entry_date' => $totals->latest_entry_date,
            'journal_sequences' => $sequences,
        ];
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function check(
        string $code,
        bool $passes,
        mixed $evidence,
        bool $blocksPreparation,
        bool $blocksApproval,
        bool $blocksExecution,
        bool $unavailable = false,
    ): array {
        $labels = $this->labels()[$code];

        return [
            'code' => $code,
            'label_fr' => $labels[0],
            'label_ar' => $labels[1],
            'result' => $passes ? 'pass' : ($unavailable ? 'unavailable' : 'blocked'),
            'evidence' => $evidence,
            'drill_down' => $labels[2] ?? null,
            'blocks_preparation' => $blocksPreparation,
            'blocks_approval' => $blocksApproval,
            'blocks_execution' => $blocksExecution,
        ];
    }

    private function periodsAreComplete(FinancialExercise $exercise, Collection $periods): bool
    {
        if ($periods->isEmpty()
            || $periods->first()->starts_on->toDateString() !== $exercise->starts_on->toDateString()
            || $periods->last()->ends_on->toDateString() !== $exercise->ends_on->toDateString()) {
            return false;
        }
        foreach ($periods->values() as $index => $period) {
            if ((int) $period->sequence !== $index + 1) {
                return false;
            }
            if ($index > 0
                && $periods[$index - 1]->ends_on->copy()->addDay()->toDateString() !== $period->starts_on->toDateString()) {
                return false;
            }
        }

        return true;
    }

    private function sequenceGaps(AccountingBook $book, FinancialExercise $exercise, int $boundary): array
    {
        return DB::table('journal_entries as e')
            ->join('accounting_journals as j', 'j.id', '=', 'e.accounting_journal_id')
            ->where('e.accounting_book_id', $book->id)
            ->where('e.financial_exercise_id', $exercise->id)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.id', '<=', $boundary)
            ->whereNotNull('e.entry_number')
            ->orderBy('j.code')->orderBy('e.entry_number')
            ->get(['j.code', 'e.entry_number'])
            ->groupBy('code')
            ->flatMap(function (Collection $entries, string $journal) {
                $numbers = $entries->map(fn ($entry) => (int) substr($entry->entry_number, -6))->values();
                if ($numbers->isEmpty()) {
                    return [];
                }
                $expected = range($numbers->min(), $numbers->max());
                $missing = array_values(array_diff($expected, $numbers->all()));

                return $missing ? [['journal' => $journal, 'missing' => $missing]] : [];
            })->values()->all();
    }

    private function labels(): array
    {
        return [
            'book_scope' => ['Périmètre du livre comptable', 'نطاق الدفتر المحاسبي'],
            'fiscal_year_dates' => ['Dates de l’exercice', 'تواريخ السنة المالية'],
            'period_completeness' => ['Continuité des périodes', 'استمرارية الفترات'],
            'earlier_periods_closed' => ['Périodes antérieures clôturées', 'إقفال الفترات السابقة'],
            'draft_entry_count' => ['Écritures brouillon', 'القيود المسودة', 'accounting.index'],
            'failed_posting_count' => ['Comptabilisations échouées', 'عمليات الترحيل الفاشلة', 'accounting.index'],
            'missing_source_posting_count' => ['Sources finalisées non comptabilisées', 'مصادر نهائية غير مُرحّلة', 'accounting.reports.index'],
            'ledger_integrity' => ['Intégrité du grand livre', 'سلامة دفتر الأستاذ'],
            'trial_balance_equality' => ['Équilibre de la balance', 'توازن ميزان المراجعة', 'accounting.reports.index'],
            'journal_sequence_integrity' => ['Séquences des journaux', 'تسلسل اليوميات'],
            'fingerprint_checksum_integrity' => ['Empreintes des écritures', 'بصمات القيود'],
            'reversal_consistency' => ['Cohérence des contre-passations', 'اتساق القيود العكسية'],
            'source_integrity' => ['Intégrité des sources', 'سلامة المصادر'],
            'report_integrity' => ['Intégrité des rapports', 'سلامة التقارير'],
            'operational_close_readiness' => ['Préparation opérationnelle', 'الجاهزية التشغيلية'],
            'closing_configuration' => ['Configuration de clôture approuvée', 'إعداد إقفال معتمد'],
            'account_classification_completeness' => ['Classification complète des comptes', 'اكتمال تصنيف الحسابات'],
            'closing_result_account' => ['Compte de transfert du résultat', 'حساب تحويل النتيجة'],
            'carry_forward_classification' => ['Éligibilité au report à nouveau', 'أهلية الترحيل'],
            'unsupported_currency' => ['Devise prise en charge', 'العملة المدعومة'],
            'opening_balance_conflict' => ['Conflit de solde d’ouverture', 'تعارض الرصيد الافتتاحي'],
            'existing_closing_posting' => ['Écriture de clôture existante', 'قيد إقفال موجود'],
            'later_fiscal_year_activity' => ['Activité d’un exercice ultérieur', 'نشاط سنة مالية لاحقة'],
            'accountant_professional_approval' => ['Approbation professionnelle du comptable', 'اعتماد المحاسب المهني'],
            'counsel_approval' => ['Décision du conseil juridique', 'قرار المستشار القانوني'],
        ];
    }
}
