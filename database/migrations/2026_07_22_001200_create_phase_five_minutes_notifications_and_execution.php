<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assembly_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('reservations_fr')->nullable();
            $table->text('reservations_ar')->nullable();
            $table->text('incidents_fr')->nullable();
            $table->text('incidents_ar')->nullable();
            $table->foreignId('reviewed_version_id')->nullable();
            $table->foreignId('signed_version_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('assembly_id', 'assembly_minutes_uq');
        });

        Schema::create('assembly_minute_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_minutes_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('kind', 24)->default('minutes');
            $table->string('status', 20)->default('draft');
            $table->string('disk', 24)->default('local');
            $table->string('path');
            $table->string('checksum', 64);
            $table->json('frozen_payload');
            $table->string('payload_checksum', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_version_id')->nullable()->constrained('assembly_minute_versions')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->json('signatures')->nullable();
            $table->timestamps();
            $table->unique(['assembly_minutes_id', 'version'], 'assembly_minute_version_uq');
        });

        Schema::table('assembly_minutes', function (Blueprint $table) {
            $table->foreign('reviewed_version_id', 'assembly_minutes_reviewed_version_fk')->references('id')->on('assembly_minute_versions')->restrictOnDelete();
            $table->foreign('signed_version_id', 'assembly_minutes_signed_version_fk')->references('id')->on('assembly_minute_versions')->restrictOnDelete();
        });

        Schema::create('decision_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->constrained()->restrictOnDelete();
            $table->foreignId('electorate_id')->constrained('assembly_electorates')->restrictOnDelete();
            $table->foreignId('signed_minutes_version_id')->constrained('assembly_minute_versions')->restrictOnDelete();
            $table->foreignId('decision_document_version_id')->nullable()->constrained('governance_document_versions')->restrictOnDelete();
            $table->string('recipient_name_snapshot');
            $table->text('address_snapshot')->nullable();
            $table->dateTime('deadline_at');
            $table->string('delivery_channel', 40)->default('pending_legal_delivery');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('idempotency_key', 100);
            $table->dateTime('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['assembly_id', 'electorate_id'], 'decision_notification_recipient_uq');
            $table->unique('idempotency_key', 'decision_notification_idem_uq');
            $table->index(['residence_id', 'status', 'deadline_at'], 'decision_notification_deadline_idx');
        });

        Schema::create('decision_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_notification_id')->constrained()->restrictOnDelete();
            $table->string('channel', 40);
            $table->string('status', 24);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('attempted_at');
            $table->string('proof_disk', 24)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_checksum', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('success_key', 100)->nullable();
            $table->timestamps();
            $table->unique(['decision_notification_id', 'success_key'], 'decision_delivery_success_uq');
        });

        Schema::create('resolution_execution_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('resolution_id')->constrained('assembly_resolutions')->restrictOnDelete();
            $table->string('action_type', 60);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 24)->default('pending');
            $table->text('description');
            $table->text('completion_result')->nullable();
            $table->text('blockers')->nullable();
            $table->string('evidence_disk', 24)->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('related_type', 80)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('source_key', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['resolution_id', 'source_key'], 'execution_resolution_source_uq');
            $table->index(['residence_id', 'status', 'due_on'], 'execution_scope_due_idx');
        });

        Schema::create('governance_notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('assembly_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 80);
            $table->string('event_key', 160);
            $table->string('channel', 20);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('attempt_count')->default(1);
            $table->dateTime('last_attempted_at');
            $table->text('last_error')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_key', 'channel'], 'gov_notification_dispatch_uq');
            $table->index(['residence_id', 'status', 'created_at'], 'gov_notification_status_idx');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['governance_notification_dispatches', 'resolution_execution_actions', 'decision_delivery_attempts', 'decision_notifications', 'assembly_minute_versions', 'assembly_minutes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
