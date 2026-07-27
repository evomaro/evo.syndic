<?php

namespace Tests\Feature;

use App\Jobs\DispatchConvocationAvailability;
use App\Models\Assembly;
use App\Models\AssemblyAgendaItem;
use App\Models\AssemblyParticipant;
use App\Models\AssemblyResolution;
use App\Models\Contact;
use App\Models\Convocation;
use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Models\GovernanceRule;
use App\Models\GovernanceRuleSource;
use App\Models\GovernanceRuleVersion;
use App\Models\GovernanceVotingShareSource;
use App\Models\Lot;
use App\Models\LotOccupancy;
use App\Models\LotOwnership;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\ResolutionRuleSnapshot;
use App\Models\User;
use App\Services\AttendanceProxyService;
use App\Services\GovernanceDeliveryScheduler;
use App\Services\GovernanceExportService;
use App\Services\GovernanceIntegrityAuditService;
use App\Services\GovernanceNotificationService;
use App\Services\PhaseSevenEligibilityService;
use App\Services\PhaseSevenGovernanceWorkflow;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseSevenHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-26 12:00:00');
        Storage::fake('local');
    }

    private function context(): array
    {
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $otherResidence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner', 'all_residences' => true]);
        $organization->users()->attach($manager, ['role' => 'manager', 'all_residences' => true]);
        foreach ([$owner, $manager] as $user) {
            $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        }

        return compact('organization', 'residence', 'otherResidence', 'owner', 'manager');
    }

    private function assembly(array $context, string $reference): Assembly
    {
        return Assembly::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'reference' => $reference,
            'type' => 'ordinary',
            'convocation_number' => 1,
            'status' => 'preparing',
            'convening_authority' => 'Synthetic technical QA',
            'meeting_date' => '2025-07-15',
            'eligibility_on' => '2025-06-30',
            'starts_at' => '18:00',
            'location' => 'Synthetic QA room',
            'timezone' => 'Africa/Casablanca',
            'convocation_deadline_at' => '2025-06-30',
            'documents_available_at' => '2025-06-30',
            'created_by' => $context['owner']->id,
        ]);
    }

    private function source(array $context, array $shares, int $denominator = 10000): GovernanceVotingShareSource
    {
        return GovernanceVotingShareSource::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'code' => 'QA-GOV-'.uniqid(),
            'version' => 1,
            'source_type' => 'dedicated_governance_shares',
            'status' => 'approved',
            'decimal_precision' => 4,
            'expected_total' => '100.0000',
            'denominator' => $denominator,
            'configuration' => ['shares' => $shares],
            'verified_by' => $context['owner']->id,
            'verified_at' => now(),
            'approved_by' => $context['owner']->id,
            'approved_at' => now(),
            'effective_from' => '2025-01-01',
        ]);
    }

    private function eligibleAssembly(array $context, string $reference = 'AG-HARDENING'): array
    {
        $assembly = $this->assembly($context, $reference);
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-'.$reference, 'active' => true]);
        $contact = Contact::factory()->for($context['organization'])->create();
        LotOwnership::create([
            'lot_id' => $lot->id,
            'contact_id' => $contact->id,
            'ownership_percentage' => '100.0000',
            'starts_on' => '2024-01-01',
        ]);
        $snapshot = app(PhaseSevenEligibilityService::class)->generate(
            $assembly,
            $this->source($context, [$lot->reference => '100.0000']),
            $context['owner'],
        );

        return compact('assembly', 'lot', 'contact', 'snapshot');
    }

    private function rule(array $context, bool $active = true): GovernanceRuleVersion
    {
        $source = GovernanceRuleSource::create([
            'organization_id' => $context['organization']->id,
            'code' => 'QA-SOURCE-'.uniqid(),
            'version' => 1,
            'jurisdiction' => 'MA',
            'official_title' => 'Synthetic source for technical tests',
            'official_url' => 'https://example.invalid/synthetic',
            'confidence' => 'source_verified',
            'verified_by' => $context['owner']->id,
            'last_verified_on' => today(),
        ]);
        $rule = GovernanceRule::create([
            'organization_id' => $context['organization']->id,
            'stable_code' => 'QA-RULE-'.uniqid(),
            'jurisdiction' => 'MA',
            'assembly_type' => 'ordinary',
            'resolution_category' => 'qa',
            'title_fr' => 'Règle synthétique',
            'title_ar' => 'قاعدة اصطناعية',
        ]);

        return $rule->versions()->create([
            'governance_rule_source_id' => $source->id,
            'identifier' => $rule->stable_code,
            'version' => 1,
            'effective_from' => '2025-01-01',
            'official_source' => $source->official_title,
            'source_url' => $source->official_url,
            'review_status' => $active ? 'professionally_reviewed' : 'pending_professional_review',
            'numerator_definition' => 'present_represented_weight',
            'denominator_definition' => 'all_eligible_weight',
            'threshold_numerator' => 1,
            'threshold_denominator' => 2,
            'comparison' => 'gte',
            'quorum_rule' => 'configured_formula',
            'abstention_behavior' => 'included_in_denominator',
            'invalid_ballot_behavior' => 'excluded',
            'second_convocation_behavior' => 'unverified',
            'proxy_restrictions' => [],
            'eligibility_restrictions' => [],
            'legal_payload' => ['certified' => false],
            'status' => $active ? 'active' : 'unverified_draft',
            'confidence' => $active ? 'professionally_reviewed' : 'unverified_draft',
            'assembly_type' => 'ordinary',
            'resolution_category' => 'qa',
            'title_fr' => 'Règle QA',
            'title_ar' => 'قاعدة اختبار',
            'rounding_policy' => 'none',
            'voting_share_source_type' => 'dedicated_governance_shares',
            'effective_date_policy' => 'meeting_date',
            'active' => $active,
            'immutable_at' => $active ? now() : null,
        ]);
    }

    private function evidence(array $context, Assembly $assembly): GovernanceDocumentVersion
    {
        $content = 'synthetic governance evidence';
        $path = "qa/governance/{$assembly->id}/evidence.txt";
        Storage::disk('local')->put($path, $content);
        $document = GovernanceDocument::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'assembly_id' => $assembly->id,
            'category' => 'technical_evidence',
            'title_fr' => 'Preuve synthétique',
            'title_ar' => 'دليل اصطناعي',
            'audience' => 'managers',
        ]);

        return GovernanceDocumentVersion::create([
            'governance_document_id' => $document->id,
            'version' => 1,
            'name' => 'evidence.txt',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size' => strlen($content),
            'checksum' => hash('sha256', $content),
            'uploaded_by' => $context['owner']->id,
            'frozen_at' => now(),
        ]);
    }

    public function test_occupancy_is_not_treated_as_historical_ownership(): void
    {
        $context = $this->context();
        $assembly = $this->assembly($context, 'AG-OCCUPANCY');
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-OCCUPIED']);
        $occupant = Contact::factory()->for($context['organization'])->create();
        LotOccupancy::create([
            'lot_id' => $lot->id,
            'contact_id' => $occupant->id,
            'type' => 'tenant',
            'is_primary_occupant' => true,
            'starts_on' => '2024-01-01',
        ]);

        $snapshot = app(PhaseSevenEligibilityService::class)->generate(
            $assembly,
            $this->source($context, ['LOT-OCCUPIED' => '100.0000']),
            $context['owner'],
        );

        $this->assertSame('indeterminate', $snapshot->status);
        $this->assertCount(0, $snapshot->interests);
        $this->assertContains('missing_historical_ownership', collect($snapshot->findings)->pluck('code'));
    }

    public function test_overlapping_ownership_and_zero_denominator_block_eligibility_review(): void
    {
        $context = $this->context();
        $assembly = $this->assembly($context, 'AG-OVERLAP');
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-OVERLAP']);
        foreach (['60.0000', '60.0000'] as $percentage) {
            LotOwnership::create([
                'lot_id' => $lot->id,
                'contact_id' => Contact::factory()->for($context['organization'])->create()->id,
                'ownership_percentage' => $percentage,
                'starts_on' => '2024-01-01',
            ]);
        }

        $snapshot = app(PhaseSevenEligibilityService::class)->generate(
            $assembly,
            $this->source($context, ['LOT-OVERLAP' => '100.0000'], 0),
            $context['owner'],
        );

        $codes = collect($snapshot->findings)->pluck('code');
        $this->assertSame('indeterminate', $snapshot->status);
        $this->assertContains('ownership_total_exceeds_100', $codes);
        $this->assertContains('invalid_voting_share_denominator', $codes);
    }

    public function test_identical_historical_inputs_produce_the_same_fingerprint(): void
    {
        $context = $this->context();
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-FINGERPRINT']);
        $contact = Contact::factory()->for($context['organization'])->create();
        LotOwnership::create([
            'lot_id' => $lot->id,
            'contact_id' => $contact->id,
            'ownership_percentage' => '100.0000',
            'starts_on' => '2024-01-01',
        ]);
        $source = $this->source($context, ['LOT-FINGERPRINT' => '100.0000']);
        $first = app(PhaseSevenEligibilityService::class)->generate($this->assembly($context, 'AG-FP-1'), $source, $context['owner']);
        $second = app(PhaseSevenEligibilityService::class)->generate($this->assembly($context, 'AG-FP-2'), $source, $context['owner']);

        $this->assertSame($first->input_fingerprint, $second->input_fingerprint);
    }

    public function test_convocation_availability_scheduler_is_bounded_queued_and_does_not_change_legal_delivery(): void
    {
        Queue::fake();
        $context = $this->context();
        ['assembly' => $assembly, 'snapshot' => $snapshot] = $this->eligibleAssembly($context, 'AG-QUEUE');
        $convocation = Convocation::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'assembly_id' => $assembly->id,
            'issued_at' => now()->subMinute(),
            'issued_by' => $context['owner']->id,
            'legal_deadline_at' => now()->subDay(),
            'path' => 'synthetic/convocation.pdf',
            'checksum' => hash('sha256', 'synthetic'),
            'frozen_payload' => ['classification' => 'technical'],
            'legal_service_status' => 'unverified',
        ]);
        $recipient = $convocation->recipients()->create([
            'electorate_id' => $snapshot->interests->first()->id,
            'recipient_name_snapshot' => 'Synthetic recipient',
            'status' => 'pending',
        ]);

        $result = app(GovernanceDeliveryScheduler::class)->dispatch(
            CarbonImmutable::now(),
            $context['owner'],
            true,
            1,
        );

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['queued']);
        $this->assertFalse($result['legal_delivery_status_changed']);
        $this->assertSame('pending', $recipient->fresh()->status);
        Queue::assertPushed(DispatchConvocationAvailability::class, 1);
    }

    public function test_populated_governance_audits_are_read_only_and_check_real_records(): void
    {
        $context = $this->context();
        ['assembly' => $assembly] = $this->eligibleAssembly($context, 'AG-AUDIT-VALID');
        $this->evidence($context, $assembly);
        $before = [
            'assemblies' => Assembly::count(),
            'documents' => GovernanceDocumentVersion::count(),
        ];
        $audit = app(GovernanceIntegrityAuditService::class);

        $assemblies = $audit->assemblies(['organization' => $context['organization']->id]);
        $eligibility = $audit->eligibility(['assembly' => $assembly->id]);
        $evidence = $audit->evidence(['assembly' => $assembly->id]);

        $this->assertSame(1, $assemblies['checked']);
        $this->assertTrue($assemblies['ok']);
        $this->assertSame(1, $eligibility['checked']);
        $this->assertTrue($eligibility['ok']);
        $this->assertSame(1, $evidence['checked']);
        $this->assertTrue($evidence['ok']);
        $this->assertSame($before, [
            'assemblies' => Assembly::count(),
            'documents' => GovernanceDocumentVersion::count(),
        ]);
    }

    public function test_populated_audits_detect_scope_delivery_and_checksum_corruption(): void
    {
        $context = $this->context();
        ['assembly' => $assembly, 'snapshot' => $snapshot] = $this->eligibleAssembly($context, 'AG-AUDIT-BROKEN');
        $version = $this->evidence($context, $assembly);
        Storage::disk('local')->put($version->path, 'tampered');
        AssemblyParticipant::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['otherResidence']->id,
            'assembly_id' => $assembly->id,
            'electorate_id' => $snapshot->interests->first()->id,
            'participant_key' => 'cross-residence',
            'display_name_snapshot' => 'Synthetic participant',
            'capacity' => 'owner',
        ]);
        $convocation = Convocation::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'assembly_id' => $assembly->id,
            'issued_at' => now(),
            'issued_by' => $context['owner']->id,
            'legal_deadline_at' => now(),
            'path' => 'synthetic/convocation.pdf',
            'checksum' => hash('sha256', 'synthetic'),
            'frozen_payload' => [],
        ]);
        $recipient = $convocation->recipients()->create([
            'electorate_id' => $snapshot->interests->first()->id,
            'recipient_name_snapshot' => 'Synthetic recipient',
        ]);
        foreach (['first', 'second'] as $successKey) {
            $recipient->attempts()->create([
                'method' => 'registered_mail',
                'status' => 'successful',
                'attempted_at' => now(),
                'actor_id' => $context['owner']->id,
                'success_key' => $successKey,
            ]);
        }
        $audit = app(GovernanceIntegrityAuditService::class);

        $assemblyCodes = collect($audit->assemblies(['assembly' => $assembly->id])['violations'])->pluck('code');
        $evidenceCodes = collect($audit->evidence(['assembly' => $assembly->id])['violations'])->pluck('code');

        $this->assertContains('cross_residence_participant', $assemblyCodes);
        $this->assertContains('duplicate_successful_convocation_delivery', $assemblyCodes);
        $this->assertContains('broken_evidence_checksum', $evidenceCodes);
    }

    public function test_populated_export_rows_match_pinned_snapshot_after_source_change(): void
    {
        $context = $this->context();
        ['assembly' => $assembly, 'snapshot' => $snapshot] = $this->eligibleAssembly($context, 'AG-EXPORT');
        $tenant = new TenantContext($context['organization'], $context['residence']);
        $service = app(GovernanceExportService::class);
        $before = $service->rows($tenant, 'eligibility', ['assembly' => $assembly->id]);
        $snapshot->votingShareSource->update(['configuration' => ['shares' => ['LOT-AG-EXPORT' => '1.0000']]]);
        $after = $service->rows($tenant, 'eligibility', ['assembly' => $assembly->id]);

        $this->assertCount(1, $before);
        $this->assertSame($before, $after);
        $this->assertSame($snapshot->id, $after[0]['eligibility_snapshot_id']);
        $this->assertSame(1_000_000, $after[0]['voting_weight_numerator']);
    }

    public function test_populated_export_endpoints_keep_filter_and_row_count_parity(): void
    {
        $context = $this->context();
        ['assembly' => $assembly] = $this->eligibleAssembly($context, 'AG-EXPORT-FORMATS');
        $this->actingAs($context['owner']);

        $json = $this->get(route('governance.exports', [
            'type' => 'eligibility',
            'format' => 'json',
            'assembly' => $assembly->id,
            'locale' => 'ar',
        ]))->assertOk()->assertDownload();
        $json->assertJsonPath('metadata.row_count', 1);
        $json->assertJsonPath('metadata.filters.assembly', (string) $assembly->id);
        $json->assertJsonCount(1, 'rows');
        $json->assertJsonMissing(['voter_user_id']);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $response = $this->get(route('governance.exports', [
                'type' => 'eligibility',
                'format' => $format,
                'assembly' => $assembly->id,
                'locale' => 'ar',
            ]));
            $response->assertOk()->assertDownload();
            $this->assertStringNotContainsString('exception', strtolower((string) $response->headers->get('content-disposition')));
        }
    }

    public function test_secret_ballot_closure_has_no_voter_choice_mapping(): void
    {
        $context = $this->context();
        ['assembly' => $assembly] = $this->eligibleAssembly($context, 'AG-SECRET');
        $rule = $this->rule($context);
        $item = AssemblyAgendaItem::create([
            'assembly_id' => $assembly->id,
            'display_order' => 1,
            'title_fr' => 'Vote secret synthétique',
            'title_ar' => 'تصويت سري اصطناعي',
            'category' => 'qa',
            'status' => 'frozen',
        ]);
        $resolution = AssemblyResolution::create([
            'assembly_id' => $assembly->id,
            'agenda_item_id' => $item->id,
            'governance_rule_version_id' => $rule->id,
            'code' => 'RES-SECRET',
            'proposed_text_fr' => 'Texte synthétique',
            'proposed_text_ar' => 'نص اصطناعي',
            'final_text_fr' => 'Texte synthétique',
            'final_text_ar' => 'نص اصطناعي',
            'category' => 'qa',
            'status' => 'voting_open',
            'vote_mode' => 'secret_aggregate',
            'voting_opened_at' => now(),
            'voting_opened_by' => $context['owner']->id,
        ]);
        $payload = ['rule_version_id' => $rule->id, 'certified' => false];
        ResolutionRuleSnapshot::create([
            'resolution_id' => $resolution->id,
            'governance_rule_version_id' => $rule->id,
            'payload' => $payload,
            'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'snapshotted_at' => now(),
            'snapshotted_by' => $context['owner']->id,
        ]);
        $reviewer = User::factory()->create();
        $context['organization']->users()->attach($reviewer, ['role' => 'owner', 'all_residences' => true]);

        $result = app(PhaseSevenGovernanceWorkflow::class)->closeSecretBallot(
            $resolution,
            ['for' => 60, 'against' => 20, 'abstention' => 10, 'invalid' => 5, 'not_cast' => 5, 'denominator' => 100],
            $this->evidence($context, $assembly),
            $context['owner'],
            $reviewer,
        );

        $this->assertSame([], $result->ballot_snapshot);
        $this->assertSame(0, $resolution->ballots()->count());
        $this->assertSame(100, $resolution->secretBallotAggregate()->firstOrFail()->weight_denominator);
        $this->assertArrayNotHasKey('voter_user_id', $result->rule_snapshot);

        $audit = app(GovernanceIntegrityAuditService::class);
        $voteReport = $audit->votes(['assembly' => $assembly->id]);
        $resolutionReport = $audit->resolutions(['resolution' => $resolution->id]);
        $this->assertSame(1, $voteReport['checked']);
        $this->assertTrue($voteReport['ok']);
        $this->assertSame(1, $resolutionReport['checked']);
        $this->assertTrue($resolutionReport['ok']);

        $result->update(['checksum' => str_repeat('0', 64)]);
        $voteReport = $audit->votes(['assembly' => $assembly->id]);
        $this->assertContains('vote_result_snapshot_mismatch', collect($voteReport['violations'])->pluck('code'));
        $this->artisan('governance:audit-votes', ['--assembly' => $assembly->id, '--json' => true])->assertExitCode(1);

        $broken = AssemblyResolution::create([
            'assembly_id' => $assembly->id,
            'agenda_item_id' => AssemblyAgendaItem::create([
                'assembly_id' => $assembly->id,
                'display_order' => 2,
                'title_fr' => 'Résolution incohérente synthétique',
                'category' => 'qa',
            ])->id,
            'governance_rule_version_id' => $rule->id,
            'code' => 'RES-BROKEN',
            'proposed_text_fr' => 'Texte synthétique',
            'category' => 'qa',
            'status' => 'adopted',
        ]);
        $this->assertContains(
            'adopted_without_closed_vote',
            collect($audit->resolutions(['resolution' => $broken->id])['violations'])->pluck('code'),
        );
    }

    public function test_unverified_rule_can_preview_but_cannot_confirm_quorum(): void
    {
        $context = $this->context();
        ['assembly' => $assembly] = $this->eligibleAssembly($context, 'AG-QUORUM-PREVIEW');
        $rule = $this->rule($context, false);
        $workflow = app(PhaseSevenGovernanceWorkflow::class);

        $snapshot = $workflow->previewQuorum($assembly, $rule, $context['owner']);

        $this->assertSame('professional_review_required', $snapshot->outcome);
        $this->assertSame('unverified', $snapshot->legal_verification_status);
        $this->expectException(ValidationException::class);
        $workflow->confirmQuorum($snapshot, $context['owner']);
    }

    public function test_quorum_confirmation_is_stable_when_json_storage_reorders_object_keys(): void
    {
        $context = $this->context();
        ['assembly' => $assembly, 'snapshot' => $eligibility] = $this->eligibleAssembly($context, 'AG-QUORUM-JSON-ORDER');
        app(PhaseSevenEligibilityService::class)->review($eligibility, $context['owner']);
        $assembly->update(['status' => 'scheduled']);
        foreach ($assembly->electorate()->orderBy('id')->get() as $interest) {
            app(AttendanceProxyService::class)->record(
                $assembly->fresh(),
                $interest,
                'present',
                $context['owner'],
            );
        }
        $workflow = app(PhaseSevenGovernanceWorkflow::class);
        $snapshot = $workflow->previewQuorum($assembly->fresh(), $this->rule($context), $context['owner']);
        $input = $snapshot->input_snapshot;
        $input['attendance'] = collect($input['attendance'])->map(fn (array $row) => [
            'status' => $row['status'],
            'weight' => $row['weight'],
            'electorate_id' => $row['electorate_id'],
        ])->all();
        DB::table('assembly_quorum_snapshots')->where('id', $snapshot->id)->update([
            'input_snapshot' => json_encode($input, JSON_THROW_ON_ERROR),
        ]);

        $confirmed = $workflow->confirmQuorum($snapshot->fresh(), $context['owner']);

        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertSame('met', $assembly->fresh()->quorum_status);
        $this->assertNull($confirmed->stale_at);
        $this->assertNotContains(
            'quorum_inconsistent_with_attendance',
            collect(app(GovernanceIntegrityAuditService::class)->assemblies(['assembly' => $assembly->id])['violations'])->pluck('code'),
        );
    }

    public function test_active_rule_version_is_model_level_immutable(): void
    {
        $rule = $this->rule($this->context());

        $this->expectException(\LogicException::class);
        $rule->update(['threshold_numerator' => 2]);
    }

    public function test_manager_cannot_use_direct_eligibility_override_url(): void
    {
        $context = $this->context();
        ['assembly' => $assembly, 'snapshot' => $snapshot] = $this->eligibleAssembly($context, 'AG-OVERRIDE-DENIED');
        $evidence = $this->evidence($context, $assembly);

        $this->actingAs($context['manager'])->post(
            route('governance.eligibility.override', $snapshot->interests->first()),
            [
                'eligibility_status' => 'restricted',
                'reason' => 'Synthetic unauthorized override attempt.',
                'evidence_version_id' => $evidence->id,
            ],
        )->assertForbidden();

        $this->assertSame('eligible', $snapshot->interests->first()->fresh()->eligibility_status);
        $this->assertSame(0, $snapshot->interests->first()->corrections()->count());
    }

    public function test_queued_availability_job_revalidates_scope_and_is_idempotent(): void
    {
        Notification::fake();
        $context = $this->context();
        ['assembly' => $assembly, 'contact' => $contact, 'snapshot' => $snapshot] = $this->eligibleAssembly($context, 'AG-JOB-IDEMPOTENT');
        $context['owner']->contacts()->attach($contact, [
            'organization_id' => $context['organization']->id,
            'linked_by' => $context['owner']->id,
            'linked_at' => now(),
        ]);
        $convocation = Convocation::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'assembly_id' => $assembly->id,
            'issued_at' => now(),
            'issued_by' => $context['owner']->id,
            'legal_deadline_at' => now(),
            'path' => 'synthetic/convocation.pdf',
            'checksum' => hash('sha256', 'synthetic'),
            'frozen_payload' => [],
        ]);
        $recipient = $convocation->recipients()->create([
            'electorate_id' => $snapshot->interests->first()->id,
            'recipient_name_snapshot' => 'Synthetic recipient',
        ]);
        $job = new DispatchConvocationAvailability(
            $recipient->id,
            $context['owner']->id,
            "convocation:{$convocation->id}:recipient:{$recipient->id}:availability",
        );

        $job->handle(app(GovernanceNotificationService::class));
        $job->handle(app(GovernanceNotificationService::class));

        $this->assertSame(2, DB::table('governance_notification_dispatches')->count());
        Notification::assertCount(2);
        $this->assertSame('pending', $recipient->fresh()->status);
    }
}
