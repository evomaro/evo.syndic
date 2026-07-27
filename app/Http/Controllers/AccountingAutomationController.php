<?php

namespace App\Http\Controllers;

use App\Models\AccountingBook;
use App\Models\AccountingOpeningBatch;
use App\Models\AccountingPostingRule;
use App\Models\AccountingSourceMapping;
use App\Services\AccountingAutomationService;
use App\Services\AccountingPostingConfigurationService;
use App\Services\OpeningBalanceService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingAutomationController extends Controller
{
    public function reviewConfiguration(Request $request, TenantContext $context)
    {
        $book = $this->book($context);
        abort_if($book->automation?->status === 'active', 409);
        $book->update(['review_status' => 'approved', 'confirmed_by' => $request->user()->id, 'confirmed_at' => now()]);
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $book->organization_id,
            'residence_id' => $book->residence_id,
            'record_type' => AccountingBook::class,
            'record_id' => $book->id,
            'action' => 'book_professionally_reviewed',
            'actor_id' => $request->user()->id,
            'context' => 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', __('Configuration comptable marquée comme revue.'));
    }

    public function storeRule(Request $request, TenantContext $context)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'stable_code' => 'required|string|max:80',
            'version' => 'required|string|max:40',
            'source_domain' => ['required', Rule::in(['fund_call', 'payment', 'payment_allocation', 'supplier_invoice', 'supplier_credit_note', 'supplier_settlement'])],
            'source_event' => 'required|string|max:60',
            'accounting_journal_id' => 'required|integer',
            'debit_resolution' => ['required', Rule::in(AccountingPostingConfigurationService::RESOLUTIONS)],
            'debit_ledger_account_id' => 'nullable|integer',
            'credit_resolution' => ['required', Rule::in(AccountingPostingConfigurationService::RESOLUTIONS)],
            'credit_ledger_account_id' => 'nullable|integer',
            'effective_from' => 'required|date',
            'source_notes' => 'nullable|string|max:4000',
        ]);
        $journal = $book->journals()->whereKey($data['accounting_journal_id'])->firstOrFail();
        foreach (['debit_ledger_account_id', 'credit_ledger_account_id'] as $field) {
            if (! empty($data[$field])) {
                $book->accounts()->whereKey($data[$field])->firstOrFail();
            }
        }
        $rule = AccountingPostingRule::create($data + [
            'organization_id' => $book->organization_id,
            'residence_id' => $book->residence_id,
            'accounting_book_id' => $book->id,
            'accounting_framework_id' => $book->accounting_framework_id,
            'accounting_journal_id' => $journal->id,
            'status' => 'draft',
            'professional_review_status' => 'pending_professional_review',
            'created_by' => $request->user()->id,
        ]);
        $this->activity($book, AccountingPostingRule::class, $rule->id, 'posting_rule_draft_created', $request, [
            'stable_code' => $rule->stable_code,
            'version' => $rule->version,
            'source_domain' => $rule->source_domain,
            'source_event' => $rule->source_event,
        ]);

        return back()->with('success', __('Règle comptable créée comme brouillon non actif.'));
    }

    public function reviewRule(Request $request, AccountingPostingRule $rule, TenantContext $context)
    {
        $this->tenant($rule, $context);
        abort_unless($rule->status === 'draft', 409);
        $rule->update([
            'professional_review_status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $this->activity($this->book($context), AccountingPostingRule::class, $rule->id, 'posting_rule_reviewed', $request, [
            'stable_code' => $rule->stable_code,
            'version' => $rule->version,
        ]);

        return back()->with('success', __('Règle marquée comme revue.'));
    }

    public function activateRule(Request $request, AccountingPostingRule $rule, TenantContext $context, AccountingPostingConfigurationService $service)
    {
        $this->tenant($rule, $context);
        $service->activateRule($rule, $request->user());

        return back()->with('success', __('Version de règle activée.'));
    }

    public function storeMapping(Request $request, TenantContext $context, AccountingPostingConfigurationService $service)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'mapping_type' => ['required', Rule::in(['financial_account', 'expense_category', 'charge_category', 'receivable_control', 'advance_control', 'supplier_payable'])],
            'source_id' => 'required|integer|min:0',
            'ledger_account_id' => 'required|integer',
            'effective_from' => 'required|date',
        ]);
        $account = $book->accounts()->whereKey($data['ledger_account_id'])->firstOrFail();
        $mapping = $service->map($book, $data['mapping_type'], $data['source_id'], $account, $data['effective_from'], $request->user());
        $this->activity($book, AccountingSourceMapping::class, $mapping->id, 'source_mapping_draft_saved', $request, [
            'mapping_type' => $mapping->mapping_type,
            'source_id' => $mapping->source_id,
            'ledger_account_id' => $mapping->ledger_account_id,
        ]);

        return back()->with('success', __('Correspondance enregistrée en attente de revue.'));
    }

    public function reviewMapping(Request $request, AccountingSourceMapping $mapping, TenantContext $context, AccountingPostingConfigurationService $service)
    {
        $this->tenant($mapping, $context);
        $mapping = $service->reviewMapping($mapping, $request->user());
        $this->activity($this->book($context), AccountingSourceMapping::class, $mapping->id, 'source_mapping_reviewed', $request, [
            'mapping_type' => $mapping->mapping_type,
            'source_id' => $mapping->source_id,
            'ledger_account_id' => $mapping->ledger_account_id,
        ]);

        return back()->with('success', __('Correspondance comptable revue.'));
    }

    public function activate(Request $request, TenantContext $context, AccountingAutomationService $service)
    {
        $book = $this->book($context);
        $data = $request->validate(['effective_from' => 'required|date']);
        $service->activate($book, $data['effective_from'], $request->user());

        return back()->with('success', __('Automatisation comptable activée prospectivement.'));
    }

    public function storeOpening(Request $request, TenantContext $context)
    {
        $book = $this->book($context);
        $data = $request->validate([
            'financial_exercise_id' => 'required|integer',
            'accounting_journal_id' => 'required|integer',
            'opening_date' => 'required|date',
            'reference' => 'required|string|max:80',
            'notes' => 'nullable|string|max:4000',
            'supporting_document_reference' => 'required|string|max:255',
            'lines' => 'required|array|min:2',
            'lines.*.ledger_account_id' => 'required|integer',
            'lines.*.label' => 'required|string|max:255',
            'lines.*.debit_minor' => 'required|integer|min:0',
            'lines.*.credit_minor' => 'required|integer|min:0',
        ]);
        $exercise = $context->residence()->financialExercises()->where('accounting_book_id', $book->id)->whereKey($data['financial_exercise_id'])->firstOrFail();
        $journal = $book->journals()->where('type', 'opening')->whereKey($data['accounting_journal_id'])->firstOrFail();

        $batch = DB::transaction(function () use ($data, $book, $exercise, $journal, $request) {
            $batch = AccountingOpeningBatch::create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_book_id' => $book->id,
                'financial_exercise_id' => $exercise->id,
                'accounting_journal_id' => $journal->id,
                'opening_date' => $data['opening_date'],
                'reference' => $data['reference'],
                'notes' => $data['notes'] ?? null,
                'supporting_document_reference' => $data['supporting_document_reference'],
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            foreach ($data['lines'] as $index => $line) {
                $account = $book->accounts()->whereKey($line['ledger_account_id'])->firstOrFail();
                $batch->lines()->create($line + ['sequence' => $index + 1, 'ledger_account_id' => $account->id]);
            }

            return $batch;
        });
        $this->activity($book, AccountingOpeningBatch::class, $batch->id, 'opening_balance_draft_created', $request, [
            'financial_exercise_id' => $batch->financial_exercise_id,
            'opening_date' => $batch->opening_date->toDateString(),
            'line_count' => $batch->lines()->count(),
        ]);

        return back()->with('success', __('Brouillon de soldes d’ouverture créé.'));
    }

    public function reviewOpening(Request $request, AccountingOpeningBatch $batch, TenantContext $context, OpeningBalanceService $service)
    {
        $this->tenant($batch, $context);
        $service->review($batch, $request->user());

        return back()->with('success', __('Soldes d’ouverture revus.'));
    }

    public function postOpening(Request $request, AccountingOpeningBatch $batch, TenantContext $context, OpeningBalanceService $service)
    {
        $this->tenant($batch, $context);
        $service->post($batch, $request->user());

        return back()->with('success', __('Soldes d’ouverture comptabilisés.'));
    }

    private function book(TenantContext $context): AccountingBook
    {
        return AccountingBook::where('organization_id', $context->organization()->id)
            ->where('residence_id', $context->residence()->id)
            ->firstOrFail();
    }

    private function tenant($model, TenantContext $context): void
    {
        abort_unless((int) $model->organization_id === (int) $context->organization()->id
            && (int) $model->residence_id === (int) $context->residence()->id, 404);
    }

    private function activity(AccountingBook $book, string $recordType, int $recordId, string $action, Request $request, array $evidence): void
    {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $book->organization_id,
            'residence_id' => $book->residence_id,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'action' => $action,
            'actor_id' => $request->user()->id,
            'after_evidence' => json_encode($evidence),
            'context' => 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
