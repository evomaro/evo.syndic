<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 80);
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('official_source', 255);
            $table->string('source_url', 500);
            $table->string('review_status', 64)->default('pending_counsel_review');
            $table->string('numerator_definition', 80);
            $table->string('denominator_definition', 80);
            $table->unsignedBigInteger('threshold_numerator');
            $table->unsignedBigInteger('threshold_denominator');
            $table->string('comparison', 4)->default('gt');
            $table->string('quorum_rule', 80)->default('first_headcount_half_gte');
            $table->string('abstention_behavior', 40)->default('included_in_denominator');
            $table->string('invalid_ballot_behavior', 40)->default('excluded_from_numerator');
            $table->string('second_convocation_behavior', 80)->default('no_headcount_quorum');
            $table->json('proxy_restrictions');
            $table->json('eligibility_restrictions')->nullable();
            $table->json('legal_payload');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['identifier', 'version'], 'gov_rule_identifier_version_uq');
            $table->index(['active', 'effective_from', 'effective_until'], 'gov_rule_effective_idx');
        });

        Schema::create('governance_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('appointing_resolution_id')->nullable();
            $table->foreignId('parent_mandate_id')->nullable()->constrained('governance_mandates')->restrictOnDelete();
            $table->string('supporting_document_path')->nullable();
            $table->string('supporting_document_checksum', 64)->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('end_reason')->nullable();
            $table->string('active_slot', 80)->nullable();
            $table->timestamps();
            $table->unique(['residence_id', 'active_slot'], 'gov_mandate_active_role_uq');
            $table->index(['organization_id', 'residence_id', 'status', 'ends_on'], 'gov_mandate_scope_status_idx');
        });

        Schema::create('assemblies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parent_assembly_id')->nullable()->constrained('assemblies')->restrictOnDelete();
            $table->foreignId('governance_mandate_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('type', 24);
            $table->unsignedTinyInteger('convocation_number')->default(1);
            $table->string('status', 40)->default('draft');
            $table->string('convening_authority', 160);
            $table->date('meeting_date');
            $table->time('starts_at');
            $table->time('expected_ends_at')->nullable();
            $table->string('location');
            $table->string('timezone', 60)->default('Africa/Casablanca');
            $table->dateTime('convocation_deadline_at');
            $table->dateTime('documents_available_at')->nullable();
            $table->foreignId('chairperson_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->foreignId('secretary_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('quorum_status', 24)->default('pending');
            $table->string('minutes_status', 24)->default('not_started');
            $table->text('cancellation_reason')->nullable();
            $table->text('postponement_reason')->nullable();
            $table->text('adjournment_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'reference'], 'assembly_org_reference_uq');
            $table->unique('parent_assembly_id', 'assembly_second_convocation_uq');
            $table->index(['organization_id', 'residence_id', 'status', 'meeting_date'], 'assembly_scope_status_date_idx');
            $table->index(['convocation_deadline_at', 'status'], 'assembly_deadline_status_idx');
        });

        Schema::create('assembly_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 100);
            $table->dateTime('transitioned_at');
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->unique(['assembly_id', 'idempotency_key'], 'assembly_transition_idem_uq');
            $table->index(['assembly_id', 'transitioned_at'], 'assembly_transition_time_idx');
        });

        Schema::create('assembly_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_item_id')->nullable()->constrained('assembly_agenda_items')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('display_order')->default(0);
            $table->string('title_fr');
            $table->string('title_ar')->nullable();
            $table->text('explanation_fr')->nullable();
            $table->text('explanation_ar')->nullable();
            $table->text('proposed_text_fr')->nullable();
            $table->text('proposed_text_ar')->nullable();
            $table->string('category', 60);
            $table->bigInteger('financial_impact_cents')->nullable();
            $table->boolean('resident_visible')->default(true);
            $table->text('internal_notes')->nullable();
            $table->string('status', 24)->default('draft');
            $table->dateTime('frozen_at')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('amendment_reason')->nullable();
            $table->timestamps();
            $table->index(['assembly_id', 'status', 'display_order'], 'agenda_assembly_status_order_idx');
        });

        Schema::create('assembly_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('agenda_item_id')->constrained('assembly_agenda_items')->restrictOnDelete();
            $table->foreignId('governance_rule_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_equipment_id')->nullable()->constrained('maintenance_equipment')->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_work_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->text('proposed_text_fr');
            $table->text('proposed_text_ar')->nullable();
            $table->text('final_text_fr')->nullable();
            $table->text('final_text_ar')->nullable();
            $table->string('category', 60);
            $table->string('status', 24)->default('draft');
            $table->json('financial_snapshot')->nullable();
            $table->dateTime('rule_snapshotted_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['assembly_id', 'code'], 'resolution_assembly_code_uq');
            $table->index(['assembly_id', 'status', 'category'], 'resolution_assembly_status_idx');
        });

        Schema::table('governance_mandates', function (Blueprint $table) {
            $table->foreign('appointing_resolution_id', 'gov_mandate_resolution_fk')->references('id')->on('assembly_resolutions')->restrictOnDelete();
        });

        Schema::create('resolution_rule_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_id')->constrained('assembly_resolutions')->restrictOnDelete();
            $table->foreignId('governance_rule_version_id')->constrained()->restrictOnDelete();
            $table->json('payload');
            $table->string('checksum', 64);
            $table->dateTime('snapshotted_at');
            $table->foreignId('snapshotted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('resolution_id', 'resolution_rule_snapshot_uq');
        });

        Schema::create('assembly_electorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->string('entitlement_key', 100);
            $table->json('lot_ids');
            $table->json('ownership_fractions');
            $table->unsignedBigInteger('voting_weight_numerator');
            $table->unsignedBigInteger('voting_weight_denominator')->default(10000);
            $table->string('contact_name_snapshot');
            $table->string('email_snapshot')->nullable();
            $table->string('phone_snapshot')->nullable();
            $table->text('address_snapshot')->nullable();
            $table->string('preferred_language', 2)->default('fr');
            $table->string('eligibility_status', 24)->default('eligible');
            $table->text('restriction_reason')->nullable();
            $table->json('source_ownership_ids');
            $table->boolean('generated_after_cutoff')->default(false);
            $table->dateTime('snapshotted_at');
            $table->timestamps();
            $table->unique(['assembly_id', 'entitlement_key'], 'electorate_entitlement_uq');
            $table->index(['assembly_id', 'eligibility_status'], 'electorate_assembly_eligibility_idx');
            $table->index(['organization_id', 'residence_id', 'contact_id'], 'electorate_scope_contact_idx');
        });

        Schema::create('electorate_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electorate_id')->constrained('assembly_electorates')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->json('before_payload');
            $table->json('after_payload');
            $table->text('reason');
            $table->dateTime('corrected_at');
            $table->timestamps();
            $table->index(['electorate_id', 'corrected_at'], 'electorate_correction_time_idx');
        });

        Schema::create('agenda_question_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('electorate_id')->constrained('assembly_electorates')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->text('question_fr');
            $table->text('question_ar')->nullable();
            $table->dateTime('submission_deadline_at');
            $table->string('status', 20)->default('submitted');
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['assembly_id', 'status', 'created_at'], 'agenda_question_status_idx');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['agenda_question_submissions', 'electorate_corrections', 'assembly_electorates', 'resolution_rule_snapshots', 'assembly_resolutions', 'assembly_agenda_items', 'assembly_transitions', 'assemblies', 'governance_mandates', 'governance_rule_versions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
