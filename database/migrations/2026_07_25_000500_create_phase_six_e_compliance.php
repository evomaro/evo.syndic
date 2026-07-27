<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('compliance_email_enabled')->default(false);
        });

        Schema::create('compliance_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('jurisdiction', 100);
            $table->string('name_fr');
            $table->string('name_ar');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'cmp_authority_org_code_uq');
        });

        Schema::create('compliance_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('authority_id')->constrained('compliance_authorities')->restrictOnDelete();
            $table->string('official_title');
            $table->text('official_url')->nullable();
            $table->string('document_reference')->nullable();
            $table->date('published_on')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('last_verified_on')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('confidence', 64)->default('unverified_draft');
            $table->text('notes_fr')->nullable();
            $table->text('notes_ar')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('compliance_sources')->restrictOnDelete();
            $table->timestamps();
            $table->index(['authority_id', 'confidence', 'effective_on'], 'cmp_source_authority_status_idx');
        });

        Schema::create('compliance_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->string('jurisdiction', 100);
            $table->string('category', 64);
            $table->foreignId('authority_id')->constrained('compliance_authorities')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'cmp_template_org_code_uq');
        });

        Schema::create('compliance_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('compliance_templates')->restrictOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('compliance_sources')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 48)->default('draft');
            $table->string('title_fr');
            $table->string('title_ar');
            $table->text('applicability_description_fr');
            $table->text('applicability_description_ar');
            $table->json('applicability_rule')->nullable();
            $table->string('schedule_type', 32);
            $table->json('deadline_rule');
            $table->text('calculation_method_fr');
            $table->text('calculation_method_ar');
            $table->text('required_evidence_fr');
            $table->text('required_evidence_ar');
            $table->string('confidence', 64)->default('unverified_draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('professional_review_required')->default(true);
            $table->string('professional_review_status', 32)->default('pending');
            $table->foreignId('professional_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('professional_reviewed_at')->nullable();
            $table->string('counsel_review_status', 32)->default('not_required');
            $table->foreignId('source_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('source_verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('activated_at')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('compliance_template_versions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['template_id', 'version'], 'cmp_template_version_uq');
            $table->index(['template_id', 'status', 'effective_from'], 'cmp_template_active_idx');
        });

        Schema::create('compliance_applicability_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('attributes');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'residence_id'], 'cmp_profile_scope_uq');
        });

        Schema::create('compliance_applicability_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('template_version_id')->constrained('compliance_template_versions')->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('outcome', 48);
            $table->json('inputs');
            $table->json('deadline_inputs')->nullable();
            $table->text('explanation_fr');
            $table->text('explanation_ar');
            $table->boolean('manual_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at');
            $table->timestamps();
            $table->index(['organization_id', 'residence_id', 'outcome'], 'cmp_decision_scope_outcome_idx');
        });

        Schema::create('compliance_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('template_id')->constrained('compliance_templates')->restrictOnDelete();
            $table->foreignId('template_version_id')->constrained('compliance_template_versions')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('compliance_sources')->restrictOnDelete();
            $table->foreignId('applicability_decision_id')->constrained('compliance_applicability_decisions')->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('occurrence_key', 190);
            $table->string('reporting_period')->nullable();
            $table->date('reporting_starts_on')->nullable();
            $table->date('reporting_ends_on')->nullable();
            $table->date('original_due_on')->nullable();
            $table->date('current_due_on')->nullable();
            $table->string('deadline_status', 32)->default('upcoming');
            $table->string('operational_status', 48)->default('upcoming');
            $table->json('deadline_inputs');
            $table->json('deadline_rule_snapshot');
            $table->string('timezone', 64);
            $table->dateTime('generated_at');
            $table->timestamps();
            $table->unique(['organization_id', 'occurrence_key'], 'cmp_obligation_occurrence_uq');
            $table->index(['organization_id', 'residence_id', 'current_due_on'], 'cmp_obligation_calendar_idx');
            $table->index(['organization_id', 'operational_status', 'deadline_status'], 'cmp_obligation_state_idx');
        });

        Schema::create('compliance_obligation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 64)->nullable();
            $table->string('assignment_type', 32);
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['obligation_id', 'assignment_type', 'ended_at'], 'cmp_assignment_active_idx');
        });

        Schema::create('compliance_obligation_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->string('from_status', 48);
            $table->string('to_status', 48);
            $table->text('reason')->nullable();
            $table->foreignId('evidence_id')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('transitioned_at');
            $table->timestamps();
            $table->index(['obligation_id', 'transitioned_at'], 'cmp_transition_history_idx');
        });

        Schema::create('compliance_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->unsignedInteger('attempt');
            $table->date('submitted_on');
            $table->string('method', 64);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamps();
            $table->unique(['obligation_id', 'attempt'], 'cmp_submission_attempt_uq');
        });

        Schema::create('compliance_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('compliance_submissions')->restrictOnDelete();
            $table->string('type', 48);
            $table->string('title');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->index(['obligation_id', 'type'], 'cmp_evidence_obligation_type_idx');
        });

        Schema::create('compliance_evidence_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evidence_id')->constrained('compliance_evidence')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['evidence_id', 'version'], 'cmp_evidence_version_uq');
        });

        Schema::table('compliance_obligation_transitions', function (Blueprint $table) {
            $table->foreign('evidence_id')->references('id')->on('compliance_evidence')->restrictOnDelete();
        });

        Schema::create('compliance_deadline_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->date('previous_due_on')->nullable();
            $table->date('new_due_on');
            $table->text('reason');
            $table->string('evidence_reference');
            $table->foreignId('overridden_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('overridden_at');
            $table->timestamps();
        });

        Schema::create('compliance_reminder_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('compliance_templates')->restrictOnDelete();
            $table->string('name');
            $table->json('triggers');
            $table->json('recipient_types');
            $table->boolean('database_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('digest')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'residence_id', 'active'], 'cmp_reminder_policy_scope_idx');
        });

        Schema::create('compliance_reminder_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('compliance_reminder_policies')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('trigger', 64);
            $table->string('channel', 16);
            $table->string('idempotency_key', 190)->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('scheduled_at');
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at', 'id'], 'cmp_reminder_dispatch_idx');
        });

        Schema::create('compliance_escalation_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('compliance_reminder_policies')->restrictOnDelete();
            $table->string('trigger', 64);
            $table->string('idempotency_key', 190)->unique();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->dateTime('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_escalation_occurrences');
        Schema::dropIfExists('compliance_reminder_occurrences');
        Schema::dropIfExists('compliance_reminder_policies');
        Schema::dropIfExists('compliance_deadline_overrides');
        Schema::dropIfExists('compliance_obligation_transitions');
        Schema::dropIfExists('compliance_evidence_versions');
        Schema::dropIfExists('compliance_evidence');
        Schema::dropIfExists('compliance_submissions');
        Schema::dropIfExists('compliance_obligation_assignments');
        Schema::dropIfExists('compliance_obligations');
        Schema::dropIfExists('compliance_applicability_decisions');
        Schema::dropIfExists('compliance_applicability_profiles');
        Schema::dropIfExists('compliance_template_versions');
        Schema::dropIfExists('compliance_templates');
        Schema::dropIfExists('compliance_sources');
        Schema::dropIfExists('compliance_authorities');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('compliance_email_enabled');
        });
    }
};
