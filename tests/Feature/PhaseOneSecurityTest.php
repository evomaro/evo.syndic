<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Contact;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\ActivityScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PhaseOneSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $res = Residence::factory()->for($org)->create();
        $org->users()->attach($user, ['role' => $role, 'all_residences' => true]);
        $user->update(['current_organization_id' => $org->id, 'current_residence_id' => $res->id]);

        return compact('user', 'org', 'res');
    }

    public function test_user_cannot_access_another_organization_or_residence_by_id(): void
    {
        extract($this->context());
        $other = Residence::factory()->create();
        $this->actingAs($user)->get(route('residences.show', $other))->assertNotFound();
        $this->actingAs($user)->put(route('context.organization', $other->organization))->assertForbidden();
    }

    public function test_property_hierarchy_cannot_be_mixed_across_residences(): void
    {
        extract($this->context());
        $foreign = Building::factory()->create();
        $this->actingAs($user)->post(route('lots.store'), ['reference' => 'APT-X', 'lot_number' => 'X', 'type' => 'apartment', 'building_id' => $foreign->id, 'active' => true])->assertSessionHasErrors('building_id');
        $this->assertDatabaseMissing('lots', ['residence_id' => $res->id, 'reference' => 'APT-X']);
    }

    public function test_allocation_values_cannot_cross_residences(): void
    {
        extract($this->context());
        $foreignLot = Lot::factory()->create();
        $key = $res->allocationKeys()->first();
        $this->actingAs($user)->put(route('allocations.values', $key), ['values' => [['lot_id' => $foreignLot->id, 'value' => 100]]])->assertSessionHasErrors('values.0.lot_id');
    }

    public function test_unauthorized_role_cannot_manage_team(): void
    {
        extract($this->context('auditor'));
        $this->actingAs($user)->post(route('team.invite'), ['email' => 'person@example.test', 'role' => 'manager'])->assertForbidden();
    }

    public function test_archived_residence_cannot_be_modified(): void
    {
        extract($this->context());
        $res->update(['status' => 'archived']);
        $this->actingAs($user)->put(route('residences.update', $res), ['name' => 'Changed', 'code' => $res->code, 'address_line_1' => 'A', 'city' => 'Rabat', 'default_language' => 'fr', 'fiscal_year_start_month' => 1, 'fiscal_year_start_day' => 1])->assertStatus(409);
    }

    public function test_expired_or_reused_invitation_tokens_are_rejected(): void
    {
        extract($this->context());
        $invitee = User::factory()->create(['email' => 'invitee@example.test']);
        $token = 'secret-token';
        $invitation = TeamInvitation::create(['organization_id' => $org->id, 'email' => $invitee->email, 'role' => 'manager', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->subMinute(), 'invited_by' => $user->id]);
        $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertGone();
        $invitation->update(['expires_at' => now()->addDay()]);
        $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
        $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertGone();
    }

    public function test_contact_from_other_organization_cannot_be_assigned_as_owner(): void
    {
        extract($this->context());
        $lot = Lot::factory()->for($res)->create();
        $foreign = Contact::factory()->create();
        $this->actingAs($user)->post(route('ownerships.transfer', $lot), ['effective_date' => '2026-01-01', 'owners' => [['contact_id' => $foreign->id, 'percentage' => 100, 'is_primary' => true]]])->assertSessionHasErrors('owners.0.contact_id');
    }

    public function test_contact_search_does_not_leak_another_organization(): void
    {
        extract($this->context());
        $visible = Contact::factory()->for($org)->create(['first_name' => 'UniqueSearch']);
        $hidden = Contact::factory()->create(['first_name' => 'UniqueSearch']);

        $response = $this->actingAs($user)->getJson(route('search.contacts', ['search' => 'UniqueSearch']))->assertOk();

        $response->assertJsonFragment(['id' => $visible->id])->assertJsonMissing(['id' => $hidden->id]);
    }

    public function test_activity_scope_does_not_leak_another_organization(): void
    {
        extract($this->context());
        $visible = Contact::factory()->for($org)->create();
        $hidden = Contact::factory()->create();
        activity()->performedOn($visible)->log('visible');
        activity()->performedOn($hidden)->log('hidden');

        $activities = app(ActivityScope::class)->apply(Activity::query(), $org)->get();

        $this->assertTrue($activities->contains('description', 'visible'));
        $this->assertFalse($activities->contains('description', 'hidden'));
    }
}
