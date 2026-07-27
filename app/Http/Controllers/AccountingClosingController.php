<?php

namespace App\Http\Controllers;

use App\Exports\AccountingReportExport;
use App\Models\AccountingActivityEvent;
use App\Models\AccountingBook;
use App\Models\AccountingClosingAccountClassification;
use App\Models\AccountingClosingConfiguration;
use App\Models\AccountingClosingPackage;
use App\Models\AccountingPeriod;
use App\Models\FinancialExercise;
use App\Services\AccountingClosingConfigurationService;
use App\Services\AccountingClosingReadinessService;
use App\Services\AccountingClosingWorkflowService;
use App\Services\AccountingReportService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class AccountingClosingController extends Controller
{
    public function index(
        Request $request,
        TenantContext $context,
        AccountingClosingReadinessService $readiness,
        AccountingClosingWorkflowService $workflow,
    ) {
        $book = $this->book($context);
        $exercise = FinancialExercise::query()
            ->where('organization_id', $book->organization_id)
            ->where('residence_id', $book->residence_id)
            ->where('accounting_book_id', $book->id)
            ->when($request->integer('financial_exercise_id'), fn ($q, $id) => $q->whereKey($id))
            ->orderByDesc('starts_on')->firstOrFail();
        $packages = AccountingClosingPackage::query()
            ->where('accounting_book_id', $book->id)
            ->where('financial_exercise_id', $exercise->id)
            ->with(['configuration', 'periodSnapshots', 'transitions'])
            ->orderByDesc('generation')->get();

        return Inertia::render('Accounting/Closing', [
            'book' => $book->load('framework'),
            'exercise' => $exercise->load('accountingPeriods'),
            'exercises' => FinancialExercise::where('accounting_book_id', $book->id)
                ->orderByDesc('starts_on')->get(),
            'readiness' => $readiness->evaluate($book, $exercise, $packages->first()),
            'packages' => $packages,
            'configurations' => AccountingClosingConfiguration::where('accounting_book_id', $book->id)
                ->withCount('classifications')->orderByDesc('effective_from')->get(),
            'journals' => $book->journals()->orderBy('code')->get(),
            'accounts' => $book->accounts()->orderBy('code')->get(['id', 'code', 'label_fr', 'label_ar', 'posting_allowed']),
            'reopeningDiagnostics' => $packages->first()
                ? $workflow->reopeningDiagnostics($packages->first())
                : null,
        ]);
    }

    public function storeConfiguration(
        Request $request,
        TenantContext $context,
        AccountingClosingConfigurationService $service,
    ) {
        $book = $this->book($context);
        $data = $request->validate([
            'version' => 'required|string|max:40',
            'effective_from' => 'required|date',
            'closing_journal_id' => 'nullable|integer',
            'opening_journal_id' => 'nullable|integer',
            'result_transfer_account_id' => 'nullable|integer',
            'classifications' => 'array',
            'classifications.*.ledger_account_id' => 'required|integer',
            'classifications.*.closing_role' => ['required', Rule::in(AccountingClosingAccountClassification::ROLES)],
            'classifications.*.carry_forward_eligible' => 'boolean',
            'classifications.*.requires_third_party_dimensions' => 'boolean',
            'classifications.*.requires_analytical_dimensions' => 'boolean',
        ]);
        $service->create($book, $data, $request->user());

        return back()->with('success', __('Configuration de clôture créée comme brouillon non approuvé.'));
    }

    public function reviewConfiguration(
        Request $request,
        AccountingClosingConfiguration $configuration,
        TenantContext $context,
        AccountingClosingConfigurationService $service,
    ) {
        $this->scoped($configuration, $context);
        $service->professionalReview($configuration, $request->user());

        return back()->with('success', __('Revue professionnelle enregistrée; la décision du conseil reste distincte.'));
    }

    public function prepare(
        Request $request,
        FinancialExercise $exercise,
        TenantContext $context,
        AccountingClosingWorkflowService $workflow,
    ) {
        $book = $this->book($context);
        $this->exercise($exercise, $book);
        $workflow->prepare($book, $exercise, $request->user());

        return back()->with('success', __('Dossier de clôture préparé à partir d’un instantané serveur.'));
    }

    public function review(
        Request $request,
        AccountingClosingPackage $package,
        TenantContext $context,
        AccountingClosingWorkflowService $workflow,
    ) {
        $this->scoped($package, $context);
        $workflow->review($package, $request->user());

        return back()->with('success', __('Dossier de clôture revu.'));
    }

    public function approve(
        Request $request,
        AccountingClosingPackage $package,
        TenantContext $context,
        AccountingClosingWorkflowService $workflow,
    ) {
        $this->scoped($package, $context);
        $workflow->approve($package, $request->user());

        return back()->with('success', __('Dossier de clôture approuvé.'));
    }

    public function closePeriod(
        Request $request,
        AccountingClosingPackage $package,
        AccountingPeriod $period,
        TenantContext $context,
        AccountingClosingWorkflowService $workflow,
    ) {
        $this->scoped($package, $context);
        $this->scoped($period, $context);
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $workflow->closePeriod($package, $period, $request->user(), $data['reason']);

        return back()->with('success', __('Période clôturée et instantané enregistré.'));
    }

    public function execute(
        Request $request,
        AccountingClosingPackage $package,
        TenantContext $context,
        AccountingClosingWorkflowService $workflow,
    ) {
        $this->scoped($package, $context);
        $data = $request->validate(['confirmation' => 'required|string|max:40']);
        abort_unless($data['confirmation'] === $package->exercise()->value('reference'), 422);
        $workflow->executeClosing($package, $request->user());

        return back()->with('success', __('Clôture exécutée et contrôlée.'));
    }

    public function carryForward(
        Request $request,
        AccountingClosingPackage $package,
        TenantContext $context,
        AccountingClosingWorkflowService $workflow,
    ) {
        $this->scoped($package, $context);
        $data = $request->validate(['confirmation' => 'required|string|max:40']);
        abort_unless($data['confirmation'] === $package->exercise()->value('reference'), 422);
        $workflow->executeCarryForward($package, $request->user());

        return back()->with('success', __('Report à nouveau exécuté.'));
    }

    public function reopen(
        Request $request,
        AccountingClosingPackage $package,
        TenantContext $context,
        AccountingClosingWorkflowService $workflow,
    ) {
        $this->scoped($package, $context);
        $data = $request->validate([
            'reason' => 'required|string|max:3000',
            'confirmation' => 'required|string|max:40',
        ]);
        abort_unless($data['confirmation'] === $package->exercise()->value('reference'), 422);
        $workflow->reopen($package, $request->user(), $data['reason']);

        return back()->with('success', __('Exercice rouvert par contre-passation contrôlée de la clôture.'));
    }

    public function export(
        Request $request,
        AccountingClosingPackage $package,
        string $format,
        TenantContext $context,
        AccountingReportService $reports,
    ) {
        $this->scoped($package, $context);
        abort_unless(in_array($format, ['pdf', 'xlsx', 'csv', 'json'], true), 404);
        $book = AccountingBook::findOrFail($package->accounting_book_id);
        $exercise = FinancialExercise::findOrFail($package->financial_exercise_id);
        $this->recordExport($request, $package, $format);
        if ($format === 'json') {
            return response()->json([
                'package' => $package->only([
                    'id', 'generation', 'state', 'currency', 'snapshot_entry_id', 'snapshot_data',
                    'readiness_results', 'trial_balance_totals', 'integrity_fingerprint',
                    'prepared_at', 'reviewed_at', 'approved_at', 'executed_at',
                    'closing_entry_id', 'carry_forward_batch_id',
                ]),
                'not_certified' => true,
            ]);
        }
        $trial = $reports->generate($book, $exercise, [
            'report' => 'trial-balance',
            'date_from' => $exercise->starts_on->toDateString(),
            'date_to' => $exercise->ends_on->toDateString(),
            'snapshot_entry_id' => $package->snapshot_entry_id,
        ], 1, 100000);
        $rows = array_map(fn ($row) => $this->safeRow((array) $row), $trial['rows']);
        $headings = $rows ? array_keys($rows[0]) : [];
        $values = array_map('array_values', $rows);
        $name = 'closing-package-'.$package->id.'-snapshot-'.$package->snapshot_entry_id;
        if ($format === 'pdf') {
            return Pdf::loadView('pdf.accounting-closing-evidence', compact(
                'package', 'book', 'exercise', 'trial', 'headings', 'values'
            ))->setPaper('a4', 'landscape')->download($name.'.pdf');
        }

        return Excel::download(
            new AccountingReportExport($values, $headings),
            $name.'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

    private function safeRow(array $row): array
    {
        return collect($row)->map(function ($value, $key) {
            if (is_string($value) && preg_match('/^[=+@-]/u', $value)) {
                $value = "'".$value;
            }
            if ($key === 'code' && $value !== null) {
                $value = "'".ltrim((string) $value, "'");
            }

            return $value;
        })->all();
    }

    private function recordExport(Request $request, AccountingClosingPackage $package, string $format): void
    {
        AccountingActivityEvent::create([
            'organization_id' => $package->organization_id,
            'residence_id' => $package->residence_id,
            'record_type' => AccountingClosingPackage::class,
            'record_id' => $package->id,
            'action' => 'closing_evidence_exported',
            'actor_id' => $request->user()->id,
            'reason' => null,
            'before_evidence' => null,
            'after_evidence' => [
                'format' => $format,
                'generation' => $package->generation,
                'snapshot_entry_id' => $package->snapshot_entry_id,
                'integrity_fingerprint' => $package->integrity_fingerprint,
            ],
            'context' => 'http',
            'occurred_at' => now(),
        ]);
    }

    private function book(TenantContext $context): AccountingBook
    {
        return AccountingBook::where('organization_id', $context->organization()->id)
            ->where('residence_id', $context->residence()->id)->firstOrFail();
    }

    private function exercise(FinancialExercise $exercise, AccountingBook $book): void
    {
        abort_unless((int) $exercise->organization_id === (int) $book->organization_id
            && (int) $exercise->residence_id === (int) $book->residence_id
            && (int) $exercise->accounting_book_id === (int) $book->id, 404);
    }

    private function scoped($model, TenantContext $context): void
    {
        abort_unless((int) $model->organization_id === (int) $context->organization()->id
            && (int) $model->residence_id === (int) $context->residence()->id, 404);
    }
}
