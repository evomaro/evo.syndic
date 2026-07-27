<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ResidenceDocument;
use App\Models\ResidenceDocumentVersion;
use App\Services\ResidenceDocumentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ResidenceDocumentController extends Controller
{
    public function index(TenantContext $context)
    {
        return Inertia::render('Portal/Documents', [
            'documents' => ResidenceDocument::query()->where('residence_id', $context->residence()->id)->with(['latestVersion', 'publishedVersion', 'lots:id,reference', 'buildings:id,name', 'contacts:id,type,company_name,first_name,last_name'])->latest()->paginate(30),
            'lots' => $context->residence()->lots()->where('active', true)->orderBy('reference')->get(['id', 'reference']),
            'buildings' => $context->residence()->buildings()->orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::query()->where('organization_id', $context->organization()->id)->orderBy('company_name')->orderBy('last_name')->limit(100)->get(['id', 'type', 'company_name', 'first_name', 'last_name']),
        ]);
    }

    public function store(Request $request, TenantContext $context, ResidenceDocumentService $service)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'category' => ['required', Rule::in(['contract', 'invoice', 'minutes', 'regulation', 'notice', 'report', 'other'])], 'audience' => ['required', Rule::in(['staff', 'all_residents', 'selected_buildings', 'selected_lots', 'selected_contacts'])], 'document_date' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date'], 'scheduled_for' => ['nullable', 'date', 'after:now'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:20480'], 'lot_ids' => ['array'], 'lot_ids.*' => [Rule::exists('lots', 'id')->where('residence_id', $context->residence()->id)], 'building_ids' => ['array'], 'building_ids.*' => [Rule::exists('buildings', 'id')->where('residence_id', $context->residence()->id)], 'contact_ids' => ['array'], 'contact_ids.*' => [Rule::exists('contacts', 'id')->where('organization_id', $context->organization()->id)]]);
        $file = $data['file'];
        $lots = $data['lot_ids'] ?? [];
        $buildings = $data['building_ids'] ?? [];
        $contacts = $data['contact_ids'] ?? [];
        unset($data['file'], $data['lot_ids'], $data['building_ids'], $data['contact_ids']);
        $document = ResidenceDocument::create($data + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id, 'created_by' => $request->user()->id, 'status' => isset($data['scheduled_for']) ? 'scheduled' : 'draft']);
        $document->lots()->sync($lots);
        $document->buildings()->sync($buildings);
        $document->contacts()->sync($contacts);
        $service->storeVersion($document, $file, $request->user());

        return back()->with('success', __('Document ajouté.'));
    }

    public function version(Request $request, ResidenceDocument $document, TenantContext $context, ResidenceDocumentService $service)
    {
        abort_unless($document->residence_id === $context->residence()->id, 404);
        $service->storeVersion($document, $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:20480']])['file'], $request->user());

        return back()->with('success', __('Nouvelle version ajoutée.'));
    }

    public function transition(Request $request, ResidenceDocument $document, TenantContext $context, ResidenceDocumentService $service)
    {
        abort_unless($document->residence_id === $context->residence()->id, 404);
        $action = $request->validate(['action' => ['required', Rule::in(['publish', 'archive'])]])['action'];
        if ($action === 'publish') {
            $published = $service->attemptPublish($document, $request->user());
            if (! $published) {
                return back()->with('error', __('La publication a échoué; le document planifié est conservé.'));
            }
        } else {
            $document->update(['status' => 'archived', 'archived_at' => now()]);
        }
        activity()->performedOn($document)->causedBy($request->user())->withProperties(['organization_id' => $document->organization_id, 'residence_id' => $document->residence_id])->log("residence_document.$action");

        return back()->with('success', __('Document mis à jour.'));
    }

    public function retry(Request $request, ResidenceDocument $document, TenantContext $context, ResidenceDocumentService $service)
    {
        abort_unless($document->organization_id === $context->organization()->id && $document->residence_id === $context->residence()->id, 404);
        abort_unless($document->publication_failed_at, 422);
        $published = $service->attemptPublish($document, $request->user());

        return back()->with($published ? 'success' : 'error', $published ? __('Document publié.') : __('La nouvelle tentative a échoué.'));
    }

    public function download(ResidenceDocumentVersion $version, TenantContext $context, ResidenceDocumentService $service)
    {
        $version->load('document.residence.organization');
        abort_unless($version->document->organization_id === $context->organization()->id && $version->document->residence_id === $context->residence()->id, 404);
        $staff = request()->user()->canInOrganization('view_documents', $context->organization());
        abort_unless($service->accessibleTo($version->document, request()->user(), $staff), 403);
        abort_unless($staff || $version->document->published_version_id === $version->id, 403);
        abort_unless(Storage::disk($version->disk)->exists($version->path) && hash_equals($version->checksum, hash('sha256', Storage::disk($version->disk)->get($version->path))), 409, __('Le fichier ne passe pas le contrôle d’intégrité.'));
        activity()->performedOn($version->document)->causedBy(request()->user())->withProperties(['organization_id' => $version->document->organization_id, 'residence_id' => $version->document->residence_id, 'version' => $version->version])->log('residence_document.downloaded');

        return Storage::disk($version->disk)->download($version->path, $version->name, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
