<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_contracts', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->nullable()->after('supplier_service_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->unique('renewed_from_id', 'supplier_contract_single_successor_unique');
        });
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('supplier_contracts', 'supplier_contract_renewed_from_idx')) {
            Schema::table('supplier_contracts', fn (Blueprint $table) => $table->dropIndex('supplier_contract_renewed_from_idx'));
        }

        Schema::table('supplier_contract_attachments', function (Blueprint $table) {
            $table->boolean('reusable_on_renewal')->default(false)->after('checksum');
            $table->string('status')->default('active')->after('reusable_on_renewal');
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->foreignId('replaces_id')->nullable()->after('archived_by')->constrained('supplier_contract_attachments')->restrictOnDelete();
            $table->unique(['supplier_contract_id', 'version'], 'contract_attachment_version_unique');
        });
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('supplier_contract_attachments', 'contract_attachment_contract_idx')) {
            Schema::table('supplier_contract_attachments', fn (Blueprint $table) => $table->dropIndex('contract_attachment_contract_idx'));
        }

        Schema::table('residence_announcements', function (Blueprint $table) {
            $table->unsignedSmallInteger('publication_attempts')->default(0)->after('scheduled_for');
            $table->timestamp('last_publication_attempt_at')->nullable()->after('publication_attempts');
            $table->timestamp('publication_failed_at')->nullable()->after('last_publication_attempt_at');
            $table->string('publication_failure_code', 80)->nullable()->after('publication_failed_at');
            $table->string('publication_failure_summary', 255)->nullable()->after('publication_failure_code');
            $table->timestamp('publication_failure_resolved_at')->nullable()->after('publication_failure_summary');
        });

        Schema::table('residence_documents', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('expires_at');
            $table->unsignedSmallInteger('publication_attempts')->default(0)->after('scheduled_for');
            $table->timestamp('last_publication_attempt_at')->nullable()->after('publication_attempts');
            $table->timestamp('publication_failed_at')->nullable()->after('last_publication_attempt_at');
            $table->string('publication_failure_code', 80)->nullable()->after('publication_failed_at');
            $table->string('publication_failure_summary', 255)->nullable()->after('publication_failure_code');
            $table->timestamp('publication_failure_resolved_at')->nullable()->after('publication_failure_summary');
            $table->index(['residence_id', 'status', 'scheduled_for'], 'res_doc_schedule_idx');
        });
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('residence_documents', 'res_doc_residence_fk_idx')) {
            Schema::table('residence_documents', fn (Blueprint $table) => $table->dropIndex('res_doc_residence_fk_idx'));
        }

        Schema::create('document_generation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 80);
            $table->morphs('subject', 'doc_generation_subject');
            $table->unsignedInteger('version')->default(1);
            $table->string('number')->nullable();
            $table->string('locale', 2)->nullable();
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_summary', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_type', 'subject_type', 'subject_id', 'version'], 'document_generation_unique');
            $table->index(['residence_id', 'status', 'failed_at'], 'document_generation_failure_idx');
        });

        Schema::table('notification_dispatches', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('residence_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 80)->nullable()->after('event_key');
            $table->string('status', 30)->default('delivered')->after('channel');
            $table->unsignedSmallInteger('attempt_count')->default(1)->after('status');
            $table->timestamp('last_attempted_at')->nullable()->after('attempt_count');
            $table->index(['organization_id', 'residence_id', 'event_type'], 'notification_dispatch_scope_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('supplier_contracts', 'supplier_contract_renewed_from_idx')) {
            Schema::table('supplier_contracts', fn (Blueprint $table) => $table->index('renewed_from_id', 'supplier_contract_renewed_from_idx'));
        }
        Schema::table('supplier_contracts', function (Blueprint $table) {
            if (Schema::hasIndex('supplier_contracts', 'supplier_contract_single_successor_unique')) {
                $table->dropUnique('supplier_contract_single_successor_unique');
            }
            if (Schema::hasColumn('supplier_contracts', 'expense_category_id')) {
                $table->dropConstrainedForeignId('expense_category_id');
            }
        });

        Schema::table('notification_dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('residence_id');
            $table->dropIndex('notification_dispatch_scope_idx');
            $table->dropColumn(['event_type', 'status', 'attempt_count', 'last_attempted_at']);
        });

        Schema::dropIfExists('document_generation_attempts');

        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('residence_documents', 'res_doc_residence_fk_idx')) {
            Schema::table('residence_documents', fn (Blueprint $table) => $table->index('residence_id', 'res_doc_residence_fk_idx'));
        }
        Schema::table('residence_documents', function (Blueprint $table) {
            $table->dropIndex('res_doc_schedule_idx');
            $table->dropColumn(['scheduled_for', 'publication_attempts', 'last_publication_attempt_at', 'publication_failed_at', 'publication_failure_code', 'publication_failure_summary', 'publication_failure_resolved_at']);
        });

        Schema::table('residence_announcements', function (Blueprint $table) {
            $table->dropColumn(['publication_attempts', 'last_publication_attempt_at', 'publication_failed_at', 'publication_failure_code', 'publication_failure_summary', 'publication_failure_resolved_at']);
        });

        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('supplier_contract_attachments', 'contract_attachment_contract_idx')) {
            Schema::table('supplier_contract_attachments', fn (Blueprint $table) => $table->index('supplier_contract_id', 'contract_attachment_contract_idx'));
        }
        Schema::table('supplier_contract_attachments', function (Blueprint $table) {
            $table->dropUnique('contract_attachment_version_unique');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropConstrainedForeignId('replaces_id');
            $table->dropColumn(['reusable_on_renewal', 'status', 'archived_at']);
        });
    }
};
