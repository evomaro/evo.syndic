<?php

namespace Tests\Feature;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\Contact;
use App\Models\ExpenseCategory;
use App\Models\FinancialExercise;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\ResidenceAnnouncement;
use App\Models\ResidenceDocument;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\AnnouncementService;
use App\Services\SupplierInvoiceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseThreePortalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Storage::fake('local');
        app()->instance(ReceiptPdfRenderer::class, new class implements ReceiptPdfRenderer
        {
            public function render(string $html, string $locale): string
            {
                return '%PDF test';
            }
        });
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $manager = User::factory()->create();
        $resident = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'owner', 'all_residences' => true]);
        $organization->users()->attach($resident, ['role' => 'coproprietaire', 'all_residences' => false]);
        $residence->users()->attach($resident);
        foreach ([$manager, $resident] as $user) {
            $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        }
        $lot = Lot::factory()->for($residence)->create();
        $contact = Contact::factory()->for($organization)->create();
        $lot->ownerships()->create(['contact_id' => $contact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2025-01-01']);
        $contact->users()->attach($resident, ['organization_id' => $organization->id, 'linked_by' => $manager->id, 'linked_at' => now()]);

        return compact('organization', 'residence', 'manager', 'resident', 'lot', 'contact');
    }

    public function test_private_document_download_is_authorized_versioned_and_integrity_checked(): void
    {
        Notification::fake();
        $c = $this->context();
        $this->actingAs($c['manager'])->post(route('documents.store'), ['title' => 'Règlement', 'category' => 'regulation', 'audience' => 'all_residents', 'file' => UploadedFile::fake()->createWithContent('reglement.pdf', '%PDF-1.4 secret')])->assertRedirect();
        $document = ResidenceDocument::first();
        $this->actingAs($c['manager'])->post(route('documents.transition', $document), ['action' => 'publish'])->assertRedirect();
        $version = $document->versions()->first();
        $this->actingAs($c['resident'])->get(route('documents.download', $version))->assertOk()->assertHeader('content-disposition');
        Storage::disk('local')->put($version->path, 'tampered');
        $this->actingAs($c['resident'])->get(route('documents.download', $version))->assertStatus(409);
        Notification::assertSentTo($c['resident'], PortalNotification::class);
    }

    public function test_former_owner_and_manipulated_tenant_ids_cannot_download_documents(): void
    {
        $c = $this->context();
        $other = $this->context();
        $document = ResidenceDocument::create(['organization_id' => $other['organization']->id, 'residence_id' => $other['residence']->id, 'title' => 'Privé', 'category' => 'other', 'status' => 'published', 'audience' => 'all_residents', 'created_by' => $other['manager']->id]);
        Storage::disk('local')->put('other.pdf', '%PDF');
        $version = $document->versions()->create(['version' => 1, 'name' => 'other.pdf', 'disk' => 'local', 'path' => 'other.pdf', 'mime_type' => 'application/pdf', 'size' => 4, 'checksum' => hash('sha256', '%PDF'), 'uploaded_by' => $other['manager']->id]);
        $this->actingAs($c['resident'])->get(route('documents.download', $version))->assertNotFound();
        $c['lot']->ownerships()->update(['ends_on' => today()->subDay()]);
        $local = ResidenceDocument::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'title' => 'Ancien', 'category' => 'other', 'status' => 'published', 'audience' => 'all_residents', 'created_by' => $c['manager']->id]);
        Storage::disk('local')->put('local.pdf', '%PDF');
        $localVersion = $local->versions()->create(['version' => 1, 'name' => 'local.pdf', 'disk' => 'local', 'path' => 'local.pdf', 'mime_type' => 'application/pdf', 'size' => 4, 'checksum' => hash('sha256', '%PDF'), 'uploaded_by' => $c['manager']->id]);
        $this->actingAs($c['resident'])->get(route('documents.download', $localVersion))->assertForbidden();
    }

    public function test_scheduled_announcement_resolves_active_audience_once(): void
    {
        Notification::fake();
        $c = $this->context();
        $announcement = ResidenceAnnouncement::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'title' => 'Coupure eau', 'body' => 'Intervention demain', 'priority' => 'important', 'status' => 'scheduled', 'audience' => 'selected_lots', 'scheduled_for' => now()->subMinute(), 'created_by' => $c['manager']->id]);
        $announcement->lots()->sync([$c['lot']->id]);
        $this->assertSame(1, app(AnnouncementService::class)->publishDue());
        $this->assertSame(0, app(AnnouncementService::class)->publishDue());
        $this->assertSame([$c['resident']->id], $announcement->fresh()->audience_snapshot['user_ids']);
        Notification::assertSentToTimes($c['resident'], PortalNotification::class, 1);
    }

    public function test_resident_portal_exposes_only_explicitly_visible_expenses(): void
    {
        $c = $this->context();
        $exercise = FinancialExercise::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $category = ExpenseCategory::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => 'Eau', 'code' => 'water']);
        $supplier = Supplier::create(['organization_id' => $c['organization']->id, 'legal_name' => 'Données fournisseur secrètes', 'rib' => 'SECRET-RIB']);
        foreach ([['private', 1000], ['category_summary', 2000], ['invoice_summary', 3000]] as [$visibility, $amount]) {
            $invoice = SupplierInvoice::create(['organization_id' => $c['organization']->id, 'primary_residence_id' => $c['residence']->id, 'supplier_id' => $supplier->id, 'supplier_invoice_number' => uniqid(), 'invoice_date' => '2026-01-01', 'due_date' => '2026-02-01', 'subtotal_cents' => $amount, 'tax_cents' => 0, 'total_cents' => $amount]);
            $invoice->lines()->create(['residence_id' => $c['residence']->id, 'financial_exercise_id' => $exercise->id, 'expense_category_id' => $category->id, 'description' => 'Eau', 'quantity' => 1, 'unit_price_cents' => $amount, 'tax_rate' => 0, 'subtotal_cents' => $amount, 'tax_cents' => 0, 'total_cents' => $amount, 'visibility' => $visibility]);
            $path = uniqid().'.pdf';
            Storage::disk('local')->put($path, '%PDF');
            $invoice->attachments()->create(['kind' => 'original', 'version' => 1, 'name' => 'invoice.pdf', 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => 4, 'checksum' => hash('sha256', '%PDF'), 'uploaded_by' => $c['manager']->id]);
            app(SupplierInvoiceWorkflow::class)->validate($invoice, $c['manager']);
        }
        $this->actingAs($c['resident'])->get(route('portal.index'))->assertOk()
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('pragma', 'no-cache')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Home')
                ->has('expenses', 1)
                ->where('expenses.0.total_cents', 5000)
                ->missing('expenses.0.supplier')
                ->has('expenseInvoices', 1)
                ->where('expenseInvoices.0.public_description', 'Eau')
                ->where('expenseInvoices.0.total_cents', 3000)
                ->missing('expenseInvoices.0.supplier')
                ->missing('expenseInvoices.0.rib'));
    }

    public function test_resident_portal_prefers_arabic_announcement_with_safe_fallback(): void
    {
        $this->assertStringContainsString('value.slice(0, 10)', file_get_contents(resource_path('js/Pages/Portal/Home.vue')));

        $c = $this->context();
        $c['resident']->update(['preferred_language' => 'ar']);
        $this->actingAs($c['manager'])->post(route('announcements.store'), [
            'title_fr' => 'Bienvenue',
            'body_fr' => 'Message français',
            'title_ar' => 'مرحبا',
            'body_ar' => 'رسالة عربية',
            'priority' => 'important',
            'audience' => 'all_residents',
        ])->assertRedirect();
        $announcement = ResidenceAnnouncement::latest('id')->firstOrFail();
        $this->assertSame('Bienvenue', $announcement->title);
        $announcement->update(['status' => 'published', 'published_at' => now()]);

        $this->actingAs($c['resident'])
            ->get(route('portal.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('announcements.0.title', 'مرحبا')
                ->where('announcements.0.body', 'رسالة عربية'));

        $this->actingAs($c['resident'])
            ->get(route('portal.announcements'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/ResidentAnnouncements')
                ->has('announcements.data', 1)
                ->where('announcements.data.0.title', 'مرحبا'));

        $this->actingAs($c['resident'])
            ->get(route('portal.documents'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/ResidentDocuments')
                ->has('documents.data', 0));
    }

    public function test_invoice_without_private_original_is_rejected(): void
    {
        $c = $this->context();
        $exercise = FinancialExercise::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $category = ExpenseCategory::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => 'Eau', 'code' => 'water']);
        $supplier = Supplier::create(['organization_id' => $c['organization']->id, 'legal_name' => 'ONEE']);
        $invoice = SupplierInvoice::create(['organization_id' => $c['organization']->id, 'primary_residence_id' => $c['residence']->id, 'supplier_id' => $supplier->id, 'invoice_date' => '2026-01-01', 'due_date' => '2026-02-01', 'total_cents' => 100]);
        $invoice->lines()->create(['residence_id' => $c['residence']->id, 'financial_exercise_id' => $exercise->id, 'expense_category_id' => $category->id, 'description' => 'Eau', 'quantity' => 1, 'unit_price_cents' => 100, 'tax_rate' => 0, 'subtotal_cents' => 100, 'tax_cents' => 0, 'total_cents' => 100]);
        $this->expectException(ValidationException::class);
        app(SupplierInvoiceWorkflow::class)->validate($invoice, $c['manager']);
    }
}
