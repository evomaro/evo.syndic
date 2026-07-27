<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\Contact;
use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Models\GovernanceRule;
use App\Models\GovernanceRuleSource;
use App\Models\GovernanceRuleVersion;
use App\Models\GovernanceVotingShareSource;
use App\Models\Lot;
use App\Models\LotOwnership;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Services\GovernanceExportService;
use App\Services\GovernanceIntegrityAuditService;
use App\Services\GovernanceRuleWorkflow;
use App\Services\PhaseSevenEligibilityService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseSevenGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-26 12:00:00');
    }

    private function context(): array
    {
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $maker = User::factory()->create();
        $reviewer = User::factory()->create();
        $activator = User::factory()->create();
        foreach ([$maker, $reviewer, $activator] as $user) {
            $organization->users()->attach($user, ['role' => 'owner', 'all_residences' => true]);
            $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        }

        return compact('organization', 'residence', 'maker', 'reviewer', 'activator');
    }

    private function assembly(array $context, string $eligibilityOn = '2025-06-30'): Assembly
    {
        return Assembly::create([
            'organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id,
            'reference' => 'AG-P07-'.uniqid(), 'type' => 'ordinary', 'convocation_number' => 1,
            'status' => 'preparing', 'convening_authority' => 'Technical QA only',
            'meeting_date' => '2025-07-15', 'eligibility_on' => $eligibilityOn,
            'starts_at' => '18:00', 'location' => 'Disposable QA room', 'timezone' => 'Africa/Casablanca',
            'convocation_deadline_at' => '2025-06-30', 'documents_available_at' => '2025-06-30',
            'created_by' => $context['maker']->id,
        ]);
    }

    private function source(array $context, array $shares, string $status = 'approved', ?string $expected = null): GovernanceVotingShareSource
    {
        return GovernanceVotingShareSource::create([
            'organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id,
            'code' => 'QA-GOV-SHARES', 'version' => 1, 'source_type' => 'dedicated_governance_shares',
            'status' => $status, 'decimal_precision' => 4, 'expected_total' => $expected,
            'denominator' => 10000, 'configuration' => ['shares' => $shares],
            'verified_by' => $context['reviewer']->id, 'verified_at' => now(),
            'approved_by' => $context['activator']->id, 'approved_at' => now(),
            'effective_from' => '2025-01-01',
        ]);
    }

    private function rule(array $context, ?GovernanceRuleSource $source = null, array $extra = []): GovernanceRuleVersion
    {
        $rule = GovernanceRule::create([
            'organization_id' => $context['organization']->id, 'stable_code' => 'QA-RULE-'.uniqid(),
            'jurisdiction' => 'MA', 'assembly_type' => 'ordinary', 'resolution_category' => 'qa',
            'title_fr' => 'Règle de test non juridique', 'title_ar' => 'قاعدة اختبار غير قانونية',
        ]);

        return $rule->versions()->create([
            'governance_rule_source_id' => $source?->id, 'identifier' => $rule->stable_code, 'version' => 1,
            'effective_from' => '2025-01-01', 'official_source' => $source?->official_title ?: 'Absent',
            'source_url' => $source?->official_url ?: 'about:blank', 'review_status' => 'pending_professional_review',
            'numerator_definition' => 'present_represented_weight', 'denominator_definition' => 'all_eligible_weight',
            'threshold_numerator' => 1, 'threshold_denominator' => 2, 'comparison' => 'gte',
            'quorum_rule' => 'configured_formula', 'abstention_behavior' => 'included_in_denominator',
            'invalid_ballot_behavior' => 'excluded', 'second_convocation_behavior' => 'unverified',
            'proxy_restrictions' => [], 'eligibility_restrictions' => [], 'legal_payload' => ['certified' => false],
            'status' => 'unverified_draft', 'confidence' => 'unverified_draft', 'assembly_type' => 'ordinary',
            'resolution_category' => 'qa', 'title_fr' => 'Règle QA', 'title_ar' => 'قاعدة اختبار',
            'rounding_policy' => 'none', 'voting_share_source_type' => 'dedicated_governance_shares',
            'effective_date_policy' => 'meeting_date', 'active' => false,
        ] + $extra);
    }

    public function test_rule_activation_requires_verified_source_review_approval_and_separate_actors(): void
    {
        $context = $this->context();
        $version = $this->rule($context);
        $version->update(['status' => 'approved', 'approved_by' => $context['reviewer']->id, 'approved_at' => now()]);
        $this->expectException(ValidationException::class);
        app(GovernanceRuleWorkflow::class)->activate($version, $context['activator']);
    }

    public function test_verified_rule_can_activate_and_amendment_supersedes_without_overwriting_history(): void
    {
        $context = $this->context();
        $source = GovernanceRuleSource::create([
            'organization_id' => $context['organization']->id, 'code' => 'QA-SOURCE', 'version' => 1,
            'jurisdiction' => 'MA', 'official_title' => 'QA source only',
            'official_url' => 'https://example.invalid/official-qa', 'confidence' => 'unverified_draft',
        ]);
        $workflow = app(GovernanceRuleWorkflow::class);
        $workflow->verifySource($source, $context['maker']);
        $version = $this->rule($context, $source->fresh());
        $workflow->review($version, $context['maker']);
        $workflow->approve($version->fresh(), $context['reviewer']);
        $workflow->activate($version->fresh(), $context['activator']);
        $this->assertSame('active', $version->fresh()->status);
        $successor = $workflow->amend($version->fresh(), $context['maker']);
        $workflow->review($successor, $context['maker']);
        $workflow->approve($successor->fresh(), $context['reviewer']);
        $workflow->activate($successor->fresh(), $context['activator']);
        $this->assertSame('superseded', $version->fresh()->status);
        $this->assertSame('active', $successor->fresh()->status);
        $this->assertNotNull($version->fresh()->immutable_at);
    }

    public function test_historical_eligibility_excludes_current_owner_and_uses_only_explicit_governance_shares(): void
    {
        $context = $this->context();
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-001', 'active' => true]);
        $historical = Contact::factory()->for($context['organization'])->create(['first_name' => 'Historical']);
        $current = Contact::factory()->for($context['organization'])->create(['first_name' => 'Current']);
        LotOwnership::create(['lot_id' => $lot->id, 'contact_id' => $historical->id, 'ownership_percentage' => '100.0000', 'starts_on' => '2024-01-01', 'ends_on' => '2025-12-31']);
        LotOwnership::create(['lot_id' => $lot->id, 'contact_id' => $current->id, 'ownership_percentage' => '100.0000', 'starts_on' => '2026-01-01']);
        $snapshot = app(PhaseSevenEligibilityService::class)->generate(
            $this->assembly($context),
            $this->source($context, ['LOT-001' => '100.0000'], 'approved', '100.0000'),
            $context['maker'],
        );
        $this->assertSame('ready_for_review', $snapshot->status);
        $this->assertSame([$historical->id], $snapshot->interests->pluck('contact_id')->all());
        $this->assertSame(1_000_000, $snapshot->interests->first()->voting_weight_numerator);
        $this->assertSame('QA-GOV-SHARES', $snapshot->interests->first()->share_source_code);
    }

    public function test_joint_ownership_is_preserved_without_duplicate_interest_or_silent_normalization(): void
    {
        $context = $this->context();
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-JOINT', 'active' => true]);
        foreach (['One', 'Two'] as $name) {
            $contact = Contact::factory()->for($context['organization'])->create(['first_name' => $name]);
            LotOwnership::create(['lot_id' => $lot->id, 'contact_id' => $contact->id, 'ownership_percentage' => '50.0000', 'starts_on' => '2024-01-01']);
        }
        $snapshot = app(PhaseSevenEligibilityService::class)->generate(
            $this->assembly($context),
            $this->source($context, ['LOT-JOINT' => '100.0000'], 'approved', '100.0000'),
            $context['maker'],
        );
        $this->assertCount(2, $snapshot->interests);
        $this->assertSame([500_000, 500_000], $snapshot->interests->pluck('voting_weight_numerator')->sort()->values()->all());
        $this->assertSame(2, $snapshot->interests->pluck('entitlement_key')->unique()->count());
    }

    public function test_missing_or_unapproved_share_source_is_indeterminate_and_does_not_reuse_financial_allocations(): void
    {
        $context = $this->context();
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-NO-SHARE', 'active' => true]);
        $contact = Contact::factory()->for($context['organization'])->create();
        LotOwnership::create(['lot_id' => $lot->id, 'contact_id' => $contact->id, 'ownership_percentage' => '100.0000', 'starts_on' => '2024-01-01']);
        $snapshot = app(PhaseSevenEligibilityService::class)->generate($this->assembly($context), null, $context['maker']);
        $this->assertSame('indeterminate', $snapshot->status);
        $this->assertSame(0, $snapshot->interests->first()->voting_weight_numerator);
        $this->assertNull($snapshot->interests->first()->share_source_code);
        $this->assertContains('voting_share_source_missing', collect($snapshot->findings)->pluck('code'));
    }

    public function test_ownership_change_marks_reviewed_snapshot_stale(): void
    {
        $context = $this->context();
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-STALE', 'active' => true]);
        $contact = Contact::factory()->for($context['organization'])->create();
        $ownership = LotOwnership::create(['lot_id' => $lot->id, 'contact_id' => $contact->id, 'ownership_percentage' => '100.0000', 'starts_on' => '2024-01-01']);
        $service = app(PhaseSevenEligibilityService::class);
        $snapshot = $service->generate($this->assembly($context), $this->source($context, ['LOT-STALE' => '100.0000'], 'approved', '100.0000'), $context['maker']);
        $service->review($snapshot, $context['reviewer']);
        CarbonImmutable::setTestNow('2026-07-26 13:00:00');
        $ownership->update(['ownership_percentage' => '90.0000']);
        $this->assertTrue($service->refreshStaleness($snapshot->fresh()));
        $this->assertSame('stale', $snapshot->fresh()->status);
    }

    public function test_evidenced_eligibility_override_is_audited_and_invalidates_review(): void
    {
        $context = $this->context();
        $assembly = $this->assembly($context);
        $lot = Lot::factory()->for($context['residence'])->create(['reference' => 'LOT-OVERRIDE', 'active' => true]);
        $contact = Contact::factory()->for($context['organization'])->create();
        LotOwnership::create(['lot_id' => $lot->id, 'contact_id' => $contact->id, 'ownership_percentage' => '100.0000', 'starts_on' => '2024-01-01']);
        $service = app(PhaseSevenEligibilityService::class);
        $snapshot = $service->generate($assembly, $this->source($context, ['LOT-OVERRIDE' => '100.0000'], 'approved', '100.0000'), $context['maker']);
        $service->review($snapshot, $context['reviewer']);
        $document = GovernanceDocument::create([
            'organization_id' => $context['organization']->id,
            'residence_id' => $context['residence']->id,
            'assembly_id' => $assembly->id,
            'category' => 'eligibility_evidence',
            'title_fr' => 'Justificatif technique QA',
            'audience' => 'managers',
        ]);
        $evidence = GovernanceDocumentVersion::create([
            'governance_document_id' => $document->id,
            'version' => 1,
            'name' => 'qa-evidence.txt',
            'disk' => 'local',
            'path' => 'qa/phase-seven/evidence.txt',
            'mime_type' => 'text/plain',
            'size' => 12,
            'checksum' => hash('sha256', 'qa-evidence'),
            'uploaded_by' => $context['maker']->id,
            'frozen_at' => now(),
        ]);

        $interest = $service->overrideInterest(
            $snapshot->interests->first(),
            ['eligibility_status' => 'restricted', 'restriction_reason' => 'QA evidence requires a review.'],
            'Evidence-backed QA correction requiring renewed eligibility review.',
            $evidence,
            $context['maker'],
        );

        $this->assertSame('restricted', $interest->eligibility_status);
        $this->assertSame('stale', $snapshot->fresh()->status);
        $this->assertSame('evidenced_eligibility_override', $interest->corrections()->firstOrFail()->correction_type);
        $this->assertSame($evidence->id, $interest->corrections()->firstOrFail()->evidence_version_id);
        $this->assertSame('eligible', $interest->corrections()->firstOrFail()->before_payload['eligibility_status']);
    }

    public function test_governance_audits_are_read_only_and_return_nonzero_for_blocking_findings(): void
    {
        $context = $this->context();
        $assembly = $this->assembly($context);
        $before = Assembly::count();
        $report = app(GovernanceIntegrityAuditService::class)->assemblies([
            'organization' => $context['organization']->id, 'residence' => $context['residence']->id,
        ]);
        $this->assertFalse($report['ok']);
        $this->assertSame($before, Assembly::count());
        $this->artisan('governance:audit-assemblies', [
            '--organization' => $context['organization']->id,
            '--residence' => $context['residence']->id,
            '--assembly' => $assembly->id,
            '--json' => true,
        ])->assertExitCode(1);
    }

    public function test_rule_and_diagnostics_routes_enforce_separate_permissions(): void
    {
        $context = $this->context();
        $manager = User::factory()->create();
        $context['organization']->users()->attach($manager, ['role' => 'manager', 'all_residences' => true]);
        $manager->update(['current_organization_id' => $context['organization']->id, 'current_residence_id' => $context['residence']->id]);
        $this->actingAs($manager)->get(route('governance.rules.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('governance.diagnostics'))->assertForbidden();
        $this->actingAs($context['maker'])->get(route('governance.rules.index'))->assertOk();
        $this->actingAs($context['maker'])->get(route('governance.diagnostics'))->assertOk();
    }

    public function test_all_governance_register_queries_are_tenant_scoped_and_empty_safe(): void
    {
        $context = $this->context();
        $this->assembly($context);
        $tenant = new TenantContext($context['organization'], $context['residence']);
        $service = app(GovernanceExportService::class);
        foreach (GovernanceExportService::TYPES as $type) {
            $rows = $service->rows($tenant, $type, []);
            $this->assertIsArray($rows);
        }
        $other = $this->context();
        $otherAssembly = $this->assembly($other);
        $rows = $service->rows($tenant, 'assemblies', []);
        $this->assertNotContains($otherAssembly->reference, collect($rows)->pluck('assembly_reference')->all());
    }
}
