<?php

namespace App\Http\Controllers;

use App\Models\FinancialExercise;
use App\Models\Payment;
use App\Rules\ValidMoney;
use App\Services\PaymentWorkflow;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'charge_from' => 'nullable|date', 'charge_to' => 'nullable|date|after_or_equal:charge_from']);
        $q = $context->residence()->payments()->with(['payer', 'account', 'documents']);
        if ($request->filled('search')) {
            $s = $request->input('search');
            $q->where(fn ($x) => $x->where('number', 'like', "%{$s}%")->orWhere('received_from', 'like', "%{$s}%")->orWhere('bank_reference', 'like', "%{$s}%")->orWhere('cheque_number', 'like', "%{$s}%")->orWhereHas('payer', fn ($p) => $p->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")));
        }
        foreach (['status', 'method', 'financial_account_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }
        $q->when($request->filled('from'), fn ($query) => $query->whereDate('payment_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('payment_date', '<=', $request->input('to')))
            ->when($request->filled('charge_from') || $request->filled('charge_to'), function ($query) use ($request) {
                $query->whereHas('allocations.charge', function ($charges) use ($request) {
                    $charges->when($request->filled('charge_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->input('charge_from')))
                        ->when($request->filled('charge_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->input('charge_to')));
                });
            })
            ->when($request->boolean('credit'), fn ($query) => $query->where('status', 'validated')->whereNotNull('payer_contact_id')->whereRaw('payments.amount_cents > (SELECT COALESCE(SUM(payment_allocations.amount_cents), 0) FROM payment_allocations WHERE payment_allocations.payment_id = payments.id AND payment_allocations.reversed_at IS NULL)'));

        return Inertia::render('Finance/Payments/Index', ['payments' => $q->latest('payment_date')->paginate(20)->withQueryString()->through(fn ($p) => $p->append(['allocated_cents', 'credit_cents'])), 'accounts' => $context->residence()->financialAccounts()->where('active', true)->get(['id', 'name']), 'filters' => $request->only(['search', 'status', 'method', 'financial_account_id', 'from', 'to', 'charge_from', 'charge_to', 'credit'])]);
    }

    public function create(TenantContext $context)
    {
        return Inertia::render('Finance/Payments/Form', $this->formProps($context));
    }

    public function store(Request $request, TenantContext $context)
    {
        $r = $context->residence();
        $data = $this->validated($request, $r->id);
        abort_unless(FinancialExercise::whereKey($data['financial_exercise_id'])->where('residence_id', $r->id)->where('status', 'open')->exists(), 409);
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');
        $attributes = collect($data)->except(['amount', 'allocation_mode', 'lot_ids'])->all() + ['organization_id' => $r->organization_id, 'amount_cents' => Money::cents($data['amount']), 'idempotency_key' => $key ?: null];
        $payment = $key ? $r->payments()->firstOrCreate(['idempotency_key' => $key], $attributes) : $r->payments()->create($attributes);
        if ($request->boolean('validate_now')) {
            abort_unless($request->user()->canInOrganization('validate_payments', $r->organization), 403);
            app(PaymentWorkflow::class)->validate($payment, $request->user(), $data['allocation_mode'] ?? 'fifo', $data['lot_ids'] ?? []);
        }

        return redirect()->route('payments.show', $payment)->with('success', __('Paiement enregistré.'));
    }

    public function show(Payment $payment, TenantContext $context)
    {
        $this->tenant($payment, $context);
        $chargeQuery = $context->residence()->lotCharges()->whereNull('cancelled_at')->whereIn('status', ['unpaid', 'partial']);
        if ($payment->status === 'validated') {
            $chargeQuery->whereHas('exercise', fn ($query) => $query->where('status', 'open'))
                ->whereHas('lot.ownerships', fn ($query) => $query->where('contact_id', $payment->payer_contact_id)
                    ->whereColumn('lot_ownerships.starts_on', '<=', 'lot_charges.issue_date')
                    ->where(fn ($period) => $period->whereNull('lot_ownerships.ends_on')->orWhereColumn('lot_ownerships.ends_on', '>=', 'lot_charges.issue_date')));
        }
        $charges = $chargeQuery->with(['lot:id,reference', 'fundCall:id,number'])->orderBy('due_date')->limit(100)->get()->map(fn ($charge) => [
            'id' => $charge->id, 'lot' => $charge->lot->reference, 'reference' => $charge->fundCall->number,
            'outstanding_cents' => $charge->outstanding_cents,
        ])->filter(fn ($charge) => $charge['outstanding_cents'] > 0)->values();

        return Inertia::render('Finance/Payments/Show', [
            'payment' => $payment->load(['payer', 'account', 'exercise', 'allocations.charge.fundCall', 'allocations.lot', 'allocations.reversedBy:id,name', 'documents'])->append(['allocated_cents', 'unallocated_cents', 'credit_cents']),
            'availableCharges' => $charges,
            'contacts' => $payment->payer_contact_id ? [] : $context->organization()->contacts()->where('active', true)->orderBy('last_name')->limit(100)->get()->map(fn ($contact) => ['id' => $contact->id, 'name' => $contact->display_name]),
        ]);
    }

    public function edit(Payment $payment, TenantContext $context)
    {
        $this->tenant($payment, $context);
        abort_unless($payment->status === 'draft', 409);

        return Inertia::render('Finance/Payments/Form', $this->formProps($context) + ['payment' => $payment]);
    }

    public function update(Request $request, Payment $payment, TenantContext $context)
    {
        $this->tenant($payment, $context);
        abort_unless($payment->status === 'draft', 409);
        $data = $this->validated($request, $payment->residence_id);
        abort_unless(FinancialExercise::whereKey($data['financial_exercise_id'])->where('residence_id', $payment->residence_id)->where('status', 'open')->exists(), 409);
        $payment->update(collect($data)->except(['amount', 'allocation_mode', 'lot_ids'])->all() + ['amount_cents' => Money::cents($data['amount'])]);

        return redirect()->route('payments.show', $payment);
    }

    public function validatePayment(Request $request, Payment $payment, TenantContext $context, PaymentWorkflow $workflow)
    {
        $this->tenant($payment, $context);
        $data = $request->validate(['allocation_mode' => ['required', Rule::in(['fifo', 'selected_lots', 'manual', 'manual_then_fifo'])], 'lot_ids' => 'array', 'manual' => 'array', 'manual.*.lot_charge_id' => 'required_with:manual|integer', 'manual.*.amount' => ['required_with:manual', new ValidMoney]]);
        $manual = collect($data['manual'] ?? [])->map(fn ($row) => ['lot_charge_id' => $row['lot_charge_id'], 'amount_cents' => Money::cents($row['amount'])])->all();
        $workflow->validate($payment, $request->user(), $data['allocation_mode'], $data['lot_ids'] ?? [], $manual);

        return back()->with('success', __('Paiement validé et reçu généré.'));
    }

    public function allocate(Request $request, Payment $payment, TenantContext $context, PaymentWorkflow $workflow)
    {
        $this->tenant($payment, $context);
        $data = $request->validate(['manual' => 'required|array|min:1', 'manual.*.lot_charge_id' => 'required|integer', 'manual.*.amount' => ['required', new ValidMoney]]);
        $manual = collect($data['manual'])->map(fn ($row) => ['lot_charge_id' => $row['lot_charge_id'], 'amount_cents' => Money::cents($row['amount'])])->all();
        $workflow->allocateCredit($payment, $request->user(), $manual);

        return back();
    }

    public function identifyPayer(Request $request, Payment $payment, TenantContext $context, PaymentWorkflow $workflow)
    {
        $this->tenant($payment, $context);
        $data = $request->validate(['contact_id' => ['required', Rule::exists('contacts', 'id')->where('organization_id', $context->organization()->id)]]);
        $workflow->identifyPayer($payment, $request->user(), (int) $data['contact_id']);

        return back()->with('success', __('Payeur identifié.'));
    }

    public function reverse(Request $request, Payment $payment, TenantContext $context, PaymentWorkflow $workflow)
    {
        $this->tenant($payment, $context);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $workflow->reverse($payment, $request->user(), $data['reason']);

        return back()->with('success', __('Paiement extourné.'));
    }

    private function validated(Request $r, int $residenceId): array
    {
        return $r->validate(['financial_exercise_id' => ['required', Rule::exists('financial_exercises', 'id')->where('residence_id', $residenceId)], 'payer_contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('organization_id', app(TenantContext::class)->organization()->id)], 'received_from' => 'nullable|required_without:payer_contact_id|string|max:180', 'payment_date' => 'required|date', 'amount' => ['required', new ValidMoney], 'method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'other'])], 'financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where(fn ($q) => $q->where('residence_id', $residenceId)->where('active', true))], 'bank_reference' => 'nullable|string|max:120', 'cheque_number' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000', 'allocation_mode' => ['nullable', Rule::in(['fifo', 'selected_lots'])], 'lot_ids' => 'array']);
    }

    private function formProps(TenantContext $context): array
    {
        $r = $context->residence();

        return ['exercises' => $r->financialExercises()->where('status', 'open')->get(['id', 'name']), 'accounts' => $r->financialAccounts()->where('active', true)->get(['id', 'name', 'type', 'default_slot']), 'lots' => $r->lots()->where('active', true)->orderBy('reference')->get(['id', 'reference']), 'contacts' => $r->organization->contacts()->where('active', true)->orderBy('last_name')->limit(50)->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->display_name, 'phone' => $c->primary_phone])];
    }

    private function tenant(Payment $p, TenantContext $t): void
    {
        abort_unless($p->organization_id === $t->organization()->id && $p->residence_id === $t->residence()->id, 404);
    }
}
