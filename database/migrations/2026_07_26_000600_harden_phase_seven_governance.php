<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_rule_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->unsignedInteger('version')->default(1);
            $table->string('jurisdiction', 100);
            $table->string('issuing_authority')->nullable();
            $table->string('official_title');
            $table->string('official_url', 2000)->nullable();
            $table->string('document_reference')->nullable();
            $table->date('published_on')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('last_verified_on')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('confidence', 64)->default('unverified_draft');
            $table->text('notes_fr')->nullable();
            $table->text('notes_ar')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code', 'version'], 'gov_rule_source_org_code_version_uq');
            $table->index(['organization_id', 'confidence', 'effective_on'], 'gov_rule_source_scope_confidence_idx');
        });

        Schema::create('governance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('stable_code', 100);
            $table->string('jurisdiction', 100);
            $table->string('assembly_type', 40);
            $table->string('resolution_category', 80);
            $table->string('title_fr');
            $table->string('title_ar');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'stable_code'], 'gov_rule_org_code_uq');
            $table->index(['organization_id', 'assembly_type', 'resolution_category'], 'gov_rule_scope_type_category_idx');
        });

        Schema::table('governance_rule_versions', function (Blueprint $table) {
            $table->foreignId('governance_rule_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('governance_rule_source_id')->nullable()->after('governance_rule_id')->constrained()->restrictOnDelete();
            $table->string('status', 48)->default('unverified_draft')->after('version');
            $table->string('confidence', 64)->default('unverified_draft')->after('status');
            $table->string('assembly_type', 40)->nullable()->after('confidence');
            $table->string('resolution_category', 80)->nullable()->after('assembly_type');
            $table->string('title_fr')->nullable()->after('resolution_category');
            $table->string('title_ar')->nullable()->after('title_fr');
            $table->string('rounding_policy', 40)->default('none')->after('comparison');
            $table->string('voting_share_source_type', 80)->default('unverified')->after('rounding_policy');
            $table->json('notice_requirements')->nullable()->after('eligibility_restrictions');
            $table->json('required_evidence')->nullable()->after('notice_requirements');
            $table->string('effective_date_policy', 80)->default('meeting_date')->after('required_evidence');
            $table->foreignId('source_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('source_verified_at')->nullable();
            $table->foreignId('professionally_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('professionally_reviewed_at')->nullable();
            $table->foreignId('counsel_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('counsel_reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('supersedes_version_id')->nullable()->constrained('governance_rule_versions')->restrictOnDelete();
            $table->timestamp('immutable_at')->nullable();
            $table->index(['governance_rule_id', 'status', 'effective_from'], 'gov_rule_version_workflow_idx');
        });

        Schema::create('governance_voting_share_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->unsignedInteger('version')->default(1);
            $table->string('source_type', 80);
            $table->string('status', 48)->default('unverified_draft');
            $table->unsignedTinyInteger('decimal_precision')->default(4);
            $table->decimal('expected_total', 24, 8)->nullable();
            $table->unsignedBigInteger('denominator')->nullable();
            $table->foreignId('governance_document_version_id')->nullable();
            $table->foreign('governance_document_version_id', 'gov_share_document_fk')->references('id')->on('governance_document_versions')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->json('configuration');
            $table->timestamps();
            $table->unique(['residence_id', 'code', 'version'], 'gov_share_source_res_code_version_uq');
            $table->index(['organization_id', 'residence_id', 'status'], 'gov_share_source_scope_status_idx');
        });

        Schema::create('assembly_agenda_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->foreignId('parent_version_id')->nullable()->constrained('assembly_agenda_versions')->restrictOnDelete();
            $table->string('checksum', 64);
            $table->json('frozen_payload')->nullable();
            $table->text('change_reason')->nullable();
            $table->string('convocation_impact', 48)->default('not_assessed');
            $table->text('impact_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('opened_for_session_at')->nullable();
            $table->timestamps();
            $table->unique(['assembly_id', 'version'], 'assembly_agenda_version_uq');
            $table->index(['organization_id', 'residence_id', 'status'], 'assembly_agenda_scope_status_idx');
        });

        Schema::create('assembly_eligibility_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('voting_share_source_id')->nullable()->constrained('governance_voting_share_sources')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->date('eligibility_on');
            $table->string('status', 48)->default('preview');
            $table->string('input_fingerprint', 64);
            $table->timestamp('ownership_boundary_at')->nullable();
            $table->unsignedBigInteger('interest_count')->default(0);
            $table->unsignedBigInteger('eligible_weight_numerator')->default(0);
            $table->unsignedBigInteger('weight_denominator')->default(1);
            $table->json('findings');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('stale_at')->nullable();
            $table->text('stale_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['assembly_id', 'version'], 'assembly_eligibility_snapshot_uq');
            $table->index(['organization_id', 'residence_id', 'status'], 'assembly_eligibility_scope_status_idx');
        });

        Schema::create('assembly_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('electorate_id')->nullable()->constrained('assembly_electorates')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('participant_key', 120);
            $table->string('display_name_snapshot');
            $table->string('capacity', 48);
            $table->string('status', 32)->default('pending');
            $table->json('interest_scope')->nullable();
            $table->timestamps();
            $table->unique(['assembly_id', 'participant_key'], 'assembly_participant_key_uq');
            $table->index(['organization_id', 'residence_id', 'status'], 'assembly_participant_scope_status_idx');
        });

        Schema::create('assembly_secret_ballot_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('resolution_id')->constrained('assembly_resolutions')->restrictOnDelete();
            $table->unsignedBigInteger('for_weight')->default(0);
            $table->unsignedBigInteger('against_weight')->default(0);
            $table->unsignedBigInteger('abstention_weight')->default(0);
            $table->unsignedBigInteger('invalid_weight')->default(0);
            $table->unsignedBigInteger('not_cast_weight')->default(0);
            $table->unsignedBigInteger('weight_denominator')->default(1);
            $table->foreignId('reconciliation_document_version_id');
            $table->foreign('reconciliation_document_version_id', 'secret_ballot_reconciliation_fk')->references('id')->on('governance_document_versions')->restrictOnDelete();
            $table->string('checksum', 64);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique('resolution_id', 'assembly_secret_result_resolution_uq');
        });

        Schema::create('assembly_resolution_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_id')->constrained('assembly_resolutions')->restrictOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('evidence_version_id')->nullable();
            $table->foreign('evidence_version_id', 'resolution_transition_evidence_fk')->references('id')->on('governance_document_versions')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->timestamp('transitioned_at');
            $table->timestamps();
            $table->unique(['resolution_id', 'idempotency_key'], 'assembly_resolution_transition_idem_uq');
        });

        Schema::create('resolution_execution_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_execution_action_id');
            $table->foreign('resolution_execution_action_id', 'resolution_execution_event_action_fk')->references('id')->on('resolution_execution_actions')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('evidence_version_id')->nullable()->constrained('governance_document_versions')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['resolution_execution_action_id', 'occurred_at'], 'resolution_execution_event_time_idx');
        });

        Schema::create('assembly_minutes_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_minutes_id')->constrained()->restrictOnDelete();
            $table->foreignId('minute_version_id')->constrained('assembly_minute_versions')->restrictOnDelete();
            $table->string('approval_type', 40);
            $table->string('status', 32);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('evidence_version_id')->nullable()->constrained('governance_document_versions')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->unique(['minute_version_id', 'approval_type'], 'assembly_minutes_approval_type_uq');
        });

        Schema::table('assemblies', function (Blueprint $table) {
            $table->date('eligibility_on')->nullable()->after('meeting_date');
            $table->foreignId('active_agenda_version_id')->nullable()->constrained('assembly_agenda_versions')->restrictOnDelete();
            $table->foreignId('session_agenda_version_id')->nullable()->constrained('assembly_agenda_versions')->restrictOnDelete();
            $table->foreignId('eligibility_snapshot_id')->nullable()->constrained('assembly_eligibility_snapshots')->restrictOnDelete();
            $table->string('vote_mode', 40)->default('recorded_interest');
            $table->string('legal_verification_status', 48)->default('unverified');
            $table->string('finalization_fingerprint', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('assembly_agenda_items', function (Blueprint $table) {
            $table->foreignId('agenda_version_id')->nullable()->constrained('assembly_agenda_versions')->restrictOnDelete();
            $table->foreignId('presenter_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('governance_rule_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supporting_document_version_id')->nullable()->constrained('governance_document_versions')->restrictOnDelete();
            $table->boolean('information_only')->default(false);
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
        });

        Schema::table('assembly_electorates', function (Blueprint $table) {
            $table->foreignId('eligibility_snapshot_id')->nullable()->constrained('assembly_eligibility_snapshots')->restrictOnDelete();
            $table->string('share_source_code', 100)->nullable();
            $table->unsignedInteger('share_source_version')->nullable();
            $table->unsignedBigInteger('original_weight_numerator')->nullable();
            $table->text('inclusion_explanation')->nullable();
        });

        Schema::table('electorate_corrections', function (Blueprint $table) {
            $table->string('correction_type', 40)->default('technical_correction');
            $table->foreignId('evidence_version_id')->nullable()->constrained('governance_document_versions')->restrictOnDelete();
        });

        Schema::table('convocations', function (Blueprint $table) {
            $table->foreignId('agenda_version_id')->nullable()->constrained('assembly_agenda_versions')->restrictOnDelete();
            $table->string('legal_service_status', 48)->default('unverified');
        });

        Schema::table('assembly_quorum_snapshots', function (Blueprint $table) {
            $table->string('outcome', 48)->default('indeterminate');
            $table->string('input_fingerprint', 64)->nullable();
            $table->string('legal_verification_status', 48)->default('unverified');
            $table->timestamp('stale_at')->nullable();
            $table->text('stale_reason')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
        });

        Schema::table('assembly_proxies', function (Blueprint $table) {
            $table->string('legal_verification_status', 48)->default('professional_review_required');
        });

        Schema::table('assembly_ballots', function (Blueprint $table) {
            $table->string('vote_mode', 40)->default('recorded_interest');
            $table->string('idempotency_key', 120)->nullable();
            $table->unique(['resolution_id', 'idempotency_key'], 'assembly_ballot_idem_uq');
        });

        Schema::table('assembly_resolutions', function (Blueprint $table) {
            $table->string('execution_status', 32)->default('not_started');
            $table->string('legal_validity_status', 48)->default('unverified');
            $table->string('vote_mode', 40)->default('recorded_interest');
            $table->timestamp('voting_opened_at')->nullable();
            $table->foreignId('voting_opened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voting_closed_at')->nullable();
            $table->foreignId('voting_closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('immutable_at')->nullable();
            $table->text('challenge_reason')->nullable();
            $table->timestamp('challenged_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('supersedes_resolution_id')->nullable()->constrained('assembly_resolutions')->restrictOnDelete();
        });

        Schema::table('resolution_execution_actions', function (Blueprint $table) {
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('responsible_role', 80)->nullable();
            $table->string('priority', 24)->default('normal');
            $table->json('dependency_action_ids')->nullable();
            $table->foreignId('compliance_obligation_id')->nullable()->constrained('compliance_obligations')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('assembly_minute_versions', function (Blueprint $table) {
            $table->string('finalization_fingerprint', 64)->nullable();
            $table->string('legal_verification_status', 48)->default('unverified');
            $table->timestamp('immutable_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assembly_proxies', function (Blueprint $table) {
            $table->dropColumn('legal_verification_status');
        });
        Schema::table('assembly_minute_versions', function (Blueprint $table) {
            $table->dropColumn(['finalization_fingerprint', 'legal_verification_status', 'immutable_at']);
        });
        Schema::table('resolution_execution_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_user_id');
            $table->dropConstrainedForeignId('compliance_obligation_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['responsible_role', 'priority', 'dependency_action_ids', 'reviewed_at']);
        });
        Schema::table('assembly_resolutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voting_opened_by');
            $table->dropConstrainedForeignId('voting_closed_by');
            $table->dropConstrainedForeignId('supersedes_resolution_id');
            $table->dropColumn(['execution_status', 'legal_validity_status', 'vote_mode', 'voting_opened_at', 'voting_closed_at', 'immutable_at', 'challenge_reason', 'challenged_at', 'suspension_reason', 'suspended_at']);
        });
        Schema::table('assembly_ballots', function (Blueprint $table) {
            $table->dropUnique('assembly_ballot_idem_uq');
            $table->dropColumn(['vote_mode', 'idempotency_key']);
        });
        Schema::table('assembly_quorum_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['outcome', 'input_fingerprint', 'legal_verification_status', 'stale_at', 'stale_reason', 'confirmed_at']);
        });
        Schema::table('convocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agenda_version_id');
            $table->dropColumn('legal_service_status');
        });
        Schema::table('assembly_electorates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eligibility_snapshot_id');
            $table->dropColumn(['share_source_code', 'share_source_version', 'original_weight_numerator', 'inclusion_explanation']);
        });
        Schema::table('electorate_corrections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evidence_version_id');
            $table->dropColumn('correction_type');
        });
        Schema::table('assembly_agenda_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agenda_version_id');
            $table->dropConstrainedForeignId('presenter_user_id');
            $table->dropConstrainedForeignId('governance_rule_version_id');
            $table->dropConstrainedForeignId('supporting_document_version_id');
            $table->dropColumn(['information_only', 'estimated_duration_minutes']);
        });
        Schema::table('assemblies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_agenda_version_id');
            $table->dropConstrainedForeignId('session_agenda_version_id');
            $table->dropConstrainedForeignId('eligibility_snapshot_id');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['eligibility_on', 'vote_mode', 'legal_verification_status', 'finalization_fingerprint', 'finalized_at']);
        });

        foreach ([
            'assembly_minutes_approvals',
            'resolution_execution_events',
            'assembly_resolution_transitions',
            'assembly_secret_ballot_aggregates',
            'assembly_participants',
            'assembly_eligibility_snapshots',
            'assembly_agenda_versions',
            'governance_voting_share_sources',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('governance_rule_versions', function (Blueprint $table) {
                $table->dropIndex('gov_rule_version_workflow_idx');
            });
        }
        Schema::table('governance_rule_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('governance_rule_id');
            $table->dropConstrainedForeignId('governance_rule_source_id');
            $table->dropConstrainedForeignId('source_verified_by');
            $table->dropConstrainedForeignId('professionally_reviewed_by');
            $table->dropConstrainedForeignId('counsel_reviewed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('activated_by');
            $table->dropConstrainedForeignId('supersedes_version_id');
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropIndex('gov_rule_version_workflow_idx');
            }
            $table->dropColumn([
                'status', 'confidence', 'assembly_type', 'resolution_category', 'title_fr', 'title_ar',
                'rounding_policy', 'voting_share_source_type', 'notice_requirements', 'required_evidence',
                'effective_date_policy', 'source_verified_at', 'professionally_reviewed_at',
                'counsel_reviewed_at', 'approved_at', 'activated_at', 'immutable_at',
            ]);
        });

        Schema::dropIfExists('governance_rules');
        Schema::dropIfExists('governance_rule_sources');
    }
};
