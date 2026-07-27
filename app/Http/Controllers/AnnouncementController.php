<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ResidenceAnnouncement;
use App\Models\ResidenceDocument;
use App\Services\AnnouncementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index(TenantContext $context)
    {
        return Inertia::render('Portal/Announcements', ['announcements' => ResidenceAnnouncement::query()->where('residence_id', $context->residence()->id)->with(['lots:id,reference', 'buildings:id,name', 'contacts:id,type,company_name,first_name,last_name', 'documents:id,title'])->latest()->paginate(30), 'lots' => $context->residence()->lots()->where('active', true)->orderBy('reference')->get(['id', 'reference']), 'buildings' => $context->residence()->buildings()->orderBy('name')->get(['id', 'name']), 'contacts' => Contact::query()->where('organization_id', $context->organization()->id)->limit(100)->get(['id', 'type', 'company_name', 'first_name', 'last_name']), 'documents' => ResidenceDocument::query()->where('residence_id', $context->residence()->id)->where('status', 'published')->get(['id', 'title'])]);
    }

    public function store(Request $request, TenantContext $context)
    {
        $data = $request->validate(['title_fr' => ['nullable', 'required_without:title_ar', 'string', 'max:255'], 'title_ar' => ['nullable', 'required_without:title_fr', 'string', 'max:255'], 'body_fr' => ['nullable', 'required_without:body_ar', 'string', 'max:10000'], 'body_ar' => ['nullable', 'required_without:body_fr', 'string', 'max:10000'], 'priority' => ['required', Rule::in(['normal', 'important', 'urgent'])], 'audience' => ['required', Rule::in(['all_residents', 'selected_buildings', 'selected_lots', 'selected_contacts'])], 'scheduled_for' => ['nullable', 'date', 'after:now'], 'expires_at' => ['nullable', 'date', 'after:now'], 'lot_ids' => ['array'], 'lot_ids.*' => [Rule::exists('lots', 'id')->where('residence_id', $context->residence()->id)], 'building_ids' => ['array'], 'building_ids.*' => [Rule::exists('buildings', 'id')->where('residence_id', $context->residence()->id)], 'contact_ids' => ['array'], 'contact_ids.*' => [Rule::exists('contacts', 'id')->where('organization_id', $context->organization()->id)], 'document_ids' => ['array'], 'document_ids.*' => [Rule::exists('residence_documents', 'id')->where('residence_id', $context->residence()->id)]]);
        $lots = $data['lot_ids'] ?? [];
        $docs = $data['document_ids'] ?? [];
        $buildings = $data['building_ids'] ?? [];
        $contacts = $data['contact_ids'] ?? [];
        unset($data['lot_ids'], $data['building_ids'], $data['contact_ids'], $data['document_ids']);
        $announcement = ResidenceAnnouncement::create($data + ['title' => $data['title_fr'] ?? $data['title_ar'], 'body' => $data['body_fr'] ?? $data['body_ar'], 'organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id, 'created_by' => $request->user()->id, 'status' => isset($data['scheduled_for']) ? 'scheduled' : 'draft']);
        $announcement->lots()->sync($lots);
        $announcement->buildings()->sync($buildings);
        $announcement->contacts()->sync($contacts);
        $announcement->documents()->sync($docs);

        return back()->with('success', __('Annonce créée.'));
    }

    public function publish(Request $request, ResidenceAnnouncement $announcement, TenantContext $context, AnnouncementService $service)
    {
        abort_unless($announcement->residence_id === $context->residence()->id, 404);
        $published = $service->attempt($announcement, $request->user());

        return back()->with($published ? 'success' : 'error', $published ? __('Annonce publiée.') : __('La publication a échoué; le brouillon est conservé.'));
    }

    public function retry(Request $request, ResidenceAnnouncement $announcement, TenantContext $context, AnnouncementService $service)
    {
        abort_unless($announcement->organization_id === $context->organization()->id && $announcement->residence_id === $context->residence()->id, 404);
        abort_unless($announcement->publication_failed_at, 422);

        return $this->publish($request, $announcement, $context, $service);
    }
}
