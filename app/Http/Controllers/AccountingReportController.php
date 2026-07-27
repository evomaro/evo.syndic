<?php

namespace App\Http\Controllers;

use App\Exports\AccountingReportExport;
use App\Models\AccountingActivityEvent;
use App\Models\AccountingBook;
use App\Models\FinancialExercise;
use App\Services\AccountingReportService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class AccountingReportController extends Controller
{
    private const PERMISSIONS = [
        'journal' => 'view_journal_reports',
        'general-ledger' => 'view_general_ledger',
        'account-ledger' => 'view_account_ledgers',
        'trial-balance' => 'view_trial_balance',
        'receivables' => 'view_accounting_receivables',
        'payables' => 'view_accounting_payables',
        'budget-actual' => 'view_budget_actual',
        'reconciliation' => 'view_accounting_reconciliation',
        'period-summary' => 'view_journal_reports',
    ];

    public function index(Request $request, TenantContext $context, AccountingReportService $reports)
    {
        [$book, $exercise, $filters] = $this->resolve($request, $context);
        $this->authorizeReport($request, $filters['report']);
        $report = $reports->generate($book, $exercise, $filters, max(1, (int) $request->integer('page', 1)));

        return Inertia::render('Accounting/Reports', [
            'book' => $book->load('framework'),
            'exercise' => $exercise,
            'exercises' => $context->residence()->financialExercises()->where('accounting_book_id', $book->id)->orderByDesc('starts_on')->get(),
            'periods' => $exercise->accountingPeriods()->orderBy('sequence')->get(),
            'journals' => $book->journals()->orderBy('code')->get(),
            'accounts' => $book->accounts()->orderBy('code')->get(['id', 'code', 'label_fr', 'label_ar', 'parent_id']),
            'report' => $report,
            'availableReports' => collect(self::PERMISSIONS)->filter(
                fn (string $permission) => $request->user()->canInOrganization($permission, $context->organization())
            )->keys()->values(),
            'canExport' => $request->user()->canInOrganization('export_accounting_reports', $context->organization()),
        ]);
    }

    public function export(Request $request, string $format, TenantContext $context, AccountingReportService $reports)
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf', 'json'], true), 404);
        abort_unless($request->user()->canInOrganization('export_accounting_reports', $context->organization()), 403);
        [$book, $exercise, $filters] = $this->resolve($request, $context);
        $this->authorizeReport($request, $filters['report']);
        $report = $reports->generate($book, $exercise, $filters, 1, 100000);
        abort_if(($report['integrity'] ?? null) === 'unbalanced_ledger', 409, 'The posted ledger is unbalanced.');
        $rows = $this->flatRows($reports->exportRows($report));
        $headings = $rows ? array_keys($rows[0]) : [];
        $values = array_map('array_values', $rows);
        $name = 'accounting-'.$filters['report'].'-'.$report['snapshot_entry_id'];
        $this->recordExport($request, $book, $filters, $report, $format);

        if ($format === 'json') {
            return response()->json([
                'report' => collect($report)->except('rows')->all(),
                'rows' => $rows,
                'not_certified' => true,
            ], 200, ['Content-Disposition' => 'attachment; filename="'.$name.'.json"']);
        }
        if ($format === 'pdf') {
            return Pdf::loadView('pdf.accounting-report', [
                'report' => $report, 'book' => $book, 'exercise' => $exercise,
                'organization' => $context->organization(), 'residence' => $context->residence(),
                'headings' => $headings, 'rows' => $values, 'locale' => app()->getLocale(),
            ])->setPaper('a4', 'landscape')->download($name.'.pdf');
        }

        return Excel::download(
            new AccountingReportExport($values, $headings),
            $name.'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX,
            $format === 'csv' ? ['Content-Type' => 'text/csv; charset=UTF-8'] : []
        );
    }

    private function resolve(Request $request, TenantContext $context): array
    {
        $book = AccountingBook::where('organization_id', $context->organization()->id)
            ->where('residence_id', $context->residence()->id)
            ->when($request->integer('accounting_book_id'), fn ($q, $id) => $q->whereKey($id))
            ->firstOrFail();
        $data = $request->validate([
            'report' => ['nullable', Rule::in(AccountingReportService::TYPES)],
            'financial_exercise_id' => 'nullable|integer',
            'accounting_period_id' => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'accounting_journal_id' => 'nullable|integer',
            'ledger_account_id' => 'nullable|integer',
            'code_from' => 'nullable|string|max:40',
            'code_to' => 'nullable|string|max:40',
            'source_type' => 'nullable|string|max:60',
            'supplier_id' => 'nullable|integer',
            'owner_contact_id' => 'nullable|integer',
            'aging' => ['nullable', Rule::in(['current', '1-30', '31-60', '61-90', '>90'])],
            'as_of' => 'nullable|date',
            'entry_reference' => 'nullable|string|max:255',
            'reversal_state' => ['nullable', Rule::in(['all', 'original', 'reversal'])],
            'hide_zero' => 'nullable|boolean',
            'snapshot_entry_id' => 'nullable|integer|min:0',
        ]);
        $exercise = FinancialExercise::where('organization_id', $book->organization_id)
            ->where('residence_id', $book->residence_id)->where('accounting_book_id', $book->id)
            ->when($data['financial_exercise_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderByDesc('starts_on')->firstOrFail();
        if (isset($data['date_from'])) {
            abort_unless($data['date_from'] >= $exercise->starts_on->toDateString(), 422);
        }
        if (isset($data['date_to'])) {
            abort_unless($data['date_to'] <= $exercise->ends_on->toDateString(), 422);
        }
        if (isset($data['accounting_journal_id'])) {
            abort_unless($book->journals()->whereKey($data['accounting_journal_id'])->exists(), 404);
        }
        if (isset($data['ledger_account_id'])) {
            abort_unless($book->accounts()->whereKey($data['ledger_account_id'])->exists(), 404);
        }
        if (isset($data['accounting_period_id'])) {
            $period = $exercise->accountingPeriods()->whereKey($data['accounting_period_id'])->firstOrFail();
            $data['date_from'] = $period->starts_on->toDateString();
            $data['date_to'] = $period->ends_on->toDateString();
        }
        $data['report'] ??= 'journal';
        if ($data['report'] === 'account-ledger' && empty($data['ledger_account_id'])) {
            $data['ledger_account_id'] = $book->accounts()->orderBy('code')->value('id');
        }
        $data['financial_exercise_id'] = $exercise->id;
        $data['accounting_book_id'] = $book->id;
        $data['hide_zero'] = (bool) ($data['hide_zero'] ?? false);

        return [$book, $exercise, $data];
    }

    private function authorizeReport(Request $request, string $report): void
    {
        abort_unless($request->user()->canInOrganization(self::PERMISSIONS[$report], app(TenantContext::class)->organization()), 403);
    }

    private function flatRows(array $rows): array
    {
        return array_map(function (array $row) {
            return collect($row)->map(function ($value, $key) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (is_string($value) && preg_match('/^[=+@-]/u', $value)) {
                    $value = "'".$value;
                }
                if (in_array($key, ['code', 'account_code'], true) && $value !== null) {
                    $value = "'".ltrim((string) $value, "'");
                }

                return $value;
            })->all();
        }, $rows);
    }

    private function recordExport(Request $request, AccountingBook $book, array $filters, array $report, string $format): void
    {
        AccountingActivityEvent::create([
            'organization_id' => $book->organization_id, 'residence_id' => $book->residence_id,
            'record_type' => AccountingBook::class, 'record_id' => $book->id, 'action' => 'report_exported',
            'actor_id' => $request->user()->id, 'reason' => null, 'before_evidence' => null,
            'after_evidence' => ['report' => $filters['report'], 'format' => $format, 'filters' => $filters, 'snapshot_entry_id' => $report['snapshot_entry_id'], 'row_count' => count($report['rows'])],
            'context' => 'http', 'occurred_at' => now(),
        ]);
    }
}
