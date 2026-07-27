<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the status-scoped index before SQLite rebuilds the budgets table;
        // otherwise SQLite's table-alter emulation can recreate it without its
        // partial WHERE clause.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS budgets_one_approved');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE budgets DROP INDEX budgets_one_approved, DROP COLUMN approved_scope');
        }

        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('status');
            $table->json('validation_snapshot')->nullable()->after('reason');
            $table->unique(['organization_id', 'idempotency_key'], 'supplier_credit_idem_unique');
        });

        Schema::table('supplier_contracts', function (Blueprint $table) {
            $table->foreignId('renewed_from_id')->nullable()->after('supplier_id')->constrained('supplier_contracts')->restrictOnDelete();
            $table->unsignedInteger('renewal_version')->default(1)->after('renewed_from_id');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('locked_by');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable()->after('archived_by');
            $table->foreignId('unlocked_by')->nullable()->after('unlocked_at')->constrained('users')->nullOnDelete();
            $table->text('unlock_reason')->nullable()->after('unlocked_by');
        });

        Schema::table('fund_calls', function (Blueprint $table) {
            $table->foreignId('budget_id')->nullable()->after('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->string('budget_source_key', 64)->nullable()->after('budget_id');
            $table->unique(['budget_id', 'budget_source_key'], 'fund_call_budget_source_unique');
        });

        Schema::table('residence_documents', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->after('published_by')->constrained('residence_document_versions')->restrictOnDelete();
        });

        Schema::create('residence_document_buildings', function (Blueprint $table) {
            $table->foreignId('residence_document_id');
            $table->foreign('residence_document_id', 'res_doc_building_document_fk')
                ->references('id')->on('residence_documents')->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->primary(['residence_document_id', 'building_id'], 'res_doc_building_pk');
        });

        Schema::create('residence_document_contacts', function (Blueprint $table) {
            $table->foreignId('residence_document_id');
            $table->foreign('residence_document_id', 'res_doc_contact_document_fk')
                ->references('id')->on('residence_documents')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->primary(['residence_document_id', 'contact_id'], 'res_doc_contact_pk');
        });

        Schema::create('residence_announcement_buildings', function (Blueprint $table) {
            $table->foreignId('residence_announcement_id');
            $table->foreign('residence_announcement_id', 'announcement_building_announcement_fk')
                ->references('id')->on('residence_announcements')->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->primary(['residence_announcement_id', 'building_id'], 'announcement_building_pk');
        });

        Schema::create('residence_announcement_contacts', function (Blueprint $table) {
            $table->foreignId('residence_announcement_id');
            $table->foreign('residence_announcement_id', 'announcement_contact_announcement_fk')
                ->references('id')->on('residence_announcements')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->primary(['residence_announcement_id', 'contact_id'], 'announcement_contact_pk');
        });

        DB::table('residence_documents')->where('status', 'published')->orderBy('id')->each(function ($document) {
            $version = DB::table('residence_document_versions')->where('residence_document_id', $document->id)->max('id');
            if ($version) {
                DB::table('residence_documents')->where('id', $document->id)->update(['published_version_id' => $version]);
            }
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX budgets_one_active ON budgets(residence_id, financial_exercise_id) WHERE status IN ('approved', 'locked')");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE budgets ADD active_scope VARCHAR(80) GENERATED ALWAYS AS (CASE WHEN status IN ('approved','locked') THEN CONCAT(residence_id, ':', financial_exercise_id) ELSE NULL END) STORED, ADD UNIQUE INDEX budgets_one_active (active_scope)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS budgets_one_active');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE budgets DROP INDEX budgets_one_active, DROP COLUMN active_scope');
        }

        Schema::dropIfExists('residence_announcement_contacts');
        Schema::dropIfExists('residence_announcement_buildings');
        Schema::dropIfExists('residence_document_contacts');
        Schema::dropIfExists('residence_document_buildings');

        Schema::table('residence_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('published_version_id'));
        Schema::table('fund_calls', function (Blueprint $table) {
            $table->dropUnique('fund_call_budget_source_unique');
            $table->dropConstrainedForeignId('budget_id');
            $table->dropColumn('budget_source_key');
        });
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropConstrainedForeignId('unlocked_by');
            $table->dropColumn(['archived_at', 'unlocked_at', 'unlock_reason']);
        });
        Schema::table('supplier_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renewed_from_id');
            $table->dropColumn('renewal_version');
        });
        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->dropUnique('supplier_credit_idem_unique');
            $table->dropColumn(['idempotency_key', 'validation_snapshot']);
        });

        // SQLite rebuilds the budgets table while dropping columns. Recreate the
        // partial index afterwards so its WHERE clause is preserved.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX budgets_one_approved ON budgets(residence_id, financial_exercise_id) WHERE status = 'approved'");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE budgets ADD approved_scope VARCHAR(80) GENERATED ALWAYS AS (CASE WHEN status = 'approved' THEN CONCAT(residence_id, ':', financial_exercise_id) ELSE NULL END) STORED, ADD UNIQUE INDEX budgets_one_approved (approved_scope)");
        }
    }
};
