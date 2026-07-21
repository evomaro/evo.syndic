<?php

namespace Tests\Feature;

use App\Actions\RecordOccupancy;
use App\Actions\TransferOwnership;
use App\Jobs\ProcessImportBatch;
use App\Models\Contact;
use App\Models\ImportBatch;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\ImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PhaseOneClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_is_hashed_notified_localized_rotated_and_accepted_by_new_user(): void
    {
        Mail::fake();
        Notification::fake();
        ['user' => $owner, 'org' => $organization] = $this->context();

        $this->actingAs($owner)->post(route('team.invite'), ['email' => 'invitee@example.test', 'role' => 'accountant', 'preferred_language' => 'ar'])->assertSessionHasNoErrors();
        $invitation = TeamInvitation::firstOrFail();
        $this->assertSame(64, strlen($invitation->getRawOriginal('token_hash')));
        Notification::assertSentOnDemand(TeamInvitationNotification::class, function ($notification) use ($invitation, &$token) {
            $token = $notification->token;
            $mail = $notification->locale('ar')->toMail((object) []);
            $this->assertStringContainsString('دعوة', $mail->subject);

            return hash('sha256', $token) === $invitation->token_hash;
        });

        $oldHash = $invitation->token_hash;
        $this->actingAs($owner)->post(route('team.resend', $invitation))->assertSessionHasNoErrors();
        $this->assertNotSame($oldHash, $invitation->fresh()->token_hash);
        Notification::assertSentOnDemand(TeamInvitationNotification::class, function ($notification) use (&$rotated) {
            $rotated = $notification->token;

            return true;
        });
        $this->post(route('logout'));
        $this->post(route('invitations.register.store', $rotated), ['name' => 'Nouvel utilisateur', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect(route('dashboard'));
        $invitee = User::where('email', 'invitee@example.test')->firstOrFail();
        $this->assertTrue($invitee->belongsToOrganization($organization));
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->post(route('invitations.accept', $rotated))->assertGone();
    }

    public function test_cancelled_and_expired_invitation_landing_pages_are_rejected(): void
    {
        ['user' => $owner, 'org' => $organization] = $this->context();
        $token = 'cancelled-token';
        $invitation = TeamInvitation::create(['organization_id' => $organization->id, 'email' => 'x@example.test', 'role' => 'manager', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'cancelled_at' => now(), 'invited_by' => $owner->id]);
        $this->get(route('invitations.show', $token))->assertGone()->assertInertia(fn ($page) => $page->component('Invitations/Invalid')->where('reason', 'cancelled'));
        $invitation->update(['cancelled_at' => null, 'expires_at' => now()->subMinute()]);
        $this->get(route('invitations.show', $token))->assertGone()->assertInertia(fn ($page) => $page->where('reason', 'expired'));
    }

    public function test_existing_user_returns_to_invitation_after_authentication(): void
    {
        ['user' => $owner, 'org' => $organization] = $this->context();
        $invitee = User::factory()->create(['email' => 'existing@example.test']);
        $token = 'existing-token';
        TeamInvitation::create(['organization_id' => $organization->id, 'email' => $invitee->email, 'role' => 'manager', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'invited_by' => $owner->id]);
        $this->get(route('invitations.show', $token))->assertOk();
        $this->post(route('login'), ['email' => $invitee->email, 'password' => 'password'])->assertRedirect(route('invitations.show', $token));
        $this->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
        $this->assertTrue($invitee->belongsToOrganization($organization));
    }

    public function test_logo_upload_replacement_removal_validation_and_authorization(): void
    {
        Storage::fake('public');
        ['user' => $owner, 'org' => $organization, 'res' => $residence] = $this->context();
        $payload = $this->residencePayload($residence);
        $this->actingAs($owner)->post(route('residences.update', $residence), [...$payload, '_method' => 'put', 'logo' => UploadedFile::fake()->image('logo.png', 120, 120)])->assertSessionHasNoErrors();
        $this->assertSame(1, $residence->fresh()->getMedia('logo')->count());
        $this->actingAs($owner)->post(route('residences.update', $residence), [...$payload, '_method' => 'put', 'logo' => UploadedFile::fake()->image('new.jpg', 100, 100)])->assertSessionHasNoErrors();
        $this->assertSame('new.jpg', $residence->fresh()->getFirstMedia('logo')->file_name);
        $this->actingAs($owner)->post(route('residences.update', $residence), [...$payload, '_method' => 'put', 'logo' => UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $auditor = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($auditor, ['role' => 'auditor', 'all_residences' => true]);
        $auditor->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $this->actingAs($auditor)->delete(route('residences.logo.destroy', $residence))->assertForbidden();
        $this->actingAs($owner)->delete(route('residences.logo.destroy', $residence))->assertSessionHasNoErrors();
        $this->assertFalse($residence->fresh()->hasMedia('logo'));
    }

    public function test_role_matrix_and_privilege_escalation_are_enforced(): void
    {
        foreach (array_keys(config('evosyndic.roles')) as $role) {
            ['user' => $user, 'org' => $organization, 'res' => $residence] = $this->context($role);
            $expected = in_array($role, ['owner', 'administrator', 'manager', 'accountant', 'maintenance_agent', 'auditor'], true);
            $this->assertSame($expected, $user->canInOrganization('view_residences', $organization), $role);
            $other = Residence::factory()->create();
            $this->actingAs($user)->get(route('residences.show', $other))->assertNotFound();
            if ($role === 'administrator') {
                $target = User::factory()->create();
                $organization->users()->attach($target, ['role' => 'auditor']);
                $this->actingAs($user)->put(route('team.role', $target), ['role' => 'owner'])->assertForbidden();
            }
        }
    }

    public function test_archive_clears_context_preserves_dependencies_and_restore_is_owner_admin_only(): void
    {
        ['user' => $owner, 'org' => $organization, 'res' => $residence] = $this->context();
        $lot = Lot::factory()->for($residence)->create();
        $this->actingAs($owner)->post(route('residences.archive', $residence))->assertRedirect(route('residences.index'));
        $this->assertNull($owner->fresh()->current_residence_id);
        $this->assertDatabaseHas('lots', ['id' => $lot->id]);
        $this->actingAs($owner)->put(route('residences.update', $residence), $this->residencePayload($residence))->assertStatus(409);
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($manager, ['role' => 'manager']);
        $manager->update(['current_organization_id' => $organization->id]);
        $this->actingAs($manager)->post(route('residences.restore', $residence))->assertForbidden();
        $this->actingAs($owner)->post(route('residences.restore', $residence))->assertSessionHasNoErrors();
    }

    public function test_onboarding_activation_is_derived_transactional_and_status_cannot_be_posted(): void
    {
        ['user' => $owner, 'org' => $organization, 'res' => $residence] = $this->context();
        $this->actingAs($owner)->post(route('onboarding.activate'))->assertSessionHasErrors('activation');
        $lot = Lot::factory()->for($residence)->create();
        $contact = Contact::factory()->for($organization)->create();
        $lot->ownerships()->create(['contact_id' => $contact->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => now()->subDay()]);
        $residence->allocationKeys()->where('is_default', true)->first()->values()->create(['lot_id' => $lot->id, 'value' => 0]);
        $this->actingAs($owner)->post(route('onboarding.activate'))->assertRedirect(route('dashboard'));
        $this->assertSame('active', $residence->fresh()->status);
        $this->actingAs($owner)->put(route('residences.update', $residence), [...$this->residencePayload($residence), 'status' => 'archived'])->assertSessionHasNoErrors();
        $this->assertSame('active', $residence->fresh()->status);
    }

    public function test_bulk_allocations_are_atomic_and_special_keys_restrict_lots(): void
    {
        ['user' => $owner, 'res' => $residence] = $this->context();
        $first = Lot::factory()->for($residence)->create(['reference' => 'A-1']);
        $second = Lot::factory()->for($residence)->create(['reference' => 'A-2']);
        $key = $residence->allocationKeys()->create(['name' => 'Ascenseur', 'code' => 'lift', 'type' => 'special', 'applies_to_all_lots' => false]);
        $key->lots()->attach($first);
        $this->actingAs($owner)->post(route('allocations.bulk', $key), ['paste' => "A-1\t0.0000"])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lot_allocation_values', ['allocation_key_id' => $key->id, 'lot_id' => $first->id, 'value' => 0]);
        $this->actingAs($owner)->post(route('allocations.bulk', $key), ['paste' => "A-1\t20.0000\nA-2\tbroken"])->assertSessionHasErrors('paste');
        $this->assertDatabaseMissing('lot_allocation_values', ['allocation_key_id' => $key->id, 'lot_id' => $first->id, 'value' => 20]);
        $this->actingAs($owner)->post(route('allocations.bulk', $key), ['paste' => "A-2\t1.0000"])->assertSessionHasErrors('paste');
    }

    public function test_large_import_is_queued_with_explicit_context_and_retry_is_idempotent(): void
    {
        Queue::fake();
        config(['imports.synchronous_threshold' => 1]);
        ['user' => $owner, 'org' => $organization, 'res' => $residence] = $this->context();
        $batch = ImportBatch::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'user_id' => $owner->id, 'type' => 'lots', 'original_filename' => 'lots.csv', 'stored_path' => 'imports/lots.csv', 'status' => 'mapping', 'total_rows' => 2]);
        $this->actingAs($owner)->post(route('imports.confirm', $batch), ['mapping' => ['reference' => 'reference']])->assertRedirect(route('imports.index'));
        Queue::assertPushed(ProcessImportBatch::class, fn ($job) => $job->organizationId === $organization->id && $job->residenceId === $residence->id && $job->userId === $owner->id);
    }

    public function test_import_processing_retry_tenant_recheck_and_safe_rollback(): void
    {
        ['user' => $owner, 'org' => $organization, 'res' => $residence] = $this->context();
        Storage::put('imports/retry.csv', "reference,lot_number,type\nI-1,1,apartment\nI-2,2,parking\n");
        $batch = ImportBatch::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'user_id' => $owner->id, 'type' => 'lots', 'original_filename' => 'retry.csv', 'stored_path' => 'imports/retry.csv', 'status' => 'pending', 'total_rows' => 2, 'column_mapping' => ['reference' => 'reference', 'lot_number' => 'lot_number', 'type' => 'type']]);
        $job = new ProcessImportBatch($batch->id, $organization->id, $residence->id, $owner->id);
        $job->handle(app(ImportService::class));
        $this->assertSame(2, $residence->lots()->whereIn('reference', ['I-1', 'I-2'])->count(), $batch->rows()->pluck('error')->implode(' | '));
        $this->assertSame(2, $batch->fresh()->created_rows);
        $job->handle(app(ImportService::class));
        $this->assertSame(2, $residence->lots()->whereIn('reference', ['I-1', 'I-2'])->count());
        app(ImportService::class)->rollback($batch->fresh());
        $this->assertSame(0, $residence->lots()->whereIn('reference', ['I-1', 'I-2'])->count());
        $this->assertSame('rolled_back', $batch->fresh()->status);

        $isolated = ImportBatch::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'user_id' => $owner->id, 'type' => 'lots', 'original_filename' => 'retry.csv', 'stored_path' => 'imports/retry.csv', 'status' => 'pending', 'column_mapping' => ['reference' => 'reference']]);
        $organization->users()->detach($owner);
        $this->expectException(HttpException::class);
        (new ProcessImportBatch($isolated->id, $organization->id, $residence->id, $owner->id))->handle(app(ImportService::class));
    }

    public function test_import_rollback_blocks_created_records_with_later_dependencies(): void
    {
        ['user' => $owner, 'org' => $organization, 'res' => $residence] = $this->context();
        Storage::put('imports/blocked.csv', "reference,lot_number,type\nBLOCK-1,1,apartment\n");
        $batch = ImportBatch::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'user_id' => $owner->id, 'type' => 'lots', 'original_filename' => 'blocked.csv', 'stored_path' => 'imports/blocked.csv', 'status' => 'pending', 'total_rows' => 1, 'column_mapping' => ['reference' => 'reference', 'lot_number' => 'lot_number', 'type' => 'type']]);
        (new ProcessImportBatch($batch->id, $organization->id, $residence->id, $owner->id))->handle(app(ImportService::class));
        $lot = $residence->lots()->where('reference', 'BLOCK-1')->firstOrFail();
        $contact = Contact::factory()->for($organization)->create();
        $lot->ownerships()->create(['contact_id' => $contact->id, 'ownership_percentage' => 100, 'starts_on' => now()->toDateString()]);
        $report = app(ImportService::class)->rollback($batch->fresh());
        $this->assertSame(0, $report['reversed']);
        $this->assertSame('lot_has_later_dependencies', $report['blocked'][0]['reason']);
        $this->assertDatabaseHas('lots', ['id' => $lot->id]);
    }

    public function test_ownership_and_occupancy_date_boundaries_reject_overlaps_and_future_conflicts(): void
    {
        ['org' => $organization, 'res' => $residence] = $this->context();
        $lot = Lot::factory()->for($residence)->create();
        $first = Contact::factory()->for($organization)->create();
        $second = Contact::factory()->for($organization)->create();
        $lot->ownerships()->create(['contact_id' => $first->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2026-01-01']);
        $lot->ownerships()->create(['contact_id' => $second->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2027-01-01']);
        try {
            app(TransferOwnership::class)->execute($lot, ['effective_date' => '2026-08-01', 'owners' => [['contact_id' => $second->id, 'percentage' => '100.0000', 'is_primary' => true]]]);
            $this->fail('A future conflict must fail.');
        } catch (ValidationException) {
            $this->assertNull($lot->ownerships()->where('contact_id', $first->id)->first()->ends_on);
        }
        app(RecordOccupancy::class)->execute($lot, ['contact_id' => $first->id, 'type' => 'tenant', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_primary_occupant' => true]);
        $this->expectException(ValidationException::class);
        app(RecordOccupancy::class)->execute($lot, ['contact_id' => $first->id, 'type' => 'tenant', 'starts_on' => '2026-12-31', 'is_primary_occupant' => false]);
    }

    public function test_database_prevents_two_default_allocation_keys(): void
    {
        $residence = Residence::factory()->create();
        $this->expectException(QueryException::class);
        $residence->allocationKeys()->create(['name' => 'Second', 'code' => 'second', 'type' => 'general', 'is_default' => true, 'default_slot' => 1]);
    }

    public function test_guest_locale_switch_persists_and_arabic_is_rtl(): void
    {
        $this->get('/login?locale=ar')->assertSessionHas('locale', 'ar')->assertInertia(fn ($page) => $page->where('locale', 'ar'));
        $this->get('/forgot-password')->assertInertia(fn ($page) => $page->where('locale', 'ar'));
        $this->get('/login?locale=fr')->assertSessionHas('locale', 'fr')->assertInertia(fn ($page) => $page->where('locale', 'fr'));
    }

    private function context(string $role = 'owner'): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create();
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => true]);
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);

        return ['user' => $user, 'org' => $organization, 'res' => $residence];
    }

    private function residencePayload(Residence $residence): array
    {
        return ['name' => $residence->name, 'code' => $residence->code, 'address_line_1' => $residence->address_line_1, 'city' => $residence->city, 'default_language' => 'fr', 'fiscal_year_start_month' => 1, 'fiscal_year_start_day' => 1];
    }
}
