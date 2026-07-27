<?php

namespace App\Http\Controllers;

use App\Models\AccountingBook;
use App\Models\AccountingFramework;
use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBatch;
use App\Models\AccountingPeriod;
use App\Models\AccountingPostingRule;
use App\Models\AccountingSourceMapping;
use App\Models\AccountingSourcePosting;
use App\Models\ChargeCategory;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\AccountingAutomationService;
use App\Services\AccountingConfigurationService;
use App\Services\AccountingPostingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AccountingController extends Controller
{
    public function index(TenantContext $context, AccountingAutomationService $automation)
    {
        $residence = $context->residence();
        $book = AccountingBook::with('framework')->where('organization_id', $context->organization()->id)->where('residence_id', $residence->id)->first();

        return Inertia::render('Accounting/Index', [
            'book' => $book,
            'frameworks' => AccountingFramework::where('status', 'active')->get(),
            'accounts' => $book?->accounts()->orderBy('code')->get() ?? [],
            'journals' => $book?->journals()->orderBy('code')->get() ?? [],
            'regimeAssessments' => $book?->regimeAssessments()->latest('assessed_at')->get() ?? [],
            'exercises' => $residence->financialExercises()->with('accountingPeriods')->latest('starts_on')->get(),
            'entries' => $book ? JournalEntry::where('accounting_book_id', $book->id)->with('lines')->latest('entry_date')->paginate(25) : null,
            'activity' => $book ? DB::table('accounting_activity_events')->where('organization_id', $book->organization_id)->where('residence_id', $book->residence_id)->latest('occurred_at')->limit(50)->get() : [],
            'automation' => $book?->automation,
            'automationReadiness' => $book ? $automation->readiness($book, today()->toDateString()) : null,
            'postingRules' => $book ? AccountingPostingRule::where('accounting_book_id', $book->id)->with('journal')->orderBy('source_domain')->orderBy('source_event')->get() : [],
            'sourceMappings' => $book ? AccountingSourceMapping::where('accounting_book_id', $book->id)->with('account')->orderBy('mapping_type')->get() : [],
            'sourcePostings' => $book ? AccountingSourcePosting::where('accounting_book_id', $book->id)->with('entry')->latest()->limit(50)->get() : [],
            'openingBatches' => $book ? AccountingOpeningBatch::where('accounting_book_id', $book->id)->with('lines')->latest()->get() : [],
            'mappingSources' => [
                'financial_accounts' => FinancialAccount::where('residence_id', $residence->id)->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
                'expense_categories' => ExpenseCategory::where('residence_id', $residence->id)->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
                'charge_categories' => ChargeCategory::where('residence_id', $residence->id)->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            ],
        ]);
    }

    public function recordRegimeAssessment(Request $request, TenantContext $context, AccountingConfigurationService $service)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'financial_exercise_id' => 'nullable|integer',
            'recommended_regime' => ['required', Rule::in(['full', 'simplified', 'minimal'])],
            'inputs' => 'required|array|min:1',
            'reason_codes' => 'required|array|min:1',
            'reason_codes.*' => 'string|max:100',
            'rule_version' => 'required|string|max:50',
            'explanation_fr' => 'nullable|array',
            'explanation_ar' => 'nullable|array',
        ]);
        $exercise = isset($data['financial_exercise_id'])
            ? FinancialExercise::where('organization_id', $book->organization_id)
                ->where('residence_id', $book->residence_id)
                ->findOrFail($data['financial_exercise_id'])
            : null;
        $service->recordRegimeAssessment($book, $exercise, $data, $request->user());

        return back()->with('success', __('Évaluation enregistrée comme proposition en attente de revue professionnelle.'));
    }

    public function createFrameworkSuccessor(Request $request, AccountingFramework $framework, AccountingConfigurationService $service)
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:30', Rule::unique('accounting_frameworks')->where('stable_code', $framework->stable_code)],
            'name_fr' => 'nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:3000',
            'official_title' => 'nullable|string|max:255',
            'issuing_authority' => 'nullable|string|max:255',
            'publication_reference' => 'nullable|string|max:255',
            'publication_date' => 'nullable|date',
            'effective_date' => 'nullable|date',
            'source_url' => 'nullable|url|max:1000',
            'import_notes' => 'nullable|string|max:3000',
        ]);
        $service->createFrameworkSuccessor($framework, $data, $request->user());

        return back()->with('success', __('Successeur créé comme brouillon non publié et non approuvé.'));
    }

    public function storeSubaccount(Request $request, TenantContext $context, AccountingConfigurationService $service)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'parent_id' => 'required|integer',
            'code' => ['required', 'string', 'max:40', Rule::unique('ledger_accounts')->where('accounting_book_id', $book->id)],
            'label_fr' => 'required|string|max:255',
            'label_ar' => 'nullable|string|max:255',
            'normal_balance' => ['nullable', Rule::in(['debit', 'credit'])],
            'account_class' => 'nullable|string|max:20',
            'posting_allowed' => 'nullable|boolean',
            'reconciliation_required' => 'nullable|boolean',
            'effective_from' => 'nullable|date',
        ]);
        $service->createSubaccount($book, $data, $request->user());

        return back()->with('success', __('Sous-compte locataire créé.'));
    }

    public function updateSubaccount(Request $request, LedgerAccount $account, TenantContext $context, AccountingConfigurationService $service)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'parent_id' => 'sometimes|integer',
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('ledger_accounts')->where('accounting_book_id', $book->id)->ignore($account->id)],
            'label_fr' => 'sometimes|string|max:255',
            'label_ar' => 'nullable|string|max:255',
            'posting_allowed' => 'sometimes|boolean',
            'reconciliation_required' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
            'effective_to' => 'nullable|date',
        ]);
        $service->updateSubaccount($account, $book, $data, $request->user());

        return back()->with('success', __('Sous-compte locataire mis à jour.'));
    }

    public function storeJournal(Request $request, TenantContext $context, AccountingConfigurationService $service)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounting_journals')->where('accounting_book_id', $book->id)],
            'label_fr' => 'required|string|max:255',
            'label_ar' => 'nullable|string|max:255',
            'type' => ['required', Rule::in(['general', 'bank', 'cash', 'collections', 'purchases', 'opening', 'closing'])],
            'effective_from' => 'nullable|date',
        ]);
        $service->createJournal($book, $data, $request->user());

        return back()->with('success', __('Journal créé.'));
    }

    public function updateJournal(Request $request, AccountingJournal $journal, TenantContext $context, AccountingConfigurationService $service)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('accounting_journals')->where('accounting_book_id', $book->id)->ignore($journal->id)],
            'label_fr' => 'sometimes|string|max:255',
            'label_ar' => 'nullable|string|max:255',
            'type' => ['sometimes', Rule::in(['general', 'bank', 'cash', 'collections', 'purchases', 'opening', 'closing'])],
            'active' => 'sometimes|boolean',
            'effective_to' => 'nullable|date',
        ]);
        $service->updateJournal($journal, $book, $data, $request->user());

        return back()->with('success', __('Journal mis à jour.'));
    }

    public function adopt(Request $request, TenantContext $context, AccountingConfigurationService $service)
    {
        $data = $request->validate([
            'accounting_framework_id' => 'required|integer|exists:accounting_frameworks,id',
            'selected_regime' => ['required', Rule::in(['full', 'simplified', 'minimal'])],
            'effective_date' => 'required|date',
        ]);
        abort_if(AccountingBook::where('residence_id', $context->residence()->id)->exists(), 409);
        $service->adopt($context->organization()->id, $context->residence()->id, AccountingFramework::findOrFail($data['accounting_framework_id']), $data['selected_regime'], $data['effective_date'], $request->user());

        return back()->with('success', __('Référentiel comptable adopté.'));
    }

    public function configureExercise(Request $request, FinancialExercise $exercise, TenantContext $context, AccountingConfigurationService $service)
    {
        $this->tenant($exercise, $context);
        abort_unless($exercise->status === 'draft' || $exercise->status === 'open', 409);
        $book = AccountingBook::where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->firstOrFail();
        abort_if($exercise->accountingPeriods()->exists(), 409);
        $service->configureExercise($exercise, $book, $request->user());

        return back()->with('success', __('Périodes comptables générées.'));
    }

    public function storeEntry(Request $request, TenantContext $context)
    {
        $book = AccountingBook::where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->firstOrFail();
        $data = $request->validate([
            'financial_exercise_id' => 'required|integer', 'accounting_period_id' => 'required|integer',
            'accounting_journal_id' => 'required|integer', 'entry_date' => 'required|date',
            'reference' => 'nullable|string|max:255', 'description_fr' => 'required|string|max:2000', 'description_ar' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:2', 'lines.*.ledger_account_id' => 'required|integer',
            'lines.*.label' => 'required|string|max:255', 'lines.*.debit_minor' => 'required|integer|min:0', 'lines.*.credit_minor' => 'required|integer|min:0',
        ]);
        $exercise = FinancialExercise::where('organization_id', $book->organization_id)->where('residence_id', $book->residence_id)->whereKey($data['financial_exercise_id'])->firstOrFail();
        $period = AccountingPeriod::where('organization_id', $book->organization_id)->where('residence_id', $book->residence_id)->where('financial_exercise_id', $exercise->id)->whereKey($data['accounting_period_id'])->firstOrFail();
        $journal = $book->journals()->whereKey($data['accounting_journal_id'])->firstOrFail();
        $entry = DB::transaction(function () use ($data, $book, $exercise, $period, $journal, $request) {
            $entry = JournalEntry::create([
                'organization_id' => $book->organization_id, 'residence_id' => $book->residence_id, 'accounting_book_id' => $book->id,
                'financial_exercise_id' => $exercise->id, 'accounting_period_id' => $period->id, 'accounting_journal_id' => $journal->id,
                'entry_date' => $data['entry_date'], 'reference' => $data['reference'] ?? null, 'description_fr' => $data['description_fr'],
                'description_ar' => $data['description_ar'] ?? null, 'status' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            foreach ($data['lines'] as $i => $line) {
                $account = $book->accounts()->whereKey($line['ledger_account_id'])->firstOrFail();
                $entry->lines()->create($line + ['ledger_account_id' => $account->id, 'sequence' => $i + 1]);
            }

            return $entry;
        });

        return redirect()->route('accounting.entries.show', $entry);
    }

    public function showEntry(JournalEntry $entry, TenantContext $context)
    {
        $this->tenant($entry, $context);

        return Inertia::render('Accounting/Show', ['entry' => $entry->load(['lines.account', 'journal', 'period'])]);
    }

    public function post(Request $request, JournalEntry $entry, TenantContext $context, AccountingPostingService $service)
    {
        $this->tenant($entry, $context);
        $service->post($entry, $request->user());

        return back()->with('success', __('Écriture comptabilisée. Elle est désormais immuable.'));
    }

    public function reverse(Request $request, JournalEntry $entry, TenantContext $context, AccountingPostingService $service)
    {
        $this->tenant($entry, $context);
        $data = $request->validate(['accounting_period_id' => 'required|integer', 'reason' => 'required|string|max:2000']);
        $period = AccountingPeriod::where('organization_id', $context->organization()->id)->where('residence_id', $context->residence()->id)->findOrFail($data['accounting_period_id']);
        $reversal = $service->reverse($entry, $period, $request->user(), $data['reason']);

        return redirect()->route('accounting.entries.show', $reversal);
    }

    public function lockPeriod(Request $request, AccountingPeriod $period, TenantContext $context, AccountingConfigurationService $service)
    {
        $this->tenant($period, $context);
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $service->lock($period, $request->user(), $data['reason']);

        return back()->with('success', __('Période verrouillée.'));
    }

    public function reopenPeriod(Request $request, AccountingPeriod $period, TenantContext $context, AccountingConfigurationService $service)
    {
        $this->tenant($period, $context);
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $service->reopen($period, $request->user(), $data['reason']);

        return back()->with('success', __('Période rouverte.'));
    }

    private function tenant($model, TenantContext $context): void
    {
        abort_unless($model->organization_id === $context->organization()->id && $model->residence_id === $context->residence()->id, 404);
    }

    private function book(TenantContext $context): AccountingBook
    {
        return AccountingBook::where('organization_id', $context->organization()->id)
            ->where('residence_id', $context->residence()->id)
            ->firstOrFail();
    }
}
