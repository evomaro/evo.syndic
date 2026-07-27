<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\Contact;
use App\Models\FinancialExercise;
use App\Models\GovernanceDocument;
use App\Models\GovernanceMandate;
use App\Models\Lot;
use App\Models\LotAllocationValue;
use App\Models\LotOwnership;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Services\AgendaService;
use App\Services\AssemblyWorkflow;
use App\Services\AttendanceProxyService;
use App\Services\BallotService;
use App\Services\ConvocationService;
use App\Services\ElectorateSnapshotService;
use App\Services\GovernanceDocumentService;
use App\Services\GovernanceMandateService;
use App\Services\GovernancePortalAccessService;
use App\Services\GovernanceRuleService;
use App\Services\MinutesService;
use App\Services\QuorumService;
use App\Services\ResolutionExecutionService;
use App\Services\VotingRuleEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseFiveGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-22 10:00:00');
        Notification::fake();
    }

    private function context(int $owners = 4): array
    {
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $manager = User::factory()->create(['preferred_language' => 'fr']);
        $organization->users()->attach($manager, ['role' => 'owner', 'all_residences' => true]);
        $manager->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $key = $residence->allocationKeys()->where('is_default', true)->firstOrFail();
        $ownerUsers = collect();
        $contacts = collect();
        for ($i = 1; $i <= $owners; $i++) {
            $user = User::factory()->create(['preferred_language' => $i === 2 ? 'ar' : 'fr']);
            $organization->users()->attach($user, ['role' => 'coproprietaire', 'all_residences' => false]);
            $residence->users()->attach($user);
            $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
            $contact = Contact::factory()->for($organization)->create(['first_name' => "Owner{$i}", 'last_name' => 'Test', 'address' => "Adresse {$i}", 'primary_email' => $user->email]);
            $user->contacts()->attach($contact, ['organization_id' => $organization->id, 'linked_by' => $manager->id, 'linked_at' => now()]);
            $lot = Lot::factory()->for($residence)->create(['reference' => "LOT-{$i}", 'active' => true]);
            LotOwnership::create(['lot_id' => $lot->id, 'contact_id' => $contact->id, 'ownership_percentage' => '100.0000', 'is_primary_contact' => true, 'starts_on' => '2025-01-01']);
            LotAllocationValue::create(['allocation_key_id' => $key->id, 'lot_id' => $lot->id, 'value' => '250.0000']);
            $ownerUsers->push($user);
            $contacts->push($contact);
        }
        $mandate = GovernanceMandate::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'user_id' => $manager->id, 'role' => 'syndic', 'starts_on' => '2026-01-01', 'ends_on' => '2027-12-31', 'status' => 'active', 'active_slot' => 'syndic', 'activated_at' => now(), 'activated_by' => $manager->id]);
        $exercise = FinancialExercise::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        return compact('organization', 'residence', 'manager', 'ownerUsers', 'contacts', 'mandate', 'exercise');
    }

    private function prepared(array $c, string $ruleIdentifier = 'article_20_relative_majority'): Assembly
    {
        $assembly = Assembly::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'financial_exercise_id' => $c['exercise']->id, 'governance_mandate_id' => $c['mandate']->id, 'reference' => uniqid('AG-'), 'type' => 'ordinary', 'convocation_number' => 1, 'status' => 'draft', 'convening_authority' => 'Syndic', 'meeting_date' => '2026-08-20', 'starts_at' => '18:00', 'expected_ends_at' => '20:00', 'location' => 'Salle commune', 'timezone' => 'Africa/Casablanca', 'convocation_deadline_at' => '2026-08-05 00:00:00', 'documents_available_at' => '2026-08-05 00:00:00', 'created_by' => $c['manager']->id]);
        app(AssemblyWorkflow::class)->transition($assembly, 'preparing', $c['manager'], null, 'prepare');
        $rule = app(GovernanceRuleService::class)->ensureVersions()[$ruleIdentifier];
        app(AgendaService::class)->add($assembly, ['display_order' => 1, 'title_fr' => 'Travaux de sécurité', 'title_ar' => 'أشغال السلامة', 'proposed_text_fr' => 'Approuver les travaux', 'proposed_text_ar' => 'الموافقة على الأشغال', 'category' => 'maintenance', 'resident_visible' => true, 'resolution' => ['code' => 'R1', 'category' => 'maintenance', 'proposed_text_fr' => 'Approuver les travaux', 'proposed_text_ar' => 'الموافقة على الأشغال']], $rule, $c['manager']);
        app(ElectorateSnapshotService::class)->generate($assembly, $c['manager']);
        app(AgendaService::class)->freeze($assembly, $c['manager']);

        return $assembly->fresh();
    }

    public function test_exact_voting_rule_boundary_matrix(): void
    {
        $rules = app(GovernanceRuleService::class)->ensureVersions();
        $engine = app(VotingRuleEngine::class);
        $this->assertFalse($engine->decide($rules['article_20_relative_majority'], 499, 1000));
        $this->assertFalse($engine->decide($rules['article_20_relative_majority'], 500, 1000));
        $this->assertTrue($engine->decide($rules['article_20_relative_majority'], 501, 1000));
        $this->assertFalse($engine->decide($rules['article_21_three_quarters'], 749, 1000));
        $this->assertTrue($engine->decide($rules['article_21_three_quarters'], 750, 1000));
        $this->assertTrue($engine->decide($rules['article_21_three_quarters'], 751, 1000));
        $this->assertFalse($engine->decide($rules['article_22_unanimity'], 999, 1000));
        $this->assertTrue($engine->decide($rules['article_22_unanimity'], 1000, 1000));
    }

    public function test_electorate_is_frozen_and_correction_is_append_only(): void
    {
        $c = $this->context();
        $assembly = $this->prepared($c);
        $first = $assembly->electorate()->orderBy('id')->firstOrFail();
        $weight = $first->voting_weight_numerator;
        LotOwnership::where('contact_id', $first->contact_id)->update(['ends_on' => '2026-08-21']);
        $this->assertSame($weight, $first->fresh()->voting_weight_numerator);
        app(ElectorateSnapshotService::class)->correct($first, ['eligibility_status' => 'restricted', 'restriction_reason' => 'Décision documentée'], $c['manager'], 'Correction documentée après vérification');
        $this->assertDatabaseHas('electorate_corrections', ['electorate_id' => $first->id]);
        $this->assertSame(2, $first->fresh()->snapshot_version);
    }

    public function test_convocation_is_hashed_immutable_and_delivery_idempotent(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $assembly = $this->prepared($c);
        $service = app(ConvocationService::class);
        $convocation = $service->issue($assembly, $c['manager']);
        $this->assertSame('convocation_issued', $assembly->fresh()->status);
        $this->assertCount(4, $convocation->recipients);
        $this->assertTrue(Storage::disk('local')->exists($convocation->path));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($convocation->path)), $convocation->checksum);
        $recipient = $convocation->recipients->first();
        $service->recordDelivery($recipient, 'registered_mail', 'successful', $c['manager']);
        $service->recordDelivery($recipient, 'registered_mail', 'successful', $c['manager']);
        $this->assertSame(1, $recipient->attempts()->whereNotNull('success_key')->count());
    }

    public function test_first_quorum_blocks_then_second_convocation_has_no_headcount_quorum(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $first = $this->prepared($c);
        app(ConvocationService::class)->issue($first, $c['manager']);
        app(AssemblyWorkflow::class)->transition($first, 'scheduled', $c['manager'], null, 'schedule');
        $one = $first->electorate()->first();
        app(AttendanceProxyService::class)->record($first, $one, 'present', $c['manager']);
        $rule = app(GovernanceRuleService::class)->ensureVersions()['article_20_relative_majority'];
        $q = app(QuorumService::class)->calculate($first, $rule, $c['manager']);
        $this->assertFalse($q->quorum_met);
        app(AssemblyWorkflow::class)->transition($first, 'adjourned_no_quorum', $c['manager'], 'Quorum légal insuffisant constaté', 'adjourn');
        $second = app(AssemblyWorkflow::class)->secondConvocation($first, ['reference' => 'AG-SECOND', 'meeting_date' => '2026-08-28', 'starts_at' => '18:00'], $c['manager']);
        $this->assertSame(2, $second->convocation_number);
        $this->assertSame($first->id, $second->parent_assembly_id);
        $this->assertSame('replaced_by_second_convocation', $first->fresh()->status);
    }

    public function test_duplicate_ballot_is_rejected_correction_is_audited_and_result_is_stable(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $a = $this->prepared($c);
        app(ConvocationService::class)->issue($a, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'scheduled', $c['manager'], null, 'schedule');
        foreach ($a->electorate as $e) {
            app(AttendanceProxyService::class)->record($a, $e, 'present', $c['manager']);
        }$rule = app(GovernanceRuleService::class)->ensureVersions()['article_20_relative_majority'];
        app(QuorumService::class)->calculate($a, $rule, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'in_session', $c['manager'], null, 'open');
        $resolution = $a->resolutions()->first();
        $service = app(BallotService::class);
        $ballot = $service->enter($resolution, $a->electorate->first(), 'against', $c['manager']);
        $service->correct($ballot, 'for', $c['manager'], 'Erreur de saisie vérifiée sur feuille');
        try {
            $service->enter($resolution, $a->electorate->first(), 'against', $c['manager']);
            $this->fail('Duplicate ballot accepted');
        } catch (ValidationException) {
        }
        foreach ($a->electorate->skip(1) as $e) {
            $service->enter($resolution, $e, 'for', $c['manager']);
        }$result = $service->finalize($resolution, $c['manager']);
        $again = $service->finalize($resolution, $c['manager']);
        $this->assertTrue($result->adopted);
        $this->assertSame($result->id, $again->id);
        $this->assertDatabaseCount('ballot_corrections', 1);
        $this->assertSame(1, $resolution->results()->count());
        $this->expectException(ValidationException::class);
        $service->correct($ballot, 'against', $c['manager'], 'Tentative après finalisation interdite');
    }

    public function test_minutes_are_signed_from_frozen_payload_and_execution_is_explicit(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $a = $this->prepared($c);
        app(ConvocationService::class)->issue($a, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'scheduled', $c['manager'], null, 'schedule');
        $a->update(['chairperson_contact_id' => $c['contacts']->first()->id, 'secretary_user_id' => $c['manager']->id]);
        foreach ($a->electorate as $e) {
            app(AttendanceProxyService::class)->record($a, $e, 'present', $c['manager']);
        }$rule = app(GovernanceRuleService::class)->ensureVersions()['article_20_relative_majority'];
        app(QuorumService::class)->calculate($a, $rule, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'in_session', $c['manager'], null, 'open');
        $resolution = $a->resolutions()->first();
        foreach ($a->electorate as $e) {
            app(BallotService::class)->enter($resolution, $e, 'for', $c['manager']);
        }app(BallotService::class)->finalize($resolution, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'deliberations_completed', $c['manager'], null, 'close-votes');
        $version = app(MinutesService::class)->prepare($a->fresh(), ['reservations_fr' => 'Aucune'], $c['manager']);
        app(MinutesService::class)->review($version, $c['manager']);
        $signed = app(MinutesService::class)->sign($version->minutes, ['chairperson' => 'Owner1 Test', 'secretary' => 'Manager', 'method' => 'wet_signature_recorded'], $c['manager']);
        $this->assertSame('signed', $signed->status);
        $this->assertTrue(Storage::disk('local')->exists($signed->path));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($signed->path)), $signed->checksum);
        $action = app(ResolutionExecutionService::class)->create($resolution->fresh(), ['action_type' => 'maintenance_request', 'description' => 'Créer une demande de maintenance sans la valider', 'source_key' => 'maintenance-r1'], $c['manager']);
        $this->assertSame('pending', $action->status);
        $this->assertNull($action->related_id);
    }

    public function test_owner_document_room_is_private_scoped_and_no_store(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $a = $this->prepared($c);
        $document = GovernanceDocument::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'assembly_id' => $a->id, 'category' => 'resolution_project', 'title_fr' => 'Projet', 'audience' => 'owners']);
        $service = app(GovernanceDocumentService::class);
        $version = $service->storeVersion($document, UploadedFile::fake()->createWithContent('projet.pdf', '%PDF-1.4 governance'), $c['manager']);
        $service->publish($document, $version, $c['manager']);
        $owner = $c['ownerUsers']->first();
        $this->actingAs($owner)->get(route('governance.documents.download', $version))->assertOk()->assertHeader('x-content-type-options', 'nosniff')->assertHeader('cache-control', 'max-age=0, no-store, private');
        $outsider = User::factory()->create();
        $c['organization']->users()->attach($outsider, ['role' => 'coproprietaire', 'all_residences' => false]);
        $c['residence']->users()->attach($outsider);
        $outsider->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        $this->actingAs($outsider)->get(route('owner-governance.show', $a))->assertForbidden();
        $this->actingAs($outsider)->get(route('governance.documents.download', $version))->assertNotFound();
        $this->actingAs($owner)->post(route('owner-governance.questions.store', $a), ['question_fr' => 'Ajouter un point de sécurité'])->assertRedirect();
    }

    public function test_cross_residence_manager_urls_are_hidden(): void
    {
        $one = $this->context();
        $two = $this->context();
        $assembly = $this->prepared($one);
        $this->actingAs($two['manager'])->get(route('governance.show', $assembly))->assertNotFound();
        $this->actingAs($two['manager'])->post(route('governance.freeze', $assembly))->assertNotFound();
    }

    public function test_proxy_weight_limit_revocation_and_attendance_authority_transfer(): void
    {
        Storage::fake('local');
        $c = $this->context(20);
        $assembly = $this->prepared($c);
        app(ConvocationService::class)->issue($assembly, $c['manager']);
        app(AssemblyWorkflow::class)->transition($assembly, 'scheduled', $c['manager'], null, 'schedule');
        $service = app(AttendanceProxyService::class);
        $representative = $c['ownerUsers']->last();
        $principals = $assembly->electorate()->whereKeyNot($assembly->electorate()->where('contact_id', $c['contacts']->last()->id)->value('id'))->take(3)->get();
        $one = $service->submitProxy($assembly, $principals[0], $representative, null, UploadedFile::fake()->createWithContent('one.pdf', '%PDF-1.4 proxy'), $c['manager']);
        $two = $service->submitProxy($assembly, $principals[1], $representative, null, UploadedFile::fake()->createWithContent('two.pdf', '%PDF-1.4 proxy'), $c['manager']);
        $three = $service->submitProxy($assembly, $principals[2], $representative, null, UploadedFile::fake()->createWithContent('three.pdf', '%PDF-1.4 proxy'), $c['manager']);
        $service->verify($one, $c['manager']);
        $service->verify($two, $c['manager']);
        $this->assertSame('represented', $principals[0]->attendance->fresh()->status);
        try {
            $service->verify($three, $c['manager']);
            $this->fail('Proxy weight cap was bypassed.');
        } catch (ValidationException) {
        }
        $service->revoke($one, $c['manager'], 'Révocation écrite reçue du mandant');
        $this->assertSame('revoked', $one->fresh()->status);
        $this->assertSame('absent', $principals[0]->attendance->fresh()->status);
        $this->assertDatabaseCount('assembly_proxy_events', 3);
    }

    public function test_late_convocation_and_agenda_question_deadlines_are_enforced(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $assembly = $this->prepared($c);
        $assembly->update(['meeting_date' => '2026-07-30', 'convocation_deadline_at' => '2026-07-15 00:00:00']);
        try {
            app(ConvocationService::class)->issue($assembly, $c['manager']);
            $this->fail('Late issuance was accepted.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('convocations', 0);
        $assembly->update(['meeting_date' => '2026-07-22']);
        $owner = $c['ownerUsers']->first();
        $this->actingAs($owner)->post(route('owner-governance.questions.store', $assembly), ['question_fr' => 'Question tardive'])->assertSessionHasErrors('question');
    }

    public function test_signed_minutes_block_snapshot_correction_and_original_pdf_replacement(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $a = $this->prepared($c);
        app(ConvocationService::class)->issue($a, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'scheduled', $c['manager'], null, 'schedule');
        $a->update(['chairperson_contact_id' => $c['contacts']->first()->id, 'secretary_user_id' => $c['manager']->id]);
        foreach ($a->electorate as $e) {
            app(AttendanceProxyService::class)->record($a, $e, 'present', $c['manager']);
        }$rule = app(GovernanceRuleService::class)->ensureVersions()['article_20_relative_majority'];
        app(QuorumService::class)->calculate($a, $rule, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'in_session', $c['manager'], null, 'open');
        $resolution = $a->resolutions()->first();
        foreach ($a->electorate as $e) {
            app(BallotService::class)->enter($resolution, $e, 'for', $c['manager']);
        }app(BallotService::class)->finalize($resolution, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'deliberations_completed', $c['manager'], null, 'done');
        $v = app(MinutesService::class)->prepare($a->fresh(), [], $c['manager']);
        app(MinutesService::class)->review($v, $c['manager']);
        app(MinutesService::class)->sign($v->minutes, ['chairperson' => 'Chair', 'secretary' => 'Secretary', 'method' => 'wet_signature_recorded'], $c['manager']);
        $before = Storage::disk('local')->get($v->path);
        try {
            app(ElectorateSnapshotService::class)->correct($a->electorate->first(), ['eligibility_status' => 'restricted'], $c['manager'], 'Correction interdite après signature');
            $this->fail('Signed snapshot changed.');
        } catch (ValidationException) {
        }
        $annex = app(MinutesService::class)->correctiveAnnex($v->minutes, 'Rectification orthographique documentée', 'Correction sans altération du vote', null, $c['manager']);
        $this->assertSame($before, Storage::disk('local')->get($v->path));
        $this->assertSame('corrective_annex', $annex->kind);
        $this->assertSame($v->id, $annex->parent_version_id);
    }

    public function test_exceptional_result_reopening_versions_without_overwriting_history(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $a = $this->prepared($c);
        app(ConvocationService::class)->issue($a, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'scheduled', $c['manager'], null, 'schedule');
        foreach ($a->electorate as $e) {
            app(AttendanceProxyService::class)->record($a, $e, 'present', $c['manager']);
        } $rule = app(GovernanceRuleService::class)->ensureVersions()['article_20_relative_majority'];
        app(QuorumService::class)->calculate($a, $rule, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'in_session', $c['manager'], null, 'open');
        $resolution = $a->resolutions()->first();
        foreach ($a->electorate as $e) {
            app(BallotService::class)->enter($resolution, $e, 'for', $c['manager']);
        } $first = app(BallotService::class)->finalize($resolution, $c['manager']);
        $firstChecksum = $first->checksum;
        app(BallotService::class)->reopen($resolution->fresh(), $c['manager'], 'Erreur matérielle exceptionnelle documentée');
        $ballot = $resolution->ballots()->first();
        app(BallotService::class)->correct($ballot, 'against', $c['manager'], 'Correction autorisée après réouverture');
        $second = app(BallotService::class)->finalize($resolution->fresh(), $c['manager']);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_result_id);
        $this->assertSame($firstChecksum, $first->fresh()->checksum);
        $this->assertSame(2, $resolution->results()->count());
        $this->assertDatabaseHas('ballot_corrections', ['ballot_id' => $ballot->id, 'to_choice' => 'against']);
    }

    public function test_owner_access_requires_current_ownership_and_verified_proxy_is_assembly_scoped(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $a = $this->prepared($c);
        app(ConvocationService::class)->issue($a, $c['manager']);
        app(AssemblyWorkflow::class)->transition($a, 'scheduled', $c['manager'], null, 'schedule');
        $principal = $a->electorate()->first();
        $former = $c['ownerUsers']->first();
        LotOwnership::where('contact_id', $principal->contact_id)->update(['ends_on' => '2026-07-21']);
        $this->actingAs($former)->get(route('owner-governance.show', $a))->assertForbidden();
        $proxyUser = User::factory()->create();
        $c['organization']->users()->attach($proxyUser, ['role' => 'resident', 'all_residences' => false]);
        $c['residence']->users()->attach($proxyUser);
        $proxyUser->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        app(ElectorateSnapshotService::class)->correct($principal, ['voting_weight_numerator' => 80_000_000], $c['manager'], 'Correction de quote-part documentée avant mandat');
        $proxy = app(AttendanceProxyService::class)->submitProxy($a, $principal->fresh(), $proxyUser, null, UploadedFile::fake()->createWithContent('proxy.pdf', '%PDF-1.4 proxy'), $c['manager']);
        app(AttendanceProxyService::class)->verify($proxy, $c['manager']);
        $this->assertTrue(app(GovernancePortalAccessService::class)->isProxyOnly($a, $proxyUser));
        $this->actingAs($proxyUser)->get(route('owner-governance.show', $a))->assertOk();
        $this->actingAs($proxyUser)->post(route('owner-governance.questions.store', $a), ['question_fr' => 'Tentative hors qualité de propriétaire'])->assertForbidden();
    }

    public function test_mandate_active_slot_prevents_overlap_and_preserves_history(): void
    {
        $c = $this->context();
        $candidate = app(GovernanceMandateService::class)->create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'user_id' => $c['ownerUsers']->first()->id, 'role' => 'syndic', 'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31'], $c['manager']);
        try {
            app(GovernanceMandateService::class)->transition($candidate, 'active', $c['manager'], 'Nomination approuvée et documentée');
            $this->fail('Overlapping syndic mandate accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame('draft',$candidate->fresh()->status);
        $this->assertDatabaseHas('governance_mandates',['id' => $c['mandate']->id, 'status' => 'active', 'active_slot' => 'syndic']);
    }

    public function test_governance_routes_are_non_cacheable_and_cross_scope_actions_are_hidden(): void
    {
        $one = $this->context();
        $two = $this->context();
        $assembly = $this->prepared($one);
        $electorate = $assembly->electorate()->first();
        $this->actingAs($two['manager'])->post(route('governance.electorate.correct',$electorate),['eligibility_status' => 'restricted', 'reason' => 'Tentative inter-résidence interdite'])->assertNotFound();
        $this->actingAs($one['ownerUsers']->first())->get(route('owner-governance.show',$assembly))->assertOk()->assertHeader('cache-control','max-age=0, no-store, private');
    }
}
