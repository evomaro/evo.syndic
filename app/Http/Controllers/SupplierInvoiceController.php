<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\SupplierInvoiceRequest;
use App\Models\ExpenseCategory;
use App\Models\FinancialExercise;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAttachment;
use App\Queries\SupplierInvoiceQuery;
use App\Services\AccountingSourceStatusService;
use App\Services\SupplierInvoiceDraftService;
use App\Services\SupplierInvoiceWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupplierInvoiceController extends Controller
{
    public function index(Request $request, TenantContext $context, SupplierInvoiceQuery $query)
    {
        return Inertia::render('SupplierInvoices/Index', ['invoices' => $query->paginate($request, $context), 'filters' => $request->only(['q', 'status'])]);
    }

    public function create(TenantContext $context)
    {
        $organization = $context->organization();
        $membership = request()->user()->organizations()->whereKey($organization->id)->firstOrFail()->pivot;
        $residences = $membership->all_residences
            ? $organization->residences()->orderBy('name')->get(['id', 'name'])
            : request()->user()->residences()->where('organization_id', $organization->id)->orderBy('name')->get(['residences.id', 'name']);

        return Inertia::render('SupplierInvoices/Form', ['exercises' => FinancialExercise::query()->where('organization_id', $organization->id)->where('status', 'open')->get(['id', 'residence_id', 'name']), 'categories' => ExpenseCategory::query()->where('organization_id', $organization->id)->where('active', true)->get(['id', 'residence_id', 'name']), 'residences' => $residences]);
    }

    public function show(SupplierInvoice $invoice, TenantContext $context, SupplierInvoiceQuery $query, AccountingSourceStatusService $accounting)
    {
        $this->tenant($invoice, $context);
        $this->authorize('view', $invoice);

        return Inertia::render('SupplierInvoices/Show', [
            'invoice' => $query->show($invoice, $context->residence()->id),
            'accountingPosting' => request()->user()->canInOrganization('view_source_postings', $context->organization())
                ? $accounting->get('supplier_invoice', $invoice->id, $invoice->organization_id, $context->residence()->id)
                : null,
        ]);
    }

    public function search(Request $request, TenantContext $context, SupplierInvoiceQuery $query)
    {
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'max:100']])['q']);

        return response()->json($query->search($term, $context));
    }

    public function store(SupplierInvoiceRequest $request, TenantContext $context, SupplierInvoiceDraftService $service)
    {
        $invoice = $service->create($request->validated(), $context->organization(), $context->residence(), $request->user());

        return to_route('supplier-invoices.show', $invoice)->with('success', __('Facture enregistrée.'));
    }

    public function validateInvoice(Request $request, SupplierInvoice $invoice, TenantContext $context, SupplierInvoiceWorkflow $workflow)
    {
        $this->tenant($invoice, $context);
        $this->authorize('validate', $invoice);
        $workflow->validate($invoice, $request->user());

        return back()->with('success', __('Facture validée.'));
    }

    public function cancel(Request $request, SupplierInvoice $invoice, TenantContext $context, SupplierInvoiceWorkflow $workflow)
    {
        $this->tenant($invoice, $context);
        $this->authorize('cancel', $invoice);
        $workflow->cancel($invoice, $request->user(), $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason']);

        return back()->with('success', __('Facture annulée.'));
    }

    public function attach(Request $request, SupplierInvoice $invoice, TenantContext $context)
    {
        $this->tenant($invoice, $context);
        $this->authorize('attach', $invoice);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:20480'], 'kind' => ['required', Rule::in(['original', 'supporting'])]]);
        abort_if($data['kind'] === 'original' && $invoice->attachments()->where('kind', 'original')->exists(), 422, __('La facture originale existe déjà.'));
        $file = $data['file'];
        $path = $file->store("supplier-invoices/{$invoice->organization_id}/{$invoice->id}", 'local');
        $bytes = Storage::disk('local')->get($path);
        $invoice->attachments()->create(['kind' => $data['kind'], 'version' => (int) $invoice->attachments()->where('kind', $data['kind'])->max('version') + 1, 'name' => basename($file->getClientOriginalName()), 'disk' => 'local', 'path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'immutable' => $invoice->status !== 'draft' || $data['kind'] === 'original', 'uploaded_by' => $request->user()->id]);

        return back()->with('success', __('Pièce jointe ajoutée.'));
    }

    public function download(SupplierInvoiceAttachment $attachment, TenantContext $context)
    {
        $attachment->load('invoice');
        $this->tenant($attachment->invoice, $context);
        $this->authorize('view', $attachment->invoice);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path) && hash_equals($attachment->checksum, hash('sha256', Storage::disk($attachment->disk)->get($attachment->path))), 409);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->name, ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function tenant(SupplierInvoice $invoice, TenantContext $context): void
    {
        abort_unless($invoice->organization_id === $context->organization()->id && $invoice->lines()->where('residence_id', $context->residence()->id)->exists(), 404);
    }
}
