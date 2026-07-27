<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->string('category', 60);
            $table->string('title_fr');
            $table->string('title_ar')->nullable();
            $table->string('audience', 24)->default('owners');
            $table->string('status', 20)->default('draft');
            $table->foreignId('published_version_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('archive_reason')->nullable();
            $table->timestamps();
            $table->index(['assembly_id', 'status', 'category'], 'gov_document_assembly_status_idx');
        });

        Schema::create('governance_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('disk', 24)->default('local');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('replaces_version_id')->nullable()->constrained('governance_document_versions')->restrictOnDelete();
            $table->dateTime('frozen_at')->nullable();
            $table->timestamps();
            $table->unique(['governance_document_id', 'version'], 'gov_document_version_uq');
        });

        Schema::table('governance_documents', function (Blueprint $table) {
            $table->foreign('published_version_id', 'gov_document_published_version_fk')->references('id')->on('governance_document_versions')->restrictOnDelete();
        });

        Schema::create('governance_document_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_document_version_id');
            $table->foreign('governance_document_version_id', 'gov_doc_access_version_fk')->references('id')->on('governance_document_versions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 20)->default('downloaded');
            $table->string('ip_hash', 64)->nullable();
            $table->dateTime('accessed_at');
            $table->timestamps();
            $table->index(['governance_document_version_id', 'accessed_at'], 'gov_document_access_time_idx');
        });

        Schema::create('convocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('issued_at');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('legal_deadline_at');
            $table->boolean('late_exception')->default(false);
            $table->text('late_exception_reason')->nullable();
            $table->string('disk', 24)->default('local');
            $table->string('path');
            $table->string('checksum', 64);
            $table->json('frozen_payload');
            $table->timestamps();
            $table->unique(['assembly_id', 'version'], 'convocation_assembly_version_uq');
        });

        Schema::create('convocation_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocation_id')->constrained()->restrictOnDelete();
            $table->foreignId('electorate_id')->constrained('assembly_electorates')->restrictOnDelete();
            $table->string('recipient_name_snapshot');
            $table->text('address_snapshot')->nullable();
            $table->string('delivery_method', 40)->default('pending_legal_delivery');
            $table->string('status', 24)->default('pending');
            $table->dateTime('notified_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();
            $table->unique(['convocation_id', 'electorate_id'], 'convocation_recipient_uq');
            $table->index(['convocation_id', 'status'], 'convocation_recipient_status_idx');
        });

        Schema::create('convocation_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocation_recipient_id')->constrained()->restrictOnDelete();
            $table->string('method', 40);
            $table->string('status', 24);
            $table->dateTime('attempted_at');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('proof_disk', 24)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_checksum', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('success_key', 100)->nullable();
            $table->timestamps();
            $table->unique(['convocation_recipient_id', 'success_key'], 'convocation_delivery_success_uq');
        });

        Schema::create('assembly_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('electorate_id')->constrained('assembly_electorates')->restrictOnDelete();
            $table->string('status', 24)->default('absent');
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->unsignedBigInteger('active_weight_numerator')->default(0);
            $table->unsignedBigInteger('active_weight_denominator')->default(10000);
            $table->string('identity_verification_method')->nullable();
            $table->string('signature_evidence_path')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['assembly_id', 'electorate_id'], 'attendance_entitlement_uq');
            $table->index(['assembly_id', 'status'], 'attendance_assembly_status_idx');
        });

        Schema::create('assembly_attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained('assembly_attendance_records')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->unsignedBigInteger('weight_numerator');
            $table->unsignedBigInteger('weight_denominator')->default(10000);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->dateTime('effective_at');
            $table->timestamps();
            $table->index(['attendance_record_id', 'effective_at'], 'attendance_event_time_idx');
        });

        Schema::create('assembly_proxies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('principal_electorate_id')->constrained('assembly_electorates')->restrictOnDelete();
            $table->foreignId('representative_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('representative_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->string('status', 24)->default('submitted');
            $table->unsignedBigInteger('entitlement_weight_numerator');
            $table->unsignedBigInteger('entitlement_weight_denominator')->default(10000);
            $table->string('document_disk', 24)->default('local');
            $table->string('document_path');
            $table->string('document_checksum', 64);
            $table->dateTime('submitted_at');
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->string('active_principal_slot', 100)->nullable();
            $table->timestamps();
            $table->unique(['assembly_id', 'active_principal_slot'], 'proxy_active_principal_uq');
            $table->index(['assembly_id', 'status'], 'proxy_assembly_status_idx');
        });

        Schema::create('assembly_proxy_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxy_id')->constrained('assembly_proxies')->restrictOnDelete();
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->dateTime('transitioned_at');
            $table->timestamps();
        });

        Schema::create('assembly_quorum_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('governance_rule_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('eligible_headcount');
            $table->unsignedBigInteger('present_or_represented_headcount');
            $table->unsignedBigInteger('eligible_weight_numerator');
            $table->unsignedBigInteger('represented_weight_numerator');
            $table->unsignedBigInteger('weight_denominator')->default(10000);
            $table->unsignedBigInteger('threshold_numerator');
            $table->unsignedBigInteger('threshold_denominator');
            $table->boolean('quorum_met');
            $table->json('input_snapshot');
            $table->string('checksum', 64);
            $table->foreignId('calculated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('calculated_at');
            $table->timestamps();
            $table->unique(['assembly_id', 'sequence'], 'quorum_assembly_sequence_uq');
        });

        Schema::create('assembly_ballots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('resolution_id')->constrained('assembly_resolutions')->restrictOnDelete();
            $table->foreignId('electorate_id')->constrained('assembly_electorates')->restrictOnDelete();
            $table->foreignId('voter_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('represented_electorate_id')->nullable()->constrained('assembly_electorates')->restrictOnDelete();
            $table->foreignId('proxy_id')->nullable()->constrained('assembly_proxies')->restrictOnDelete();
            $table->unsignedBigInteger('weight_numerator');
            $table->unsignedBigInteger('weight_denominator')->default(10000);
            $table->json('ownership_unit_snapshot');
            $table->json('rule_snapshot');
            $table->string('choice', 24);
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('entered_at');
            $table->string('signed_evidence_path')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['resolution_id', 'electorate_id'], 'ballot_resolution_entitlement_uq');
            $table->index(['assembly_id', 'resolution_id', 'choice'], 'ballot_resolution_choice_idx');
        });

        Schema::create('ballot_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ballot_id')->constrained('assembly_ballots')->restrictOnDelete();
            $table->string('from_choice', 24);
            $table->string('to_choice', 24);
            $table->text('reason');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('corrected_at');
            $table->timestamps();
        });

        Schema::create('resolution_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_id')->constrained('assembly_resolutions')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('total_eligible_weight');
            $table->unsignedBigInteger('present_weight');
            $table->unsignedBigInteger('represented_weight');
            $table->unsignedBigInteger('for_weight');
            $table->unsignedBigInteger('against_weight');
            $table->unsignedBigInteger('abstention_weight');
            $table->unsignedBigInteger('invalid_weight');
            $table->unsignedBigInteger('non_participating_weight');
            $table->unsignedBigInteger('numerator');
            $table->unsignedBigInteger('denominator');
            $table->unsignedBigInteger('threshold_numerator');
            $table->unsignedBigInteger('threshold_denominator');
            $table->string('comparison', 4);
            $table->boolean('adopted');
            $table->string('rule_identifier', 80);
            $table->unsignedInteger('rule_version');
            $table->json('rule_snapshot');
            $table->json('ballot_snapshot');
            $table->string('checksum', 64);
            $table->foreignId('finalized_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('finalized_at');
            $table->foreignId('supersedes_result_id')->nullable()->constrained('resolution_results')->restrictOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['resolution_id', 'version'], 'resolution_result_version_uq');
            $table->index(['resolution_id', 'adopted', 'finalized_at'], 'resolution_result_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['resolution_results', 'ballot_corrections', 'assembly_ballots', 'assembly_quorum_snapshots', 'assembly_proxy_events', 'assembly_proxies', 'assembly_attendance_events', 'assembly_attendance_records', 'convocation_delivery_attempts', 'convocation_recipients', 'convocations', 'governance_document_accesses', 'governance_document_versions', 'governance_documents'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
