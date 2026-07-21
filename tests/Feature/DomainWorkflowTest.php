<?php

namespace Tests\Feature;

use App\Actions\RecordOccupancy;
use App\Actions\TransferOwnership;
use App\Models\Contact;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DomainWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_residence_automatically_gets_one_default_allocation_key(): void
    {
        $res = Residence::factory()->create();
        $this->assertSame(1, $res->allocationKeys()->where('is_default', true)->count());
        $this->assertSame('general', $res->allocationKeys()->first()->code);
    }

    public function test_joint_ownership_and_transfer_preserve_history(): void
    {
        $org = Organization::factory()->create();
        $res = Residence::factory()->for($org)->create();
        $lot = Lot::factory()->for($res)->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $old = Contact::factory()->for($org)->create();
        $a = Contact::factory()->for($org)->create();
        $b = Contact::factory()->for($org)->create();
        $lot->ownerships()->create(['contact_id' => $old->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2020-01-01']);
        app(TransferOwnership::class)->execute($lot, ['effective_date' => '2026-02-01', 'owners' => [['contact_id' => $a->id, 'percentage' => 60, 'is_primary' => true], ['contact_id' => $b->id, 'percentage' => 40, 'is_primary' => false]]]);
        $this->assertSame('2026-01-31', $lot->ownerships()->where('contact_id', $old->id)->first()->ends_on->toDateString());
        $this->assertEquals(100, $lot->activeOwnerships()->sum('ownership_percentage'));
        $this->assertSame(3, $lot->ownerships()->count());
    }

    public function test_incomplete_ownership_requires_explicit_acknowledgement(): void
    {
        $org = Organization::factory()->create();
        $lot = Lot::factory()->for(Residence::factory()->for($org))->create();
        $contact = Contact::factory()->for($org)->create();
        $this->expectException(ValidationException::class);
        app(TransferOwnership::class)->execute($lot, ['effective_date' => '2026-01-01', 'owners' => [['contact_id' => $contact->id, 'percentage' => 50]]]);
    }

    public function test_occupancy_history_updates_lot_status_without_changing_ownership(): void
    {
        $org = Organization::factory()->create();
        $lot = Lot::factory()->for(Residence::factory()->for($org))->create();
        $tenant = Contact::factory()->for($org)->create();
        app(RecordOccupancy::class)->execute($lot, ['contact_id' => $tenant->id, 'type' => 'tenant', 'starts_on' => '2026-01-01', 'is_primary_occupant' => true]);
        $this->assertSame('rented', $lot->fresh()->occupancy_status);
        $this->assertSame(0, $lot->ownerships()->count());
        app(RecordOccupancy::class)->close($lot, $lot->occupancies()->first()->id, '2026-06-01');
        $this->assertSame('vacant', $lot->fresh()->occupancy_status);
        $this->assertNotNull($lot->occupancies()->first()->ends_on);
    }

    public function test_residence_operational_rules_are_real(): void
    {
        $org = Organization::factory()->create();
        $res = Residence::factory()->for($org)->create();
        $lot = Lot::factory()->for($res)->create();
        $this->assertFalse($res->isOperational());
        $res->update(['ownership_incomplete_acknowledged' => true, 'allocations_deferred' => true]);
        $this->assertTrue($res->fresh()->isOperational());
    }
}
