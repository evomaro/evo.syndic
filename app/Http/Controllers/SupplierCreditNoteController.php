<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\SupplierCreditNoteRequest;
use App\Models\SupplierCreditNote;
use App\Queries\SupplierInvoiceQuery;
use App\Services\CreditNoteWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierCreditNoteController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $query = SupplierCreditNote::query()->where('residence_id', $context->residence()->id)->with('supplier:id,legal_name');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return Inertia::render('SupplierCreditNotes/Index', ['creditNotes' => $query->latest('credit_date')->paginate(20)->withQueryString(), 'filters' => $request->only('status')]);
    }

    public function create()
    {
        return Inertia::render('SupplierCreditNotes/Form');
    }

    public function show(SupplierCreditNote $credit, TenantContext $context, SupplierInvoiceQuery $invoices)
    {
        $this->tenant($credit, $context);
        $this->authorize('view', $credit);

        return Inertia::render('SupplierCreditNotes/Show', [
            'creditNote' => $credit->load(['supplier', 'allocations.invoice', 'allocations.line']),
            'openInvoices' => $credit->status === 'draft' ? $invoices->openForSupplier($credit->supplier_id, $context) : [],
        ]);
    }

    public function store(SupplierCreditNoteRequest $request, TenantContext $context)
    {
        $data = $request->validated();
        $credit = ! empty($data['idempotency_key']) ? SupplierCreditNote::firstOrCreate(['organization_id' => $context->organization()->id, 'idempotency_key' => $data['idempotency_key']], $data + ['residence_id' => $context->residence()->id]) : SupplierCreditNote::create($data + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id]);

        return to_route('supplier-credit-notes.show', $credit)->with('success', __('Avoir enregistré.'));
    }

    public function validateCredit(Request $request, SupplierCreditNote $credit, TenantContext $context, CreditNoteWorkflow $workflow)
    {
        $this->tenant($credit, $context);
        $this->authorize('update', $credit);
        $data = $request->validate(['allocations' => ['required', 'array', 'min:1'], 'allocations.*.supplier_invoice_id' => ['required', 'integer'], 'allocations.*.supplier_invoice_line_id' => ['nullable', 'integer'], 'allocations.*.amount_cents' => ['required', 'integer', 'min:1']]);
        $workflow->validate($credit, $request->user(), $data['allocations']);

        return back()->with('success', __('Avoir validé.'));
    }

    public function cancel(Request $request, SupplierCreditNote $credit, TenantContext $context, CreditNoteWorkflow $workflow)
    {
        $this->tenant($credit, $context);
        $this->authorize('update', $credit);
        $workflow->cancel($credit, $request->user(), $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason']);

        return back()->with('success', __('Avoir annulé.'));
    }

    private function tenant(SupplierCreditNote $credit, TenantContext $context): void
    {
        abort_unless($credit->organization_id === $context->organization()->id && $credit->residence_id === $context->residence()->id, 404);
    }
}
