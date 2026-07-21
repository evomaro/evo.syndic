<?php

namespace App\Http\Controllers;

use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Rules\ValidMoney;
use App\Services\FundCallWorkflow;
use App\Support\ArabicPdf;
use App\Support\Money;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FundCallController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $query = $context->residence()->fundCalls()->with('exercise');
        foreach (['status', 'financial_exercise_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('number', 'like', '%'.$request->input('search').'%')->orWhere('title', 'like', '%'.$request->input('search').'%'));
        }
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->input('to')));

        return Inertia::render('Finance/FundCalls/Index', ['calls' => $query->latest('issue_date')->paginate(20)->withQueryString(), 'exercises' => $context->residence()->financialExercises()->get(['id', 'name', 'status']), 'filters' => $request->only(['status', 'financial_exercise_id', 'search', 'from', 'to'])]);
    }

    public function create(TenantContext $context)
    {
        return Inertia::render('Finance/FundCalls/Form', $this->formProps($context));
    }

    public function store(Request $request, TenantContext $context)
    {
        $r = $context->residence();
        $data = $this->validated($request, $r->id);
        abort_unless(FinancialExercise::whereKey($data['financial_exercise_id'])->where('residence_id', $r->id)->whereIn('status', ['draft', 'open'])->exists(), 409);
        $call = DB::transaction(function () use ($r, $data) {
            $call = $r->fundCalls()->create(collect($data)->except('lines')->all() + ['organization_id' => $r->organization_id]);
            foreach ($data['lines'] as $i => $line) {
                $call->lines()->create($this->lineData($line, $i));
            }

            return $call;
        });

        return redirect()->route('fund-calls.show', $call)->with('success', __('Brouillon d’appel créé.'));
    }

    public function show(FundCall $fundCall, TenantContext $context, FundCallWorkflow $workflow)
    {
        $this->tenant($fundCall, $context);
        $fundCall->load(['exercise', 'lines.category', 'charges.lot', 'charges.billedContact']);
        $preview = $fundCall->status === 'draft' ? $workflow->preview($fundCall) : [];

        return Inertia::render('Finance/FundCalls/Show', ['call' => $fundCall, 'preview' => $preview]);
    }

    public function edit(FundCall $fundCall, TenantContext $context)
    {
        $this->tenant($fundCall, $context);
        abort_unless($fundCall->status === 'draft', 409);

        return Inertia::render('Finance/FundCalls/Form', $this->formProps($context) + ['call' => $fundCall->load('lines')]);
    }

    public function update(Request $request, FundCall $fundCall, TenantContext $context)
    {
        $this->tenant($fundCall, $context);
        abort_unless($fundCall->status === 'draft', 409);
        $data = $this->validated($request, $fundCall->residence_id);
        abort_unless(FinancialExercise::whereKey($data['financial_exercise_id'])->where('residence_id', $fundCall->residence_id)->whereIn('status', ['draft', 'open'])->exists(), 409);
        DB::transaction(function () use ($fundCall, $data) {
            $fundCall->update(collect($data)->except('lines')->all());
            $fundCall->lines()->delete();
            foreach ($data['lines'] as $i => $line) {
                $fundCall->lines()->create($this->lineData($line, $i));
            }
        });

        return redirect()->route('fund-calls.show', $fundCall);
    }

    public function validateCall(FundCall $fundCall, TenantContext $context, FundCallWorkflow $workflow)
    {
        $this->tenant($fundCall, $context);
        $workflow->validate($fundCall, request()->user());

        return back()->with('success', __('Appel de fonds validé.'));
    }

    public function cancel(Request $request, FundCall $fundCall, TenantContext $context, FundCallWorkflow $workflow)
    {
        $this->tenant($fundCall, $context);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $workflow->cancel($fundCall, $request->user(), $data['reason']);

        return back()->with('success', __('Appel de fonds annulé.'));
    }

    public function pdf(Request $request, FundCall $fundCall, TenantContext $context)
    {
        $this->tenant($fundCall, $context);
        abort_unless(in_array($fundCall->status, ['validated', 'closed'], true), 409);
        $lotId = $request->integer('lot');
        $charges = $fundCall->charges()->with(['lot', 'billedContact', 'line.category'])->when($lotId, fn ($q) => $q->where('lot_id', $lotId))->get();
        if ($lotId && $charges->isEmpty()) {
            abort(404);
        } $locale = $request->user()->preferred_language === 'ar' ? 'ar' : 'fr';

        $html = view('pdf.fund-call', compact('fundCall', 'charges', 'locale'))->render();

        return Pdf::loadHTML(ArabicPdf::shapeHtml($html, $locale))->setPaper('a4')->download(($lotId ? 'avis-' : 'appel-').$fundCall->number.'.pdf');
    }

    private function formProps(TenantContext $c): array
    {
        $r = $c->residence();

        return ['exercises' => $r->financialExercises()->whereIn('status', ['draft', 'open'])->get(['id', 'name', 'status', 'starts_on', 'ends_on']), 'categories' => $r->chargeCategories()->where('active', true)->get(), 'allocationKeys' => $r->allocationKeys()->where('active', true)->get(['id', 'name']), 'buildings' => $r->buildings()->where('active', true)->get(['id', 'name']), 'lots' => $r->lots()->where('active', true)->orderBy('reference')->get(['id', 'reference', 'type', 'building_id'])];
    }

    private function validated(Request $r, int $residenceId): array
    {
        return $r->validate(['financial_exercise_id' => ['required', Rule::exists('financial_exercises', 'id')->where('residence_id', $residenceId)], 'title' => 'required|string|max:180', 'description' => 'nullable|string|max:3000', 'issue_date' => 'required|date', 'due_date' => 'required|date|after_or_equal:issue_date', 'lines' => 'required|array|min:1', 'lines.*.charge_category_id' => ['required', Rule::exists('charge_categories', 'id')->where('residence_id', $residenceId)], 'lines.*.label' => 'required|string|max:180', 'lines.*.distribution_method' => ['required', Rule::in(['allocation_key', 'equal', 'fixed', 'manual'])], 'lines.*.allocation_key_id' => ['nullable', Rule::exists('allocation_keys', 'id')->where('residence_id', $residenceId)], 'lines.*.target_type' => ['required', Rule::in(['all', 'buildings', 'lot_types', 'lots'])], 'lines.*.target_ids' => 'nullable|array', 'lines.*.amount' => ['required', new ValidMoney], 'lines.*.fixed_amount' => ['nullable', new ValidMoney], 'lines.*.manual_allocations' => 'nullable|array', 'lines.*.manual_allocations.*.lot_id' => ['required_with:lines.*.manual_allocations', 'integer'], 'lines.*.manual_allocations.*.amount' => ['required_with:lines.*.manual_allocations', new ValidMoney]]);
    }

    private function lineData(array $l, int $i): array
    {
        return collect($l)->except(['amount', 'fixed_amount'])->all() + ['amount_cents' => Money::cents($l['amount']), 'fixed_amount_cents' => isset($l['fixed_amount']) && $l['fixed_amount'] !== '' ? Money::cents($l['fixed_amount']) : null, 'sort_order' => $i];
    }

    private function tenant(FundCall $c, TenantContext $t): void
    {
        abort_unless($c->organization_id === $t->organization()->id && $c->residence_id === $t->residence()->id, 404);
    }
}
