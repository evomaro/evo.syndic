<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\SupplierSettlementRequest;
use App\Models\FinancialExercise;
use App\Models\SupplierSettlement;
use App\Queries\SupplierSettlementQuery;
use App\Services\SupplierSettlementWorkflow;
use App\Services\VoucherService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupplierSettlementController extends Controller
{
    public function index(Request $request, TenantContext $context, SupplierSettlementQuery $query)
    {
        return Inertia::render('SupplierSettlements/Index', ['settlements' => $query->paginate($request, $context), 'filters' => $request->only('status')]);
    }

    public function create(TenantContext $context)
    {
        return Inertia::render('SupplierSettlements/Form', ['exercises' => FinancialExercise::query()->where('residence_id', $context->residence()->id)->where('status', 'open')->get(['id', 'name']), 'accounts' => $context->residence()->financialAccounts()->where('active', true)->get(['id', 'name'])]);
    }

    public function show(SupplierSettlement $settlement, TenantContext $context)
    {
        $this->tenant($settlement, $context);
        $this->authorize('view', $settlement);

        return Inertia::render('SupplierSettlements/Show', ['settlement' => $settlement->load(['supplier', 'account', 'allocations.invoice', 'allocations.line', 'documents', 'movements'])]);
    }

    public function store(SupplierSettlementRequest $request, TenantContext $context)
    {
        $data = $request->validated();
        $settlement = ! empty($data['idempotency_key']) ? SupplierSettlement::firstOrCreate(['residence_id' => $context->residence()->id, 'idempotency_key' => $data['idempotency_key']], $data + ['organization_id' => $context->organization()->id]) : SupplierSettlement::create($data + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id]);

        return to_route('supplier-settlements.show', $settlement)->with('success', __('Règlement enregistré.'));
    }

    public function validateSettlement(Request $request, SupplierSettlement $settlement, TenantContext $context, SupplierSettlementWorkflow $workflow)
    {
        $this->tenant($settlement, $context);
        $this->authorize('validate', $settlement);
        $data = $request->validate(['mode' => ['required', Rule::in(['fifo', 'manual'])], 'allocations' => ['array'], 'allocations.*.supplier_invoice_id' => ['required', 'integer'], 'allocations.*.supplier_invoice_line_id' => ['nullable', 'integer'], 'allocations.*.amount_cents' => ['required', 'integer', 'min:1']]);
        $workflow->validate($settlement, $request->user(), $data['mode'], $data['allocations'] ?? []);

        return back()->with('success', __('Règlement validé.'));
    }

    public function reverse(Request $request, SupplierSettlement $settlement, TenantContext $context, SupplierSettlementWorkflow $workflow)
    {
        $this->tenant($settlement, $context);
        $this->authorize('reverse', $settlement);
        $workflow->reverse($settlement, $request->user(), $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason']);

        return back()->with('success', __('Règlement extourné.'));
    }

    public function retryVoucher(Request $request, SupplierSettlement $settlement, TenantContext $context, VoucherService $service)
    {
        $this->tenant($settlement, $context);
        $this->authorize('validate', $settlement);
        abort_unless($settlement->status === 'validated', 422);
        $service->generate($settlement, $request->user());

        return back()->with('success', __('Justificatif généré.'));
    }

    private function tenant(SupplierSettlement $settlement, TenantContext $context): void
    {
        abort_unless($settlement->organization_id === $context->organization()->id && $settlement->residence_id === $context->residence()->id, 404);
    }
}
