<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\FundCallLine;
use App\Models\Lot;
use App\Models\LotCharge;
use App\Models\Payment;
use App\Models\Residence;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use App\Rules\ValidMoney;
use App\Services\EssentialFinanceService;
use App\Services\ExperienceCapabilities;
use App\Services\FinancialTransferService;
use App\Services\FundCallDistributionService;
use App\Services\FundCallWorkflow;
use App\Services\PaymentWorkflow;
use App\Services\SupplierInvoiceDraftService;
use App\Services\SupplierInvoiceWorkflow;
use App\Services\SupplierSettlementWorkflow;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EssentialController extends Controller
{
    public function dashboard(Request $request, TenantContext $context, EssentialFinanceService $finance)
    {
        $residence = $this->residence($request, $context);
        $range = $finance->periodRange(
            $request->string('from_period')->toString(),
            $request->string('to_period')->toString(),
            $request->string('period')->toString(),
        );
        if (! $residence) {
            return Inertia::render('Essential/Dashboard', ['range' => $range, 'summary' => $this->emptySummary() + ['balance_as_of' => min($range['end'], today()->toDateString())], 'recentExpenses' => [], 'residences' => $this->residences($request, $context), 'activeResidenceId' => null, 'canGenerateCotisation' => false]);
        }
        $charges = $finance->chargesInRange($residence, $range)->get();
        $expected = (int) $charges->sum('amount_cents');
        $paid = (int) $charges->sum(fn ($charge) => min((int) $charge->amount_cents, (int) $charge->paid_cents));
        $balanceAsOf = min($range['end'], today()->toDateString());
        $balances = $finance->balances($residence, $balanceAsOf);
        $recent = SupplierInvoice::query()->where('organization_id', $residence->organization_id)->where('primary_residence_id', $residence->id)
            ->whereIn('status', ['validated', 'partial', 'paid'])->with('supplier:id,legal_name')->latest('invoice_date')->limit(5)->get()
            ->map(fn ($invoice) => ['id' => $invoice->id, 'date' => $invoice->invoice_date?->toDateString(), 'description' => $invoice->supplier?->legal_name ?: $invoice->number, 'amount_cents' => (int) $invoice->total_cents]);

        return Inertia::render('Essential/Dashboard', [
            'range' => $range,
            'summary' => ['expected_cents' => $expected, 'collected_cents' => $paid, 'remaining_cents' => max(0, $expected - $paid), 'bank_cents' => $balances['bank'], 'cash_cents' => $balances['cash'], 'balance_as_of' => $balanceAsOf],
            'recentExpenses' => $recent, 'residences' => $this->residences($request, $context), 'activeResidenceId' => $residence->id,
            'canGenerateCotisation' => app(ExperienceCapabilities::class)->allows($request->user(), $context->organization(), 'cotisations.generate'),
        ]);
    }

    public function cotisations(Request $request, TenantContext $context, EssentialFinanceService $finance)
    {
        $residence = $this->residence($request, $context);
        $period = $finance->period($request->string('period')->toString());
        $rows = $residence
            ? $finance->charges($residence, $request)->orderBy('lot_id')->paginate(20)->withQueryString()->through(fn ($charge) => $finance->chargeRow($charge))
            : null;
        $history = $residence?->fundCalls()->whereIn('status', ['validated', 'closed', 'cancelled'])->latest('issue_date')->limit(12)->get()
            ->map(function (FundCall $call) {
                $hasPayments = DB::table('payment_allocations')->join('lot_charges', 'lot_charges.id', '=', 'payment_allocations.lot_charge_id')
                    ->where('lot_charges.fund_call_id', $call->id)->whereNull('payment_allocations.reversed_at')->exists();
                $exerciseOpen = $call->exercise()->where('status', 'open')->exists();

                return [
                    'id' => $call->id,
                    'period' => $call->issue_date?->format('Y-m'),
                    'total_cents' => (int) $call->total_cents,
                    'created_at' => $call->created_at?->toDateString(),
                    'status' => $call->status,
                    'can_cancel' => in_array($call->status, ['validated', 'closed'], true) && ! $hasPayments && $exerciseOpen,
                    'cancel_blocked_reason' => $hasPayments
                        ? __('Impossible d’annuler : des paiements ont déjà été enregistrés.')
                        : (! $exerciseOpen && $call->status !== 'cancelled' ? __('Impossible d’annuler : la période financière est clôturée.') : null),
                ];
            })->values() ?? collect();
        $defaultAllocationKey = $residence?->allocationKeys()->where('active', true)->orderByDesc('is_default')->first(['id', 'name']);
        $defaultCategory = $residence?->chargeCategories()->where('active', true)->orderByRaw("CASE WHEN type = 'ordinary' THEN 0 ELSE 1 END")->first(['id', 'name']);
        $latestTotal = (int) ($residence?->fundCalls()->whereIn('status', ['validated', 'closed'])->latest('issue_date')->value('total_cents') ?? 0);
        $organization = $context->organization();

        return Inertia::render('Essential/Cotisations', [
            'cotisations' => $rows ?: ['data' => [], 'links' => [], 'total' => 0],
            'periodHasCharges' => $residence
                ? $finance->charges($residence, Request::create('', 'GET', ['period' => $period['value']]))->exists()
                : false,
            'filters' => $request->only(['residence_id', 'building_id', 'period', 'status']), 'period' => $period,
            'residences' => $this->residences($request, $context), 'activeResidenceId' => $residence?->id,
            'buildings' => $residence?->buildings()->where('active', true)->orderBy('name')->get(['id', 'name']) ?? [],
            'accounts' => $residence?->financialAccounts()->where('active', true)->whereIn('type', ['bank', 'cash'])->get(['id', 'name', 'type']) ?? [],
            'exercises' => $residence?->financialExercises()->where('status', 'open')->get(['id', 'name', 'starts_on', 'ends_on']) ?? [],
            'generation' => [
                'can_generate' => app(ExperienceCapabilities::class)->allows($request->user(), $organization, 'cotisations.generate'),
                'can_cancel' => app(ExperienceCapabilities::class)->allows($request->user(), $organization, 'cotisations.cancel'),
                'default_allocation_key' => $defaultAllocationKey,
                'default_category' => $defaultCategory,
                'latest_total_cents' => $latestTotal,
                'open' => $request->boolean('generate'),
            ],
            'canManageLots' => $request->user()->canInOrganization('manage_property_structure', $organization),
            'issuedCotisations' => $history,
        ]);
    }

    public function previewCotisation(Request $request, TenantContext $context, FundCallDistributionService $distribution)
    {
        $organization = $context->organization();
        abort_unless(app(ExperienceCapabilities::class)->allows($request->user(), $organization, 'cotisations.generate'), 403);
        $data = $this->validatedEssentialCotisation($request);
        [$residence, $exercise, $category, $allocationKey] = $this->essentialCotisationContext($request, $context, $data);
        $line = $this->essentialCotisationLine($residence, $exercise, $category->id, $allocationKey?->id, $data);
        $rows = collect($distribution->distribute($line));

        return response()->json([
            'period' => $data['period'],
            'total_cents' => (int) $rows->sum('amount_cents'),
            'allocations' => $rows->map(fn ($row) => [
                'lot_id' => $row['lot']->id,
                'lot' => $row['lot']->reference,
                'amount_cents' => (int) $row['amount_cents'],
            ])->values(),
        ]);
    }

    public function generateCotisation(Request $request, TenantContext $context, FundCallWorkflow $workflow)
    {
        $organization = $context->organization();
        abort_unless(app(ExperienceCapabilities::class)->allows($request->user(), $organization, 'cotisations.generate'), 403);
        $data = $this->validatedEssentialCotisation($request);
        [$residence, $exercise, $category, $allocationKey] = $this->essentialCotisationContext($request, $context, $data);
        $issueDate = CarbonImmutable::createFromFormat('Y-m-d', $data['period'].'-01');

        DB::transaction(function () use ($residence, $exercise, $category, $allocationKey, $data, $issueDate, $workflow, $request) {
            Residence::query()->whereKey($residence->id)->lockForUpdate()->firstOrFail();
            $alreadyExists = FundCall::query()->where('residence_id', $residence->id)
                ->where('status', '!=', 'cancelled')
                ->whereBetween('issue_date', [$issueDate->startOfMonth()->toDateString(), $issueDate->endOfMonth()->toDateString()])
                ->exists();
            if ($alreadyExists) {
                throw ValidationException::withMessages(['period' => __('Une cotisation existe déjà pour ce mois.')]);
            }

            $call = FundCall::create([
                'organization_id' => $residence->organization_id,
                'residence_id' => $residence->id,
                'financial_exercise_id' => $exercise->id,
                'title' => 'Cotisation '.$data['period'],
                'description' => __('Cotisation générée depuis le mode Essential.'),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->addDays(15)->toDateString(),
                'metadata' => ['source' => 'essential'],
            ]);
            $call->lines()->create([
                'charge_category_id' => $category->id,
                'label' => 'Cotisation '.$data['period'],
                'distribution_method' => $data['distribution_method'],
                'allocation_key_id' => $allocationKey?->id,
                'target_type' => 'all',
                'target_ids' => [],
                'amount_cents' => Money::cents($data['amount']),
                'sort_order' => 0,
            ]);
            $workflow->validate($call, $request->user());
        }, 5);

        return to_route('essential.cotisations', ['period' => $data['period'], 'residence_id' => $residence->id])
            ->with('success', __('Cotisation générée.'));
    }

    public function cancelCotisation(Request $request, FundCall $fundCall, TenantContext $context, FundCallWorkflow $workflow)
    {
        $organization = $context->organization();
        abort_unless(app(ExperienceCapabilities::class)->allows($request->user(), $organization, 'cotisations.cancel'), 403);
        abort_unless($fundCall->organization_id === $organization->id && $fundCall->residence_id === $context->residence()->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $workflow->cancel($fundCall, $request->user(), $data['reason']);

        return back()->with('success', __('Cotisation annulée.'));
    }

    public function previewPayment(Request $request, TenantContext $context)
    {
        $organization = $context->organization();
        abort_unless(app(ExperienceCapabilities::class)->allows($request->user(), $organization, 'payments.record'), 403);
        $data = $request->validate([
            'lot_id' => ['required', 'integer'],
            'allocation_mode' => ['required', Rule::in(['fifo', 'range'])],
            'amount' => ['nullable', 'required_if:allocation_mode,fifo', new ValidMoney],
            'from_period' => ['nullable', 'required_if:allocation_mode,range', 'date_format:Y-m'],
            'to_period' => ['nullable', 'required_if:allocation_mode,range', 'date_format:Y-m'],
        ]);
        $residence = $this->residence($request, $context);
        abort_unless($residence, 404);

        return response()->json($this->essentialPaymentPlan($residence, $data));
    }

    public function payment(Request $request, TenantContext $context, PaymentWorkflow $workflow)
    {
        $organization = $context->organization();
        abort_unless($request->user()->canInOrganization('create_payments', $organization) && $request->user()->canInOrganization('validate_payments', $organization), 403);
        $data = $request->validate([
            'lot_charge_id' => ['nullable', 'required_without:lot_id', 'integer'], 'lot_id' => ['nullable', 'required_without:lot_charge_id', 'integer'],
            'allocation_mode' => ['nullable', Rule::in(['single', 'fifo', 'range'])],
            'from_period' => ['nullable', 'required_if:allocation_mode,range', 'date_format:Y-m'],
            'to_period' => ['nullable', 'required_if:allocation_mode,range', 'date_format:Y-m'],
            'financial_exercise_id' => ['required', 'integer'],
            'financial_account_id' => ['required', 'integer'], 'payment_date' => ['required', 'date'],
            'amount' => ['required', new ValidMoney], 'method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'other'])],
            'bank_reference' => ['nullable', 'string', 'max:120'], 'cheque_number' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $charge = isset($data['lot_charge_id']) ? LotCharge::query()->whereKey($data['lot_charge_id'])->where('organization_id', $organization->id)->whereNull('cancelled_at')->with(['lot', 'billedContact'])->firstOrFail() : null;
        $lot = $charge?->lot ?? Lot::query()->whereKey($data['lot_id'])->whereHas('residence', fn ($query) => $query->where('organization_id', $organization->id))->firstOrFail();
        $this->assertResidenceAccess($request, $organization->id, $lot->residence_id);
        $residence = Residence::query()->whereKey($lot->residence_id)->where('organization_id', $organization->id)->firstOrFail();
        $existing = Payment::query()->where('residence_id', $residence->id)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing?->status === 'validated') {
            return back()->with('success', __('Paiement déjà enregistré.'));
        }
        $amount = Money::cents($data['amount']);
        $mode = $data['allocation_mode'] ?? 'single';
        if ($mode === 'single') {
            $remaining = $charge?->outstanding_cents ?? 0;
            if ($amount > $remaining) {
                return back()->withErrors(['amount' => __('Le montant dépasse le reste à encaisser (:amount MAD).', ['amount' => Money::formatted($remaining)])]);
            }
            $plan = ['allocations' => [['lot_charge_id' => $charge->id, 'amount_cents' => $amount, 'period' => $charge->issue_date->format('Y-m')]], 'credit_cents' => 0, 'total_cents' => $amount];
        } else {
            $plan = $this->essentialPaymentPlan($residence, $data + ['lot_id' => $lot->id]);
            if ($mode === 'range' && $amount !== (int) $plan['total_cents']) {
                return back()->withErrors(['amount' => __('Le montant a changé. Vérifiez à nouveau la répartition avant de confirmer.')]);
            }
        }
        $seedCharge = $charge ?: LotCharge::query()->where('lot_id', $lot->id)->whereNull('cancelled_at')->latest('issue_date')->with('billedContact')->first();
        $payer = $seedCharge?->billedContact ?: $lot->activeOwnerships($data['payment_date'])->with('contact')->orderByDesc('is_primary_contact')->first()?->contact;
        if (! $payer) {
            throw ValidationException::withMessages([
                'payer' => __('Aucun contact de facturation n’est défini pour ce lot.'),
            ]);
        }
        $account = FinancialAccount::query()->whereKey($data['financial_account_id'])->where('organization_id', $organization->id)->where('residence_id', $residence->id)->where('active', true)->firstOrFail();
        $exercise = FinancialExercise::query()->whereKey($data['financial_exercise_id'])->where('organization_id', $organization->id)->where('residence_id', $residence->id)->where('status', 'open')->firstOrFail();
        $attributes = [
            'organization_id' => $organization->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id,
            'payer_contact_id' => $payer->id, 'received_from' => $payer->display_name,
            'payment_date' => $data['payment_date'], 'amount_cents' => $amount, 'method' => $data['method'], 'financial_account_id' => $account->id,
            'bank_reference' => $data['bank_reference'] ?? null, 'cheque_number' => $data['cheque_number'] ?? null, 'notes' => $data['notes'] ?? null,
            'idempotency_key' => $data['idempotency_key'], 'metadata' => [
                'source' => 'essential',
                'advance_lot_id' => $lot->id,
                'essential_month_count' => collect($plan['allocations'])->pluck('period')->unique()->count(),
            ],
        ];
        DB::transaction(function () use ($attributes, $residence, $data, $workflow, $request, $plan) {
            $payment = Payment::firstOrCreate(['residence_id' => $residence->id, 'idempotency_key' => $data['idempotency_key']], $attributes)->refresh();
            if ($payment->status === 'draft') {
                $manual = collect($plan['allocations'])->map(fn ($row) => ['lot_charge_id' => $row['lot_charge_id'], 'amount_cents' => $row['amount_cents']])->all();
                $workflow->validate($payment, $request->user(), 'manual', [], $manual);
            }
        }, 5);

        return back()->with('success', __('Paiement enregistré.'));
    }

    public function expenses(Request $request, TenantContext $context)
    {
        $residence = $this->residence($request, $context);
        $query = SupplierInvoice::query()->where('organization_id', $context->organization()->id)->whereIn('status', ['validated', 'partial', 'paid'])
            ->when($residence, fn ($q) => $q->where('primary_residence_id', $residence->id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->input('to')))
            ->when($request->integer('category_id'), fn ($q, $id) => $q->whereHas('lines', fn ($lines) => $lines->where('expense_category_id', $id)))
            ->with(['supplier:id,legal_name', 'settlementAllocations.settlement.account:id,name,type'])->latest('invoice_date');

        return Inertia::render('Essential/Expenses', [
            'expenses' => $query->paginate(15)->withQueryString()->through(fn ($invoice) => ['id' => $invoice->id, 'number' => $invoice->number, 'date' => $invoice->invoice_date?->toDateString(), 'description' => $invoice->lines()->value('description'), 'supplier' => $invoice->supplier?->legal_name, 'amount_cents' => (int) $invoice->total_cents, 'account' => $invoice->settlementAllocations->first()?->settlement?->account?->type]),
            'filters' => $request->only(['residence_id', 'from', 'to', 'category_id']), 'residences' => $this->residences($request, $context), 'activeResidenceId' => $residence?->id,
            'categories' => $residence?->expenseCategories()->where('active', true)->orderBy('name')->get(['id', 'name']) ?? [],
            'suppliers' => Supplier::query()->where('organization_id', $context->organization()->id)->where('active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'accounts' => $residence?->financialAccounts()->where('active', true)->whereIn('type', ['bank', 'cash'])->get(['id', 'name', 'type']) ?? [],
            'exercises' => $residence?->financialExercises()->where('status', 'open')->get(['id', 'name', 'starts_on', 'ends_on']) ?? [],
        ]);
    }

    public function storeExpense(Request $request, TenantContext $context, SupplierInvoiceDraftService $drafts, SupplierInvoiceWorkflow $invoices, SupplierSettlementWorkflow $settlements)
    {
        $organization = $context->organization();
        abort_unless($request->user()->canInOrganization('create_expenses', $organization) && $request->user()->canInOrganization('validate_expenses', $organization) && $request->user()->canInOrganization('create_settlements', $organization) && $request->user()->canInOrganization('validate_settlements', $organization), 403);
        $data = $request->validate([
            'residence_id' => ['required', 'integer'], 'financial_exercise_id' => ['required', 'integer'], 'expense_category_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'], 'financial_account_id' => ['required', 'integer'], 'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'], 'amount' => ['required', new ValidMoney], 'method' => ['required', Rule::in(['bank_transfer', 'cheque', 'cash', 'direct_debit'])],
            'supplier_reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'], 'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $this->assertResidenceAccess($request, $organization->id, (int) $data['residence_id']);
        $residence = Residence::query()->whereKey($data['residence_id'])->where('organization_id', $organization->id)->firstOrFail();
        $exercise = FinancialExercise::query()->whereKey($data['financial_exercise_id'])->where('residence_id', $residence->id)->where('status', 'open')->firstOrFail();
        $category = ExpenseCategory::query()->whereKey($data['expense_category_id'])->where('organization_id', $organization->id)->where('residence_id', $residence->id)->where('active', true)->firstOrFail();
        $supplier = Supplier::query()->whereKey($data['supplier_id'])->where('organization_id', $organization->id)->where('active', true)->firstOrFail();
        $account = FinancialAccount::query()->whereKey($data['financial_account_id'])->where('organization_id', $organization->id)->where('residence_id', $residence->id)->where('active', true)->whereIn('type', ['bank', 'cash'])->firstOrFail();
        $amount = Money::cents($data['amount']);
        $stored = null;
        try {
            DB::transaction(function () use ($request, $data, $organization, $residence, $exercise, $category, $supplier, $account, $amount, $drafts, $invoices, $settlements, &$stored) {
                $invoice = $drafts->create([
                    'supplier_id' => $supplier->id, 'supplier_invoice_number' => $data['supplier_reference'] ?? null,
                    'invoice_date' => $data['date'], 'due_date' => $data['date'], 'idempotency_key' => 'essential-exp-'.$data['idempotency_key'], 'notes' => $data['notes'] ?? null,
                    'lines' => [['residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'expense_category_id' => $category->id, 'description' => $data['description'], 'quantity' => '1', 'unit_price_cents' => $amount, 'tax_rate' => '0', 'visibility' => 'invoice_summary']],
                ], $organization, $residence, $request->user())->refresh();
                if ($invoice->status === 'draft' && ! $invoice->attachments()->exists()) {
                    $file = $request->file('receipt');
                    $stored = $file->store("supplier-invoices/{$organization->id}/{$invoice->id}", 'local');
                    $bytes = Storage::disk('local')->get($stored);
                    $invoice->attachments()->create(['kind' => 'original', 'version' => 1, 'name' => basename($file->getClientOriginalName()), 'disk' => 'local', 'path' => $stored, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $request->user()->id]);
                    $invoices->validate($invoice, $request->user());
                }
                $settlement = SupplierSettlement::firstOrCreate(['residence_id' => $residence->id, 'idempotency_key' => 'essential-exp-'.$data['idempotency_key']], ['organization_id' => $organization->id, 'financial_exercise_id' => $exercise->id, 'supplier_id' => $supplier->id, 'financial_account_id' => $account->id, 'settlement_date' => $data['date'], 'amount_cents' => $amount, 'method' => $data['method'], 'notes' => $data['notes'] ?? null])->refresh();
                if ($settlement->status === 'draft') {
                    $settlements->validate($settlement, $request->user(), 'manual', [['supplier_invoice_id' => $invoice->id, 'amount_cents' => $amount]]);
                }
            }, 5);
        } catch (\Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $exception;
        }

        return back()->with('success', __('Dépense enregistrée.'));
    }

    public function accounts(Request $request, TenantContext $context, EssentialFinanceService $finance)
    {
        $residence = $this->residence($request, $context);
        $accounts = $residence?->financialAccounts()->where('active', true)->whereIn('type', ['bank', 'cash'])->get()->map(fn ($account) => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'balance_cents' => $account->current_balance_cents]) ?? collect();
        $movements = FinancialAccountMovement::query()->where('organization_id', $context->organization()->id)
            ->when($residence, fn ($q) => $q->where('residence_id', $residence->id), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($request->integer('account_id'), fn ($q, $id) => $q->where('financial_account_id', $id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_on', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_on', '<=', $request->input('to')))
            ->with('account:id,name,type')->latest('occurred_on')->latest('id')->paginate(25)->withQueryString()
            ->through(fn ($movement) => [
                'id' => $movement->id,
                'date' => $movement->occurred_on?->toDateString(),
                'description' => $movement->description,
                'direction' => $movement->direction,
                'amount_cents' => (int) $movement->amount_cents,
                'kind' => $movement->operational_kind,
                'is_correction' => in_array($movement->operational_kind, ['payment_reversal', 'supplier_settlement_reversal'], true),
                'reversal_of_id' => $movement->reversal_of_id,
                'account' => $movement->account,
            ]);

        return Inertia::render('Essential/Accounts', ['accounts' => $accounts, 'movements' => $movements, 'balances' => $residence ? $finance->balances($residence) : ['bank' => 0, 'cash' => 0], 'filters' => $request->only(['residence_id', 'account_id', 'from', 'to']), 'residences' => $this->residences($request, $context), 'activeResidenceId' => $residence?->id, 'exercises' => $residence?->financialExercises()->where('status', 'open')->get(['id', 'name', 'starts_on', 'ends_on']) ?? []]);
    }

    public function transfer(Request $request, TenantContext $context, FinancialTransferService $service)
    {
        $organization = $context->organization();
        abort_unless(app(ExperienceCapabilities::class)->allows($request->user(), $organization, 'transfers.create'), 403);
        $data = $request->validate(['residence_id' => ['required', 'integer'], 'financial_exercise_id' => ['required', 'integer'], 'source_account_id' => ['required', 'integer'], 'destination_account_id' => ['required', 'integer', 'different:source_account_id'], 'transferred_on' => ['required', 'date'], 'amount' => ['required', new ValidMoney], 'reference' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:64']]);
        $this->assertResidenceAccess($request, $organization->id, (int) $data['residence_id']);
        $service->create(collect($data)->except('amount')->all() + ['amount_cents' => Money::cents($data['amount'])], $organization->id, (int) $data['residence_id'], $request->user());

        return back()->with('success', __('Transfert enregistré.'));
    }

    public function reports(Request $request, TenantContext $context, EssentialFinanceService $finance)
    {
        $residence = $this->residence($request, $context);
        $type = in_array($request->input('type'), ['unpaid', 'collections', 'expenses', 'movements'], true) ? $request->input('type') : 'unpaid';
        $this->applyRelevantReportPeriod($request, $residence, $type);
        $rows = $this->reportRows($request, $residence, $type, $finance);
        $total = (int) collect($rows)->sum('amount_cents');

        return Inertia::render('Essential/Reports', [
            'reportType' => $type,
            'rows' => $rows,
            'totalCents' => $total,
            'periodHasCharges' => $residence
                ? $finance->charges($residence, Request::create('', 'GET', ['period' => $request->input('period')]))->exists()
                : false,
            'filters' => $request->only(['residence_id', 'period', 'type', 'building_id', 'account_id']),
            'period' => $finance->period($request->string('period')->toString()),
            'residences' => $this->residences($request, $context),
            'activeResidenceId' => $residence?->id,
            'buildings' => $residence?->buildings()->where('active', true)->get(['id', 'name']) ?? [],
            'accounts' => $residence?->financialAccounts()->where('active', true)->whereIn('type', ['bank', 'cash'])->get(['id', 'name', 'type']) ?? [],
        ]);
    }

    public function exportReport(Request $request, TenantContext $context, EssentialFinanceService $finance)
    {
        $residence = $this->residence($request, $context);
        $type = in_array($request->input('type'), ['unpaid', 'collections', 'expenses', 'movements'], true) ? $request->input('type') : 'unpaid';
        $this->applyRelevantReportPeriod($request, $residence, $type);
        $rows = $this->reportRows($request, $residence, $type, $finance);
        $period = $finance->period($request->string('period')->toString());

        return response()->streamDownload(function () use ($rows, $context, $residence, $period) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Organisation', $this->csv($context->organization()->name)]);
            fputcsv($handle, ['Résidence', $this->csv($residence?->name ?? '')]);
            fputcsv($handle, ['Période', $period['value']]);
            fputcsv($handle, ['Date', 'Bâtiment', 'Lot / description', 'Résident / compte', 'Montant (MAD)', 'Statut']);
            foreach ($rows as $row) {
                fputcsv($handle, [$this->csv($row['date'] ?? ''), $this->csv($row['building'] ?? ''), $this->csv($row['description'] ?? ''), $this->csv($row['party'] ?? ''), Money::decimal((int) $row['amount_cents']), $this->csv($row['status'] ?? '')]);
            }
            fputcsv($handle, ['', '', '', 'Total', Money::decimal((int) collect($rows)->sum('amount_cents')), '']);
            fclose($handle);
        }, "rapport-{$type}-{$period['value']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function reportRows(Request $request, ?Residence $residence, string $type, EssentialFinanceService $finance): array
    {
        if (! $residence) {
            return [];
        }
        $period = $finance->period($request->string('period')->toString());
        if ($type === 'unpaid') {
            $clone = Request::create('', 'GET', [...$request->query(), 'status' => null]);

            return $finance->charges($residence, $clone)->get()->map($finance->chargeRow(...))->filter(fn ($row) => $row['remaining_cents'] > 0)->map(fn ($row) => ['date' => $row['period'], 'building' => $row['building'], 'description' => $row['lot'], 'party' => $row['resident'], 'amount_cents' => $row['remaining_cents'], 'status' => $row['status']])->values()->all();
        }
        if ($type === 'collections') {
            return Payment::query()->where('organization_id', $residence->organization_id)->where('residence_id', $residence->id)->where('status', 'validated')->whereBetween('payment_date', [$period['start'], $period['end']])->with(['payer', 'account'])->get()->map(fn ($payment) => ['date' => $payment->payment_date?->toDateString(), 'description' => $payment->number, 'party' => $payment->payer?->display_name ?: $payment->received_from, 'amount_cents' => (int) $payment->amount_cents, 'status' => $payment->account?->type])->all();
        }
        if ($type === 'expenses') {
            return SupplierInvoice::query()->where('organization_id', $residence->organization_id)->where('primary_residence_id', $residence->id)->whereIn('status', ['validated', 'partial', 'paid'])->whereBetween('invoice_date', [$period['start'], $period['end']])->with('supplier')->get()->map(fn ($invoice) => ['date' => $invoice->invoice_date?->toDateString(), 'description' => $invoice->number, 'party' => $invoice->supplier?->legal_name, 'amount_cents' => (int) $invoice->total_cents, 'status' => $invoice->status])->all();
        }

        return FinancialAccountMovement::query()->where('organization_id', $residence->organization_id)->where('residence_id', $residence->id)->whereBetween('occurred_on', [$period['start'], $period['end']])->when($request->integer('account_id'), fn ($q, $id) => $q->where('financial_account_id', $id))->with('account')->get()->map(fn ($movement) => ['date' => $movement->occurred_on?->toDateString(), 'description' => $movement->description, 'party' => $movement->account?->name, 'amount_cents' => $movement->direction === 'debit' ? -(int) $movement->amount_cents : (int) $movement->amount_cents, 'status' => $movement->direction])->all();
    }

    private function validatedEssentialCotisation(Request $request): array
    {
        return $request->validate([
            'residence_id' => ['nullable', 'integer'],
            'period' => ['required', 'date_format:Y-m'],
            'amount' => ['required', new ValidMoney],
            'distribution_method' => ['required', Rule::in(['allocation_key', 'equal'])],
        ]);
    }

    private function essentialCotisationContext(Request $request, TenantContext $context, array $data): array
    {
        $residence = $this->residence($request, $context);
        abort_unless($residence, 404);
        $issueDate = CarbonImmutable::createFromFormat('Y-m-d', $data['period'].'-01');
        $exercise = $residence->financialExercises()->where('status', 'open')
            ->whereDate('starts_on', '<=', $issueDate)->whereDate('ends_on', '>=', $issueDate)->first();
        if (! $exercise) {
            throw ValidationException::withMessages(['period' => __('Aucune période financière ouverte ne couvre ce mois.')]);
        }
        $category = $residence->chargeCategories()->where('active', true)
            ->orderByRaw("CASE WHEN type = 'ordinary' THEN 0 ELSE 1 END")->first();
        if (! $category) {
            throw ValidationException::withMessages(['amount' => __('Configurez d’abord une catégorie de cotisation active.')]);
        }
        $allocationKey = null;
        if ($data['distribution_method'] === 'allocation_key') {
            $allocationKey = $residence->allocationKeys()->where('active', true)->orderByDesc('is_default')->first();
            if (! $allocationKey) {
                throw ValidationException::withMessages(['distribution_method' => __('Aucune clé de tantièmes active n’est configurée.')]);
            }
        }

        return [$residence, $exercise, $category, $allocationKey];
    }

    private function essentialCotisationLine(Residence $residence, FinancialExercise $exercise, int $categoryId, ?int $allocationKeyId, array $data): FundCallLine
    {
        $call = new FundCall([
            'organization_id' => $residence->organization_id,
            'residence_id' => $residence->id,
            'financial_exercise_id' => $exercise->id,
            'issue_date' => $data['period'].'-01',
        ]);
        $line = new FundCallLine([
            'charge_category_id' => $categoryId,
            'distribution_method' => $data['distribution_method'],
            'allocation_key_id' => $allocationKeyId,
            'target_type' => 'all',
            'target_ids' => [],
            'amount_cents' => Money::cents($data['amount']),
        ]);
        $line->setRelation('fundCall', $call);

        return $line;
    }

    private function essentialPaymentPlan(Residence $residence, array $data): array
    {
        $lot = $residence->lots()->whereKey((int) $data['lot_id'])->firstOrFail();
        $mode = $data['allocation_mode'];
        $query = LotCharge::query()->where('organization_id', $residence->organization_id)
            ->where('residence_id', $residence->id)->where('lot_id', $lot->id)
            ->whereIn('status', ['unpaid', 'partial'])->whereNull('cancelled_at')
            ->withSum(['allocations as paid_cents' => fn ($rows) => $rows->whereNull('reversed_at')], 'amount_cents');
        if ($mode === 'range') {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from_period'].'-01')->startOfMonth();
            $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to_period'].'-01')->endOfMonth();
            if ($to->lt($from)) {
                throw ValidationException::withMessages(['to_period' => __('Le mois de fin doit suivre le mois de début.')]);
            }
            $query->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]);
        }
        $charges = $query->orderBy('due_date')->orderBy('issue_date')->orderBy('id')->get();
        $requested = $mode === 'range'
            ? (int) $charges->sum(fn ($charge) => max(0, (int) $charge->amount_cents - (int) ($charge->paid_cents ?? 0)))
            : Money::cents($data['amount']);
        $remaining = $requested;
        $allocations = collect();
        foreach ($charges as $charge) {
            if ($remaining <= 0) {
                break;
            }
            $paid = (int) ($charge->paid_cents ?? 0);
            $outstanding = max(0, (int) $charge->amount_cents - $paid);
            $allocated = min($remaining, $outstanding);
            if ($allocated <= 0) {
                continue;
            }
            $newPaid = $paid + $allocated;
            $allocations->push([
                'lot_charge_id' => $charge->id,
                'period' => $charge->issue_date->format('Y-m'),
                'expected_cents' => (int) $charge->amount_cents,
                'previously_paid_cents' => $paid,
                'amount_cents' => $allocated,
                'remaining_cents' => max(0, (int) $charge->amount_cents - $newPaid),
                'projected_status' => $newPaid >= (int) $charge->amount_cents ? 'paid' : 'partial',
            ]);
            $remaining -= $allocated;
        }

        return [
            'lot_id' => $lot->id,
            'lot' => $lot->reference,
            'total_cents' => $requested,
            'allocated_cents' => $requested - $remaining,
            'credit_cents' => $remaining,
            'allocations' => $allocations->values()->all(),
        ];
    }

    private function applyRelevantReportPeriod(Request $request, ?Residence $residence, string $type): void
    {
        if ($request->filled('period') || $type !== 'unpaid' || ! $residence) {
            return;
        }

        $latestIssueDate = LotCharge::query()
            ->where('organization_id', $residence->organization_id)
            ->where('residence_id', $residence->id)
            ->whereNull('cancelled_at')
            ->max('issue_date');

        if ($latestIssueDate) {
            $request->merge(['period' => substr((string) $latestIssueDate, 0, 7)]);
        }
    }

    private function residence(Request $request, TenantContext $context): ?Residence
    {
        $id = $request->integer('residence_id') ?: $context->residence?->id;
        if (! $id) {
            return null;
        }
        $this->assertResidenceAccess($request, $context->organization()->id, $id);

        return Residence::query()->whereKey($id)->where('organization_id', $context->organization()->id)->where('status', '!=', 'archived')->firstOrFail();
    }

    private function assertResidenceAccess(Request $request, int $organizationId, int $residenceId): void
    {
        $membership = $request->user()->organizations()->whereKey($organizationId)->firstOrFail()->pivot;
        abort_unless($membership->all_residences || $request->user()->residences()->where('residences.organization_id', $organizationId)->whereKey($residenceId)->exists(), 404);
    }

    private function residences(Request $request, TenantContext $context)
    {
        $membership = $request->user()->organizations()->whereKey($context->organization()->id)->firstOrFail()->pivot;

        return $context->organization()->residences()->where('status', '!=', 'archived')->when(! $membership->all_residences, fn ($q) => $q->whereHas('users', fn ($users) => $users->whereKey($request->user()->id)))->orderBy('name')->get(['id', 'name']);
    }

    private function csv(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@]/', ltrim($value)) ? "'".$value : $value;
    }

    private function emptySummary(): array
    {
        return ['expected_cents' => 0, 'collected_cents' => 0, 'remaining_cents' => 0, 'bank_cents' => 0, 'cash_cents' => 0];
    }
}
