<?php

namespace Database\Seeders;

use App\Models\Assembly;
use App\Models\AssemblyProxy;
use App\Models\Contact;
use App\Models\GovernanceDocument;
use App\Models\GovernanceMandate;
use App\Models\GovernanceRule;
use App\Models\GovernanceRuleSource;
use App\Models\GovernanceVotingShareSource;
use App\Models\Lot;
use App\Models\LotOwnership;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use App\Services\AgendaService;
use App\Services\AssemblyWorkflow;
use App\Services\AttendanceProxyService;
use App\Services\BallotService;
use App\Services\ConvocationService;
use App\Services\MinutesService;
use App\Services\PhaseSevenEligibilityService;
use App\Services\PhaseSevenGovernanceWorkflow;
use App\Services\ResolutionExecutionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PhaseSevenQaSeeder extends Seeder
{
    public const ORGANIZATION_CODE = 'P07-QA-DISPOSABLE';

    public function run(): void
    {
        $this->assertDisposableDatabase();

        Notification::fake();
        Queue::fake();

        if (Organization::where('code', self::ORGANIZATION_CODE)->exists()) {
            $this->command?->info('Phase 07 QA fixture already exists; no changes made.');

            return;
        }

        $org = Organization::create([
            'name' => 'Phase 07 Synthetic QA — Disposable',
            'code' => self::ORGANIZATION_CODE,
            'type' => 'professional_syndic',
            'legal_name' => 'SYNTHETIC TEST DATA — NOT AN OFFICIAL ENTITY',
            'city' => 'Casablanca',
            'email' => 'phase07-qa@example.invalid',
            'active' => true,
        ]);
        $otherOrg = Organization::create([
            'name' => 'Phase 07 Isolation Control',
            'code' => 'P07-QA-ISOLATION',
            'type' => 'professional_syndic',
            'legal_name' => 'SYNTHETIC ISOLATION DATA',
            'city' => 'Rabat',
        ]);
        $primary = Residence::create($this->residence($org, 'QA-PRIMARY', 'Résidence QA principale'));
        $secondary = Residence::create($this->residence($org, 'QA-SECONDARY', 'Résidence QA secondaire'));
        $isolated = Residence::create($this->residence($otherOrg, 'QA-ISOLATED', 'Résidence QA isolée'));

        $manager = $this->user($org, $primary, 'qa.manager@evosyndic.test', 'Gestionnaire QA', 'owner', 'fr');
        $reviewer = $this->user($org, $primary, 'qa.reviewer@evosyndic.test', 'Réviseuse QA', 'owner', 'ar');
        $owners = collect([
            $this->user($org, $primary, 'qa.owner1@evosyndic.test', 'Propriétaire QA 1', 'coproprietaire', 'fr'),
            $this->user($org, $primary, 'qa.owner2@evosyndic.test', 'Propriétaire QA 2', 'coproprietaire', 'ar'),
            $this->user($org, $primary, 'qa.owner3@evosyndic.test', 'Propriétaire QA 3', 'coproprietaire', 'fr'),
            $this->user($org, $primary, 'qa.owner4@evosyndic.test', 'Propriétaire QA 4', 'coproprietaire', 'fr'),
        ]);
        $revokedUser = $this->user($org, $primary, 'qa.revoked@evosyndic.test', 'Accès révoqué QA', 'coproprietaire', 'fr', false);
        $this->user($org, $secondary, 'qa.secondary@evosyndic.test', 'Gestionnaire secondaire QA', 'manager', 'fr');
        $this->user($otherOrg, $isolated, 'qa.isolated@evosyndic.test', 'Gestionnaire isolé QA', 'owner', 'fr');

        $contacts = collect([
            $this->contact($org, 'Amina', 'QA', $owners[0]),
            $this->contact($org, 'Youssef', 'QA', $owners[1]),
            $this->contact($org, 'Salma', 'QA', $owners[2]),
            $this->contact($org, 'Omar', 'QA', $owners[3]),
        ]);
        $revokedContact = $this->contact($org, 'Accès', 'Révoqué', null);
        $revokedUser->contacts()->attach($revokedContact, [
            'organization_id' => $org->id,
            'linked_by' => $manager->id,
            'linked_at' => now()->subYear(),
            'revoked_at' => now()->subDay(),
            'revoked_by' => $manager->id,
        ]);

        $lots = collect([
            $this->lot($primary, 'QA-LOT-A', 'A-01'),
            $this->lot($primary, 'QA-LOT-B', 'B-01'),
            $this->lot($primary, 'QA-LOT-JOINT', 'J-01'),
            $this->lot($primary, 'QA-LOT-TRANSFER', 'T-01'),
        ]);
        $this->ownership($lots[0], $contacts[0], '100.0000', '2025-01-01');
        $this->ownership($lots[1], $contacts[1], '100.0000', '2025-01-01');
        $this->ownership($lots[2], $contacts[0], '50.0000', '2025-01-01');
        $this->ownership($lots[2], $contacts[2], '50.0000', '2025-01-01');
        $this->ownership($lots[3], $contacts[3], '100.0000', '2025-01-01', '2026-07-10');
        $this->ownership($lots[3], $contacts[2], '100.0000', '2026-07-11');

        $mandate = GovernanceMandate::create([
            'organization_id' => $org->id,
            'residence_id' => $primary->id,
            'user_id' => $manager->id,
            'role' => 'syndic',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-08-10',
            'status' => 'active',
            'active_slot' => 'syndic',
            'activated_at' => now(),
            'activated_by' => $reviewer->id,
        ]);

        [$activeRule, $draftRule] = $this->rules($org, $manager, $reviewer);
        $shareSource = GovernanceVotingShareSource::create([
            'organization_id' => $org->id,
            'residence_id' => $primary->id,
            'code' => 'P07-QA-EXPLICIT-SHARES',
            'version' => 1,
            'source_type' => 'dedicated_governance_shares',
            'status' => 'approved',
            'decimal_precision' => 4,
            'expected_total' => '100.0000',
            'denominator' => 10000,
            'configuration' => ['shares' => [
                'QA-LOT-A' => '25.0000',
                'QA-LOT-B' => '25.0000',
                'QA-LOT-JOINT' => '25.0000',
                'QA-LOT-TRANSFER' => '25.0000',
            ]],
            'verified_by' => $manager->id,
            'verified_at' => now(),
            'approved_by' => $reviewer->id,
            'approved_at' => now(),
            'effective_from' => '2026-01-01',
        ]);

        $assembly = Assembly::create([
            'organization_id' => $org->id,
            'residence_id' => $primary->id,
            'governance_mandate_id' => $mandate->id,
            'reference' => 'P07-QA-COMPLETE',
            'type' => 'ordinary',
            'convocation_number' => 1,
            'status' => 'preparing',
            'convening_authority' => 'Synthetic technical QA only',
            'meeting_date' => '2026-08-05',
            'eligibility_on' => '2026-07-01',
            'starts_at' => '18:00',
            'expected_ends_at' => '20:00',
            'location' => 'Disposable QA room',
            'timezone' => 'Africa/Casablanca',
            'convocation_deadline_at' => '2026-07-21 00:00:00',
            'documents_available_at' => '2026-07-21 00:00:00',
            'chairperson_contact_id' => $contacts[0]->id,
            'secretary_user_id' => $manager->id,
            'created_by' => $manager->id,
            'legal_verification_status' => 'technical_test_only',
        ]);

        $agenda = app(AgendaService::class);
        $agenda->add($assembly, [
            'display_order' => 1,
            'title_fr' => 'Résolution enregistrée de QA',
            'title_ar' => 'قرار اختبار مسجل',
            'proposed_text_fr' => 'Valider une action technique synthétique.',
            'proposed_text_ar' => 'الموافقة على إجراء تقني اصطناعي.',
            'category' => 'qa',
            'resident_visible' => true,
            'resolution' => [
                'code' => 'QA-RECORDED',
                'category' => 'qa',
                'proposed_text_fr' => 'Valider une action technique synthétique.',
                'proposed_text_ar' => 'الموافقة على إجراء تقني اصطناعي.',
            ],
        ], $activeRule, $manager);
        $agenda->add($assembly, [
            'display_order' => 2,
            'title_fr' => 'Résolution secrète de QA',
            'title_ar' => 'قرار اختبار سري',
            'proposed_text_fr' => 'Tester le rapprochement agrégé sans identité de votant.',
            'proposed_text_ar' => 'اختبار المطابقة المجمعة دون هوية المصوت.',
            'category' => 'qa',
            'resident_visible' => true,
            'resolution' => [
                'code' => 'QA-SECRET',
                'category' => 'qa',
                'proposed_text_fr' => 'Tester le rapprochement agrégé sans identité de votant.',
                'proposed_text_ar' => 'اختبار المطابقة المجمعة دون هوية المصوت.',
            ],
        ], $activeRule, $manager);

        $eligibility = app(PhaseSevenEligibilityService::class);
        $snapshot = $eligibility->generate($assembly, $shareSource, $manager);
        $eligibility->review($snapshot, $reviewer);
        $agenda->freeze($assembly, $manager);

        $evidence = $this->evidence($org, $primary, $assembly, $manager);
        $convocation = app(ConvocationService::class)->issue(
            $assembly,
            $manager,
            true,
            'Synthetic QA late-service exception; not a legal determination.',
        );
        $recipients = $convocation->recipients()->orderBy('id')->get();
        app(ConvocationService::class)->recordDelivery($recipients[0], 'registered_mail', 'successful', $manager);
        app(ConvocationService::class)->recordDelivery($recipients[1], 'registered_mail', 'failed', $manager, 'Synthetic failed attempt for technical QA.');
        app(AssemblyWorkflow::class)->transition($assembly->fresh(), 'scheduled', $manager, null, 'qa-schedule');

        $interests = $assembly->electorate()->orderBy('id')->get();
        $verifiedProxy = AssemblyProxy::create([
            'assembly_id' => $assembly->id,
            'principal_electorate_id' => $interests[0]->id,
            'representative_user_id' => $reviewer->id,
            'status' => 'verified',
            'entitlement_weight_numerator' => $interests[0]->voting_weight_numerator,
            'entitlement_weight_denominator' => $interests[0]->voting_weight_denominator,
            'document_path' => 'synthetic/phase07/verified-proxy.pdf',
            'document_checksum' => hash('sha256', 'synthetic verified proxy'),
            'submitted_at' => now(),
            'verified_at' => now(),
            'verified_by' => $manager->id,
            'active_principal_slot' => 'principal:'.$interests[0]->id,
            'legal_verification_status' => 'reviewed_configuration',
        ]);
        AssemblyProxy::create([
            'assembly_id' => $assembly->id,
            'principal_electorate_id' => $interests[1]->id,
            'representative_user_id' => $reviewer->id,
            'status' => 'revoked',
            'entitlement_weight_numerator' => $interests[1]->voting_weight_numerator,
            'entitlement_weight_denominator' => $interests[1]->voting_weight_denominator,
            'document_path' => 'synthetic/phase07/revoked-proxy.pdf',
            'document_checksum' => hash('sha256', 'synthetic revoked proxy'),
            'submitted_at' => now()->subHour(),
            'verified_at' => now()->subMinutes(30),
            'verified_by' => $manager->id,
            'revoked_at' => now(),
            'revoked_by' => $reviewer->id,
            'revocation_reason' => 'Synthetic revoked proxy for technical QA.',
            'legal_verification_status' => 'reviewed_configuration',
        ]);
        AssemblyProxy::create([
            'assembly_id' => $assembly->id,
            'principal_electorate_id' => $interests[1]->id,
            'representative_user_id' => $manager->id,
            'status' => 'submitted',
            'entitlement_weight_numerator' => $interests[1]->voting_weight_numerator,
            'entitlement_weight_denominator' => $interests[1]->voting_weight_denominator,
            'document_path' => 'synthetic/phase07/conflicting-proxy.pdf',
            'document_checksum' => hash('sha256', 'synthetic conflicting proxy'),
            'submitted_at' => now(),
            'legal_verification_status' => 'professional_review_required',
        ]);
        foreach ($interests as $index => $interest) {
            app(AttendanceProxyService::class)->record(
                $assembly->fresh(),
                $interest,
                $index === 0 ? 'represented' : 'present',
                $manager,
            );
        }
        $phaseSeven = app(PhaseSevenGovernanceWorkflow::class);
        $quorum = $phaseSeven->previewQuorum($assembly->fresh(), $activeRule, $manager);
        $phaseSeven->confirmQuorum($quorum, $reviewer);
        app(AssemblyWorkflow::class)->transition($assembly->fresh(), 'in_session', $manager, null, 'qa-open-session');

        $recorded = $assembly->resolutions()->where('code', 'QA-RECORDED')->firstOrFail();
        $phaseSeven->openVoting($recorded, 'recorded_interest', $manager);
        foreach ($assembly->electorate()->orderBy('id')->get() as $index => $interest) {
            app(BallotService::class)->enter(
                $recorded->fresh(),
                $interest,
                $index === 1 ? 'against' : 'for',
                $manager,
                $index === 0 ? $verifiedProxy->id : null,
                'qa-recorded-'.$interest->id,
            );
        }
        app(BallotService::class)->finalize($recorded->fresh(), $reviewer);

        $secret = $assembly->resolutions()->where('code', 'QA-SECRET')->firstOrFail();
        $phaseSeven->openVoting($secret, 'secret_aggregate', $manager);
        $eligibleWeight = (int) $assembly->electorate()->where('eligibility_status', 'eligible')->sum('voting_weight_numerator');
        $phaseSeven->closeSecretBallot($secret->fresh(), [
            'for' => 250000,
            'against' => $eligibleWeight - 250000,
            'abstention' => 0,
            'invalid' => 0,
            'not_cast' => 0,
            'denominator' => $eligibleWeight,
        ], $evidence, $manager, $reviewer);

        app(AssemblyWorkflow::class)->transition($assembly->fresh(), 'deliberations_completed', $manager, null, 'qa-close-deliberations');
        $draftMinutes = app(MinutesService::class)->prepare($assembly->fresh(), [
            'reservations_fr' => 'Données synthétiques; aucune portée juridique.',
            'reservations_ar' => 'بيانات اصطناعية دون أثر قانوني.',
        ], $manager);
        app(MinutesService::class)->review($draftMinutes, $reviewer);
        $phaseSeven->approveMinutes($draftMinutes, 'approval', $reviewer, $evidence);
        $signed = app(MinutesService::class)->sign($draftMinutes->minutes, [
            'chairperson' => 'Amina QA — synthetic',
            'secretary' => 'Gestionnaire QA — synthetic',
            'method' => 'technical_test_record',
        ], $manager);
        app(MinutesService::class)->correctiveAnnex(
            $signed->minutes,
            'Synthetic correction retained to exercise append-only history.',
            'Correction technique sans portée juridique.',
            'تصحيح تقني دون أثر قانوني.',
            $reviewer,
        );
        $phaseSeven->finalizeAssembly($assembly->fresh(), $manager);

        app(ResolutionExecutionService::class)->create($recorded->fresh(), [
            'action_type' => 'technical_follow_up',
            'description' => 'Synthetic QA follow-up; creates no financial or governance side effect.',
            'source_key' => 'p07-qa-follow-up',
            'responsible_user_id' => $manager->id,
            'reviewer_user_id' => $reviewer->id,
            'due_on' => now()->addDays(2)->toDateString(),
        ], $manager);

        $this->createStageAssemblies($org, $primary, $secondary, $isolated, $manager, $draftRule);

        $this->command?->info('Phase 07 guarded synthetic QA fixture created.');
    }

    private function assertDisposableDatabase(): void
    {
        $database = (string) Config::get('database.connections.'.Config::get('database.default').'.database');
        $sqlite = Config::get('database.default') === 'sqlite'
            && preg_match('#^/private/tmp/evosyndic-phase07-[A-Za-z0-9_.-]+\\.sqlite$#', $database);
        $mysql = in_array(Config::get('database.default'), ['mysql', 'mariadb'], true)
            && preg_match('/^evosyndic_phase07_disposable_[A-Za-z0-9_]+$/', $database);

        if (! app()->environment('testing') || env('PHASE07_DISPOSABLE') !== 'YES' || (! $sqlite && ! $mysql)) {
            throw new RuntimeException('PhaseSevenQaSeeder requires APP_ENV=testing, PHASE07_DISPOSABLE=YES, and an explicitly named Phase 07 disposable database.');
        }
    }

    private function residence(Organization $organization, string $code, string $name): array
    {
        return [
            'organization_id' => $organization->id,
            'name' => $name,
            'code' => $code,
            'address_line_1' => 'Synthetic QA address',
            'city' => 'Casablanca',
            'status' => 'operational',
        ];
    }

    private function user(Organization $organization, Residence $residence, string $email, string $name, string $role, string $language, bool $verified = true): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
            'password' => Hash::make('phase07-disposable'),
            'preferred_language' => $language,
            'current_organization_id' => $organization->id,
            'current_residence_id' => $residence->id,
        ]);
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => $role !== 'coproprietaire']);
        $residence->users()->attach($user);

        return $user;
    }

    private function contact(Organization $organization, string $firstName, string $lastName, ?User $user): Contact
    {
        $contact = Contact::create([
            'organization_id' => $organization->id,
            'type' => 'individual',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'primary_email' => $user?->email,
            'preferred_language' => $user?->preferred_language ?? 'fr',
            'notification_channel' => 'none',
            'notes' => 'Synthetic Phase 07 QA record.',
        ]);
        if ($user) {
            $user->contacts()->attach($contact, [
                'organization_id' => $organization->id,
                'linked_by' => User::where('email', 'qa.manager@evosyndic.test')->value('id'),
                'linked_at' => now(),
            ]);
        }

        return $contact;
    }

    private function lot(Residence $residence, string $reference, string $number): Lot
    {
        return Lot::create([
            'residence_id' => $residence->id,
            'reference' => $reference,
            'lot_number' => $number,
            'type' => 'apartment',
            'title' => 'Synthetic QA lot',
            'occupancy_status' => 'owner_occupied',
            'active' => true,
        ]);
    }

    private function ownership(Lot $lot, Contact $contact, string $percentage, string $startsOn, ?string $endsOn = null): void
    {
        LotOwnership::create([
            'lot_id' => $lot->id,
            'contact_id' => $contact->id,
            'ownership_percentage' => $percentage,
            'is_primary_contact' => $percentage === '100.0000',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'notes' => 'Synthetic Phase 07 QA ownership.',
        ]);
    }

    private function rules(Organization $organization, User $maker, User $reviewer): array
    {
        $source = GovernanceRuleSource::create([
            'organization_id' => $organization->id,
            'code' => 'P07-QA-SOURCE',
            'version' => 1,
            'jurisdiction' => 'TEST-ONLY',
            'issuing_authority' => 'EvoSyndic technical QA fixture',
            'official_title' => 'SYNTHETIC TEST-ONLY SOURCE — NOT OFFICIAL OR LEGAL',
            'official_url' => 'https://example.invalid/phase07-qa',
            'last_verified_on' => today(),
            'verified_by' => $maker->id,
            'confidence' => 'source_verified',
            'notes_fr' => 'Source synthétique vérifiée uniquement pour les invariants techniques.',
            'notes_ar' => 'مصدر اصطناعي للتحقق التقني فقط.',
        ]);
        $rule = GovernanceRule::create([
            'organization_id' => $organization->id,
            'stable_code' => 'P07-QA-MAJORITY',
            'jurisdiction' => 'TEST-ONLY',
            'assembly_type' => 'ordinary',
            'resolution_category' => 'qa',
            'title_fr' => 'Règle majoritaire synthétique',
            'title_ar' => 'قاعدة أغلبية اصطناعية',
        ]);
        $common = [
            'governance_rule_source_id' => $source->id,
            'effective_from' => '2026-01-01',
            'official_source' => $source->official_title,
            'source_url' => $source->official_url,
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
            'legal_payload' => ['certified' => false, 'classification' => 'technical_test_only'],
            'assembly_type' => 'ordinary',
            'resolution_category' => 'qa',
            'title_fr' => 'Règle QA synthétique',
            'title_ar' => 'قاعدة اختبار اصطناعية',
            'rounding_policy' => 'none',
            'voting_share_source_type' => 'dedicated_governance_shares',
            'effective_date_policy' => 'meeting_date',
        ];
        $active = $rule->versions()->create($common + [
            'identifier' => 'P07-QA-MAJORITY',
            'version' => 1,
            'status' => 'active',
            'confidence' => 'professionally_reviewed',
            'review_status' => 'professionally_reviewed',
            'source_verified_by' => $maker->id,
            'source_verified_at' => now(),
            'professionally_reviewed_by' => $reviewer->id,
            'professionally_reviewed_at' => now(),
            'approved_by' => $reviewer->id,
            'approved_at' => now(),
            'activated_by' => $maker->id,
            'activated_at' => now(),
            'immutable_at' => now(),
            'active' => true,
        ]);
        $draft = $rule->versions()->create($common + [
            'identifier' => 'P07-QA-MAJORITY-DRAFT',
            'version' => 2,
            'status' => 'unverified_draft',
            'confidence' => 'unverified_draft',
            'review_status' => 'pending_professional_review',
            'active' => false,
        ]);

        return [$active, $draft];
    }

    private function evidence(Organization $organization, Residence $residence, Assembly $assembly, User $actor)
    {
        $bytes = 'Synthetic Phase 07 reconciliation evidence; no legal effect.';
        $path = 'qa/phase07/reconciliation-evidence.txt';
        Storage::disk('local')->put($path, $bytes);
        $document = GovernanceDocument::create([
            'organization_id' => $organization->id,
            'residence_id' => $residence->id,
            'assembly_id' => $assembly->id,
            'category' => 'technical_evidence',
            'title_fr' => 'Preuve synthétique de rapprochement',
            'title_ar' => 'دليل مطابقة اصطناعي',
            'audience' => 'managers',
        ]);

        return $document->versions()->create([
            'version' => 1,
            'name' => 'reconciliation-evidence.txt',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'uploaded_by' => $actor->id,
            'frozen_at' => now(),
        ]);
    }

    private function createStageAssemblies(Organization $organization, Residence $primary, Residence $secondary, Residence $isolated, User $actor, $draftRule): void
    {
        foreach ([
            [$primary, 'P07-QA-DRAFT', 'draft', '2026-09-10'],
            [$primary, 'P07-QA-PREPARING', 'preparing', '2026-09-12'],
            [$primary, 'P07-QA-QUORUM-FAIL', 'adjourned_no_quorum', '2026-09-14'],
            [$primary, 'P07-QA-INDETERMINATE', 'preparing', '2026-09-16'],
            [$secondary, 'P07-QA-SECONDARY', 'scheduled', '2026-09-18'],
            [$isolated, 'P07-QA-ISOLATION-CONTROL', 'preparing', '2026-09-20'],
        ] as [$residence, $reference, $status, $meetingDate]) {
            Assembly::create([
                'organization_id' => $residence->organization_id,
                'residence_id' => $residence->id,
                'reference' => $reference,
                'type' => 'ordinary',
                'convocation_number' => 1,
                'status' => $status,
                'convening_authority' => 'Synthetic technical QA only',
                'meeting_date' => $meetingDate,
                'eligibility_on' => '2026-08-01',
                'starts_at' => '18:00',
                'location' => 'Disposable QA room',
                'timezone' => 'Africa/Casablanca',
                'convocation_deadline_at' => '2026-08-20 00:00:00',
                'documents_available_at' => '2026-08-20 00:00:00',
                'created_by' => $actor->id,
                'quorum_status' => $status === 'adjourned_no_quorum' ? 'failed' : 'pending',
                'legal_verification_status' => $draftRule->status,
            ]);
        }
    }
}
