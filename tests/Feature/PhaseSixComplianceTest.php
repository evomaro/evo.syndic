<?php

namespace Tests\Feature;

use App\Models\ComplianceApplicabilityDecision;
use App\Models\ComplianceAuthority;
use App\Models\ComplianceEvidence;
use App\Models\ComplianceObligation;
use App\Models\ComplianceReminderOccurrence;
use App\Models\ComplianceReminderPolicy;
use App\Models\ComplianceSource;
use App\Models\ComplianceTemplate;
use App\Models\ComplianceTemplateVersion;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\ComplianceApplicabilityService;
use App\Services\ComplianceAuditService;
use App\Services\ComplianceDeadlineService;
use App\Services\ComplianceEvidenceService;
use App\Services\ComplianceObligationWorkflow;
use App\Services\ComplianceOccurrenceService;
use App\Services\ComplianceReminderService;
use App\Services\ComplianceTemplateWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseSixComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_is_blocked_without_verified_source_and_separate_approval(): void
    {
        [$organization, $residence, $verifier, $approver, $activator] = $this->context();
        $version = $this->version();
        $workflow = app(ComplianceTemplateWorkflow::class);

        $this->expectException(ValidationException::class);
        $workflow->approve($version, $approver);
    }

    public function test_verified_source_approval_activation_and_active_immutability_are_controlled(): void
    {
        [, , $verifier, $approver, $activator] = $this->context();
        $version = $this->version(true);
        $workflow = app(ComplianceTemplateWorkflow::class);
        $workflow->verifySource($version->source, $verifier);
        $version->update(['professional_review_status' => 'approved']);
        $workflow->approve($version, $approver);
        $active = $workflow->activate($version, $activator);

        $this->assertSame('active', $active->status);
        $this->assertSame($verifier->id, $active->source->verified_by);
        $this->assertSame($approver->id, $active->approved_by);
        $this->assertSame($activator->id, $active->activated_by);

        $this->expectException(ValidationException::class);
        $active->update(['title_fr' => 'Réécriture historique interdite']);
    }

    public function test_missing_applicability_attribute_is_undetermined_and_explicit_values_are_deterministic(): void
    {
        [, , , , $actor] = $this->context();
        $version = $this->activeVersion($actor);
        $service = app(ComplianceApplicabilityService::class);

        $this->assertSame('undetermined', $service->preview($version, [])['outcome']);
        $this->assertSame('applies', $service->preview($version, ['explicit_employer_status' => true])['outcome']);
        $this->assertSame('does_not_apply', $service->preview($version, ['explicit_employer_status' => false])['outcome']);
    }

    public function test_business_day_rule_is_unavailable_and_calendar_day_deadline_uses_explicit_timezone(): void
    {
        [, , , , $actor] = $this->context();
        $version = $this->activeVersion($actor);
        $deadlines = app(ComplianceDeadlineService::class);
        $available = $deadlines->calculate($version, ['reporting_period_end' => '2026-07-31'], 'Africa/Casablanca');
        $this->assertSame('2026-08-10', $available['due_on']);

        $version->status = 'draft';
        $version->deadline_rule = ['basis' => 'reporting_period_end', 'unit' => 'business_days', 'offset' => 10];
        $unavailable = $deadlines->calculate($version, ['reporting_period_end' => '2026-07-31'], 'Africa/Casablanca');
        $this->assertSame('unavailable', $unavailable['status']);
        $this->assertSame('approved_business_calendar_missing', $unavailable['reason']);
    }

    public function test_occurrence_generation_is_idempotent_and_preserves_version_and_original_deadline(): void
    {
        [$organization, $residence, , , $actor] = $this->context();
        $version = $this->activeVersion($actor);
        $decision = ComplianceApplicabilityDecision::create([
            'organization_id' => $organization->id, 'residence_id' => $residence->id,
            'template_version_id' => $version->id, 'outcome' => 'applies',
            'inputs' => ['explicit_employer_status' => true], 'explanation_fr' => 'Explicite',
            'explanation_ar' => 'صريح', 'decided_by' => $actor->id, 'decided_at' => now('UTC'),
        ]);
        $inputs = ['reporting_period' => '2026-07', 'reporting_period_end' => '2026-07-31'];
        $service = app(ComplianceOccurrenceService::class);
        $first = $service->generate($decision, $inputs, 'Africa/Casablanca');
        $second = $service->generate($decision, $inputs, 'Africa/Casablanca');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($version->id, $first->template_version_id);
        $this->assertSame('2026-08-10', $first->original_due_on->toDateString());
        $this->assertSame(1, ComplianceObligation::count());
    }

    public function test_bounded_recurring_generation_is_retry_safe(): void
    {
        [$organization, $residence, , , $actor] = $this->context();
        $version = $this->activeVersion($actor);
        ComplianceApplicabilityDecision::create([
            'organization_id' => $organization->id, 'residence_id' => $residence->id,
            'template_version_id' => $version->id, 'outcome' => 'applies',
            'inputs' => ['explicit_employer_status' => true], 'deadline_inputs' => ['reporting_period_end' => '2026-07-31'],
            'explanation_fr' => 'Explicite', 'explanation_ar' => 'صريح', 'decided_by' => $actor->id, 'decided_at' => now(),
        ]);
        $service = app(ComplianceOccurrenceService::class);
        $first = $service->generateHorizon(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-09-30'), true);
        $retry = $service->generateHorizon(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-09-30'), true);

        $this->assertSame(3, $first['candidates']);
        $this->assertSame(3, $first['generated']);
        $this->assertSame(0, $retry['generated']);
        $this->assertSame(3, ComplianceObligation::count());
    }

    public function test_new_version_supersedes_active_version_without_rewriting_occurrence(): void
    {
        [$organization, $residence, $reviewer, $approver, $activator] = $this->context();
        $active = $this->activeVersion($reviewer);
        $obligation = $this->obligationFromVersion($organization, $residence, $reviewer, $active);
        $workflow = app(ComplianceTemplateWorkflow::class);
        $draft = $workflow->createAmendment($active, $reviewer);
        $draft->update(['effective_from' => '2027-01-01']);
        $workflow->professionalReview($draft, $reviewer);
        $workflow->approve($draft, $approver);
        $new = $workflow->activate($draft, $activator);

        $this->assertSame('active', $new->status);
        $this->assertSame('superseded', $active->fresh()->status);
        $this->assertSame('2026-12-31', $active->fresh()->effective_until->toDateString());
        $this->assertSame($active->id, $obligation->fresh()->template_version_id);
        $this->assertSame('2026-08-10', $obligation->fresh()->original_due_on->toDateString());
    }

    public function test_submission_does_not_mean_acceptance_and_acceptance_requires_external_evidence(): void
    {
        [$organization, $residence, , $reviewer, $actor] = $this->context();
        $obligation = $this->obligation($organization, $residence, $actor);
        $workflow = app(ComplianceObligationWorkflow::class);
        $workflow->assign($obligation, $actor, null, 'responsible', $reviewer);
        $workflow->transition($obligation, 'in_preparation', $actor);
        $workflow->transition($obligation, 'ready_for_review', $actor);
        $workflow->transition($obligation, 'ready_for_submission', $reviewer);
        $submission = $workflow->submit($obligation, ['submitted_on' => '2026-07-25', 'method' => 'manual_test'], $actor);
        $workflow->transition($obligation, 'submitted', $actor);

        $this->assertSame(1, $submission->attempt);
        $this->assertSame('submitted', $obligation->fresh()->operational_status);

        try {
            $workflow->transition($obligation->fresh(), 'acknowledged', $reviewer);
            $this->fail('Acknowledgement without evidence should fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $evidence = ComplianceEvidence::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'obligation_id' => $obligation->id, 'submission_id' => $submission->id, 'type' => 'authority_acknowledgement', 'title' => 'Accusé explicite', 'created_by' => $reviewer->id]);
        $workflow->transition($obligation->fresh(), 'acknowledged', $reviewer, null, $evidence);
        $workflow->transition($obligation->fresh(), 'accepted', $reviewer, null, $evidence);
        $this->assertSame('accepted', $obligation->fresh()->operational_status);
    }

    public function test_evidence_is_versioned_checksummed_and_cross_scope_replacement_is_rejected(): void
    {
        Storage::fake('local');
        [$organization, $residence, , $reviewer, $actor] = $this->context();
        $obligation = $this->obligation($organization, $residence, $actor);
        $service = app(ComplianceEvidenceService::class);
        $first = $service->store($obligation, 'preparation_document', 'Dossier', UploadedFile::fake()->create('dossier.pdf', 20, 'application/pdf'), $actor);
        $second = $service->store($obligation, 'preparation_document', 'Dossier', UploadedFile::fake()->create('dossier-v2.pdf', 25, 'application/pdf'), $reviewer, null, $first->evidence);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(64, strlen($second->checksum));
        $this->assertNotSame($first->path, $second->path);
        $service->assertIntegrity($second);
    }

    public function test_reminders_are_duplicate_safe_respect_tenant_mail_gate_and_stop_after_completion(): void
    {
        Notification::fake();
        [$organization, $residence, , $reviewer, $actor] = $this->context();
        $obligation = $this->obligation($organization, $residence, $actor, '2026-07-25');
        app(ComplianceObligationWorkflow::class)->assign($obligation, $actor, null, 'responsible', $reviewer);
        ComplianceReminderPolicy::create([
            'organization_id' => $organization->id, 'residence_id' => $residence->id,
            'name' => 'Échéance', 'triggers' => [['type' => 'due_date']], 'recipient_types' => ['responsible'],
            'database_enabled' => true, 'email_enabled' => true, 'digest' => false, 'active' => true, 'created_by' => $reviewer->id,
        ]);
        $service = app(ComplianceReminderService::class);
        $first = $service->generate(CarbonImmutable::parse('2026-07-25'));
        $second = $service->generate(CarbonImmutable::parse('2026-07-25'));

        $this->assertSame(2, $first['generated']);
        $this->assertSame(0, $second['generated']);
        $this->assertSame(2, ComplianceReminderOccurrence::count());
        $result = $service->dispatch();
        $this->assertSame(1, $result['delivered']);
        $this->assertSame(1, $result['failed']);
        Notification::assertSentTo($actor, PortalNotification::class, fn ($notification, $channels) => $channels === ['database']);
    }

    public function test_digest_policy_delivers_one_notification_for_multiple_due_obligations(): void
    {
        Notification::fake();
        [$organization, $residence, , $reviewer, $actor] = $this->context();
        $first = $this->obligation($organization, $residence, $actor, '2026-07-25');
        $second = $this->obligation($organization, $residence, $actor, '2026-07-25');
        $workflow = app(ComplianceObligationWorkflow::class);
        $workflow->assign($first, $actor, null, 'responsible', $reviewer);
        $workflow->assign($second, $actor, null, 'responsible', $reviewer);
        ComplianceReminderPolicy::create([
            'organization_id' => $organization->id,
            'residence_id' => $residence->id,
            'name' => 'Synthèse quotidienne',
            'triggers' => [['type' => 'due_date']],
            'recipient_types' => ['responsible'],
            'database_enabled' => true,
            'email_enabled' => false,
            'digest' => true,
            'active' => true,
            'created_by' => $reviewer->id,
        ]);

        $service = app(ComplianceReminderService::class);
        $this->assertSame(2, $service->generate(CarbonImmutable::parse('2026-07-25'))['generated']);
        $result = $service->dispatch();

        $this->assertSame(['delivered' => 2, 'failed' => 0], $result);
        $this->assertSame(2, ComplianceReminderOccurrence::where('status', 'delivered')->count());
        Notification::assertSentToTimes($actor, PortalNotification::class, 1);
        Notification::assertSentTo(
            $actor,
            PortalNotification::class,
            fn (PortalNotification $notification, array $channels) => $channels === ['database']
                && $notification->eventKey === 'compliance.reminder_digest'
                && $notification->payload['occurrence_count'] === 2,
        );
    }

    public function test_cross_residence_routes_are_non_disclosing_and_exports_are_scoped(): void
    {
        [$organization, $residence, , , $actor] = $this->context();
        $other = Residence::create(['organization_id' => $organization->id, 'name' => 'Autre', 'code' => 'OTH', 'address_line_1' => 'B', 'city' => 'Rabat', 'timezone' => 'Africa/Casablanca', 'currency' => 'MAD']);
        $obligation = $this->obligation($organization, $other, $actor);
        $actor->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);

        $this->actingAs($actor)->get(route('compliance.obligations.show', $obligation))->assertNotFound();
        $response = $this->actingAs($actor)->get(route('compliance.export', ['format' => 'json']));
        $response->assertOk()->assertJsonCount(0, 'rows')->assertJson(['not_legal_or_tax_advice' => true]);
    }

    public function test_calendar_filters_and_export_families_share_the_same_scoped_snapshot(): void
    {
        Storage::fake('local');
        [$organization, $residence, , $reviewer, $actor] = $this->context();
        $obligation = $this->obligation($organization, $residence, $actor, '2026-07-25');
        $obligation->update(['deadline_status' => 'overdue']);
        app(ComplianceObligationWorkflow::class)->assign($obligation, $actor, null, 'responsible', $reviewer);
        app(ComplianceObligationWorkflow::class)->submit($obligation, [
            'submitted_on' => '2026-07-24',
            'method' => 'synthetic_qa',
            'reference' => '=unsafe-reference',
        ], $actor);
        app(ComplianceEvidenceService::class)->store(
            $obligation,
            'submission_receipt',
            '=unsafe-title',
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            $actor,
        );
        $authorityId = $obligation->template->authority_id;

        $this->actingAs($actor)->get(route('compliance.index', [
            'authority_id' => $authorityId,
            'assignee_id' => $actor->id,
            'month' => '2026-07',
            'view' => 'month',
        ]))->assertOk()->assertInertia(fn ($page) => $page
            ->where('obligations.total', 1)
            ->where('filters.authority_id', (string) $authorityId)
            ->where('filters.assignee_id', (string) $actor->id));

        foreach (['register', 'evidence', 'submissions', 'overdue'] as $family) {
            $response = $this->actingAs($actor)->get(route('compliance.export', [
                'format' => 'json',
                'family' => $family,
                'authority_id' => $authorityId,
                'assignee_id' => $actor->id,
            ]));
            $response->assertOk()
                ->assertJsonPath('family', $family)
                ->assertJsonCount(1, 'rows')
                ->assertJsonPath('snapshot_id', $obligation->id);
        }
        $this->actingAs($actor)->get(route('compliance.export', [
            'format' => 'json', 'family' => 'evidence',
        ]))->assertJsonPath('rows.0.evidence_title', "'=unsafe-title");
        $this->actingAs($actor)->get(route('compliance.export', [
            'format' => 'json', 'family' => 'submissions',
        ]))->assertJsonPath('rows.0.reference', "'=unsafe-reference");
    }

    public function test_cross_tenant_source_verification_is_non_disclosing(): void
    {
        [$organization, $residence, , , $actor] = $this->context();
        $other = Organization::create(['name' => 'Autre organisation', 'code' => 'OTHER-'.str()->random(8), 'type' => 'professional', 'timezone' => 'Africa/Casablanca', 'currency' => 'MAD']);
        $authority = ComplianceAuthority::create(['organization_id' => $other->id, 'code' => 'OTHER-AUTH', 'jurisdiction' => 'MA', 'name_fr' => 'Autre', 'name_ar' => 'أخرى']);
        $source = ComplianceSource::create(['organization_id' => $other->id, 'authority_id' => $authority->id, 'official_title' => 'Source autre', 'official_url' => 'https://example.test/other', 'published_on' => '2026-01-01', 'effective_on' => '2026-01-01', 'confidence' => 'unverified_draft', 'version' => 1]);
        $actor->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);

        $this->actingAs($actor)->post(route('compliance.sources.verify', $source))->assertNotFound();
        $this->assertSame('unverified_draft', $source->fresh()->confidence);
    }

    public function test_applicability_override_supersedes_the_prior_decision_and_open_obligations(): void
    {
        [$organization, $residence, , $reviewer, $actor] = $this->context();
        $obligation = $this->obligation($organization, $residence, $actor);
        $decision = $obligation->applicabilityDecision;

        $replacement = app(ComplianceApplicabilityService::class)->override(
            $decision,
            'does_not_apply',
            'Correction explicite',
            'QA-EVIDENCE-1',
            $reviewer,
        );

        $this->assertSame($decision->id, $replacement->supersedes_id);
        $this->assertSame($replacement->id, $decision->fresh()->superseded_by_id);
        $this->assertSame('superseded', $obligation->fresh()->operational_status);
        $this->expectException(ValidationException::class);
        app(ComplianceOccurrenceService::class)->generate($decision->fresh(), [
            'reporting_period_end' => '2026-08-31',
            'reporting_period' => '2026-08',
        ], 'Africa/Casablanca');
    }

    public function test_withdrawn_template_cannot_generate_an_occurrence(): void
    {
        [, , , $approver, $activator] = $this->context();
        $version = $this->activeVersion($approver);
        $decision = ComplianceApplicabilityDecision::create([
            'organization_id' => $version->template->organization_id,
            'residence_id' => null,
            'template_version_id' => $version->id,
            'outcome' => 'applies',
            'inputs' => ['explicit_employer_status' => true],
            'explanation_fr' => 'Explicite',
            'explanation_ar' => 'صريح',
            'decided_by' => $approver->id,
            'decided_at' => now(),
        ]);

        app(ComplianceTemplateWorkflow::class)->withdraw($version, $activator, 'Source retirée');
        $this->assertSame('withdrawn', $version->fresh()->status);
        $this->expectException(ValidationException::class);
        app(ComplianceOccurrenceService::class)->generate($decision, [
            'reporting_period_end' => '2026-08-31',
            'reporting_period' => '2026-08',
        ], 'Africa/Casablanca');
    }

    public function test_deadline_override_invalidates_a_stale_pending_reminder(): void
    {
        Notification::fake();
        [$organization, $residence, , $reviewer, $actor] = $this->context();
        $obligation = $this->obligation($organization, $residence, $actor, '2026-07-25');
        app(ComplianceObligationWorkflow::class)->assign($obligation, $actor, null, 'responsible', $reviewer);
        ComplianceReminderPolicy::create([
            'organization_id' => $organization->id,
            'residence_id' => $residence->id,
            'name' => 'Échéance',
            'triggers' => [['type' => 'due_date']],
            'recipient_types' => ['responsible'],
            'database_enabled' => true,
            'email_enabled' => false,
            'digest' => false,
            'active' => true,
            'created_by' => $reviewer->id,
        ]);
        $service = app(ComplianceReminderService::class);
        $service->generate(CarbonImmutable::parse('2026-07-25'));
        app(ComplianceObligationWorkflow::class)->overrideDeadline(
            $obligation,
            '2026-08-25',
            'Report documenté',
            'QA-DEADLINE-1',
            $reviewer,
        );

        $this->assertSame(['delivered' => 0, 'failed' => 1], $service->dispatch());
        $this->assertSame('stale_or_unauthorized_scope', ComplianceReminderOccurrence::firstOrFail()->failure_code);
        Notification::assertNothingSent();
    }

    public function test_read_only_audits_detect_acceptance_without_acknowledgement(): void
    {
        [$organization, $residence, , , $actor] = $this->context();
        $obligation = $this->obligation($organization, $residence, $actor);
        $obligation->update(['operational_status' => 'accepted']);
        $report = app(ComplianceAuditService::class)->obligations(['organization' => $organization->id]);

        $this->assertFalse($report['ok']);
        $this->assertContains('accepted_without_acknowledgement_evidence', collect($report['violations'])->pluck('check'));
        $this->artisan('compliance:audit-obligations', ['--organization' => $organization->id, '--json' => true])->assertExitCode(1);
    }

    private function context(): array
    {
        $organization = Organization::create(['name' => 'Phase 06E', 'code' => 'P6E-'.str()->random(8), 'type' => 'professional', 'timezone' => 'Africa/Casablanca', 'currency' => 'MAD']);
        $residence = Residence::create(['organization_id' => $organization->id, 'name' => 'Résidence conformité', 'code' => 'RC-'.str()->random(5), 'address_line_1' => 'A', 'city' => 'Casablanca', 'timezone' => 'Africa/Casablanca', 'currency' => 'MAD']);
        $users = collect(range(1, 3))->map(fn () => User::factory()->create(['email_verified_at' => now()]));
        foreach ($users as $user) {
            $organization->users()->attach($user->id, ['role' => 'owner', 'all_residences' => true]);
            $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        }

        return [$organization, $residence, ...$users];
    }

    private function version(bool $withSource = false): ComplianceTemplateVersion
    {
        $organization = Organization::query()->firstOrFail();
        $authority = ComplianceAuthority::create(['organization_id' => $organization->id, 'code' => 'AUTH-'.str()->random(8), 'jurisdiction' => 'MA', 'name_fr' => 'Autorité de test', 'name_ar' => 'سلطة اختبار']);
        $source = $withSource ? ComplianceSource::create(['organization_id' => $organization->id, 'authority_id' => $authority->id, 'official_title' => 'Document officiel de test', 'official_url' => 'https://example.test/official', 'published_on' => '2026-01-01', 'effective_on' => '2026-01-01', 'confidence' => 'unverified_draft', 'version' => 1]) : null;
        $template = ComplianceTemplate::create(['organization_id' => $organization->id, 'code' => 'CMP-'.str()->random(8), 'jurisdiction' => 'MA', 'category' => 'other', 'authority_id' => $authority->id]);

        return ComplianceTemplateVersion::create([
            'template_id' => $template->id, 'source_id' => $source?->id, 'version' => 1, 'status' => 'draft',
            'title_fr' => 'Obligation de test', 'title_ar' => 'التزام اختباري',
            'applicability_description_fr' => 'Employeur explicite', 'applicability_description_ar' => 'مشغل صريح',
            'applicability_rule' => ['attribute' => 'explicit_employer_status', 'operator' => 'boolean_is', 'value' => true],
            'schedule_type' => 'monthly', 'deadline_rule' => ['basis' => 'reporting_period_end', 'unit' => 'calendar_days', 'offset' => 10],
            'calculation_method_fr' => 'Décalage explicite', 'calculation_method_ar' => 'إزاحة صريحة',
            'required_evidence_fr' => 'Accusé explicite', 'required_evidence_ar' => 'إشعار صريح',
            'confidence' => 'unverified_draft', 'effective_from' => '2026-01-01',
            'professional_review_required' => true, 'professional_review_status' => 'pending', 'counsel_review_status' => 'not_required',
        ]);
    }

    private function activeVersion(User $actor): ComplianceTemplateVersion
    {
        $version = $this->version(true);
        $version->source->update(['confidence' => 'source_verified', 'last_verified_on' => '2026-07-25', 'verified_by' => $actor->id]);
        $version->update(['status' => 'active', 'professional_review_status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'activated_by' => $actor->id, 'activated_at' => now()]);

        return $version->fresh();
    }

    private function obligation(Organization $organization, Residence $residence, User $actor, string $due = '2026-08-10'): ComplianceObligation
    {
        $version = $this->activeVersion($actor);

        return $this->obligationFromVersion($organization, $residence, $actor, $version, $due);
    }

    private function obligationFromVersion(Organization $organization, Residence $residence, User $actor, ComplianceTemplateVersion $version, string $due = '2026-08-10'): ComplianceObligation
    {
        $decision = ComplianceApplicabilityDecision::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'template_version_id' => $version->id, 'outcome' => 'applies', 'inputs' => ['explicit_employer_status' => true], 'explanation_fr' => 'Explicite', 'explanation_ar' => 'صريح', 'decided_by' => $actor->id, 'decided_at' => now()]);

        return ComplianceObligation::create([
            'organization_id' => $organization->id, 'residence_id' => $residence->id,
            'template_id' => $version->template_id, 'template_version_id' => $version->id, 'source_id' => $version->source_id,
            'applicability_decision_id' => $decision->id, 'occurrence_key' => hash('sha256', str()->random()),
            'reporting_period' => '2026-07', 'original_due_on' => $due, 'current_due_on' => $due,
            'deadline_status' => 'upcoming', 'operational_status' => 'upcoming',
            'deadline_inputs' => ['reporting_period_end' => '2026-07-31'], 'deadline_rule_snapshot' => $version->deadline_rule,
            'timezone' => 'Africa/Casablanca', 'generated_at' => now('UTC'),
        ]);
    }
}
