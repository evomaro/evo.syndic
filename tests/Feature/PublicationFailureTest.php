<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Lot;
use App\Models\ResidenceAnnouncement;
use App\Models\ResidenceDocument;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\AnnouncementService;
use App\Services\ManagerNotificationService;
use App\Services\ResidenceDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class PublicationFailureTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    public function test_announcement_failure_is_safe_exhaustion_is_idempotent_and_recovery_notifies_resident_once(): void
    {
        $context = $this->phaseThreeContext();
        $resident = User::factory()->create();
        $context['organization']->users()->attach($resident, ['role' => 'coproprietaire', 'all_residences' => false]);
        $context['residence']->users()->attach($resident);
        $lot = Lot::factory()->for($context['residence'])->create();
        $contact = Contact::factory()->for($context['organization'])->create();
        $lot->ownerships()->create(['contact_id' => $contact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2025-01-01']);
        $contact->users()->attach($resident, ['organization_id' => $context['organization']->id, 'linked_by' => $context['user']->id, 'linked_at' => now()]);
        $announcement = ResidenceAnnouncement::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'title' => 'Coupure', 'body' => 'Information', 'audience' => 'all_residents', 'status' => 'scheduled', 'scheduled_for' => now()->subMinute(), 'created_by' => $context['user']->id]);
        $failing = Mockery::mock(AnnouncementService::class, [app(ManagerNotificationService::class)])->makePartial();
        $failing->shouldReceive('publish')->times(4)->andThrow(new RuntimeException('secret database password'));

        foreach (range(1, 4) as $attempt) {
            $this->assertFalse($failing->attempt($announcement->fresh(), $context['user']));
        }

        $failed = $announcement->fresh();
        $this->assertSame(4, $failed->publication_attempts);
        $this->assertSame('scheduled', $failed->status);
        $this->assertSame('scheduled_publication_failed', $failed->publication_failure_code);
        $this->assertStringNotContainsString('password', $failed->publication_failure_summary);
        $this->assertSame(2, DB::table('notification_dispatches')->where('event_type', 'scheduled_publication_failed')->count());

        $this->assertTrue(app(AnnouncementService::class)->attempt($failed, $context['user']));
        $this->assertSame('published', $announcement->fresh()->status);
        $this->assertNull($announcement->fresh()->publication_failed_at);
        $this->assertNotNull($announcement->fresh()->publication_failure_resolved_at);
        Notification::assertSentToTimes($resident, PortalNotification::class, 1);
    }

    public function test_shared_document_attempts_increment_final_notice_is_singleton_and_retry_resolves(): void
    {
        $context = $this->phaseThreeContext();
        $document = ResidenceDocument::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'title' => 'Rapport', 'category' => 'report', 'audience' => 'staff', 'status' => 'scheduled', 'scheduled_for' => now()->subMinute(), 'created_by' => $context['user']->id]);
        $service = app(ResidenceDocumentService::class);

        foreach (range(1, 4) as $attempt) {
            $this->assertFalse($service->attemptPublish($document->fresh(), $context['user']));
        }

        $failed = $document->fresh();
        $this->assertSame(4, $failed->publication_attempts);
        $this->assertSame('scheduled', $failed->status);
        $this->assertSame('scheduled_publication_failed', $failed->publication_failure_code);
        $this->assertSame(2, DB::table('notification_dispatches')->where('event_type', 'scheduled_publication_failed')->count());

        $service->storeVersion($failed, UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 report'), $context['user']);
        $this->assertTrue($service->attemptPublish($failed->fresh(), $context['user']));
        $this->assertSame('published', $document->fresh()->status);
        $this->assertNull($document->fresh()->publication_failed_at);
        $this->assertNotNull($document->fresh()->publication_failure_resolved_at);
    }
}
