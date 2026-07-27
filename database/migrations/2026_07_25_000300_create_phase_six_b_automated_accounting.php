<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->date('effective_from');
            $table->string('status', 24)->default('active');
            $table->string('rule_set_version', 64);
            $table->string('readiness_result', 24);
            $table->json('readiness_evidence');
            $table->string('professional_review_status', 40)->default('pending_professional_review');
            $table->foreignId('activated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('activated_at');
            $table->date('deactivated_from')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->timestamps();
            $table->unique('accounting_book_id', 'acct_auto_book_uq');
            $table->index(['organization_id', 'residence_id', 'status'], 'acct_auto_tenant_status_idx');
        });

        Schema::create('accounting_posting_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('accounting_framework_id')->constrained('accounting_frameworks')->restrictOnDelete();
            $table->string('stable_code', 80);
            $table->string('version', 40);
            $table->string('source_domain', 40);
            $table->string('source_event', 60);
            $table->foreignId('accounting_journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $table->string('debit_resolution', 40);
            $table->foreignId('debit_ledger_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('credit_resolution', 40);
            $table->foreignId('credit_ledger_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->json('conditions')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('professional_review_status', 40)->default('pending_professional_review');
            $table->text('source_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('activated_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable();
            $table->foreignId('superseded_by_actor')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'stable_code', 'version'], 'acct_rule_book_code_ver_uq');
            $table->index(['accounting_book_id', 'source_domain', 'source_event', 'status'], 'acct_rule_source_status_idx');
            $table->foreign('superseded_by_id', 'acct_rule_successor_fk')->references('id')->on('accounting_posting_rules')->restrictOnDelete();
        });

        Schema::create('accounting_source_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->string('mapping_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('review_status', 40)->default('pending_professional_review');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'mapping_type', 'source_id', 'effective_from'], 'acct_map_book_type_source_date_uq');
            $table->index(['organization_id', 'residence_id', 'mapping_type'], 'acct_map_tenant_type_idx');
            $table->foreign('superseded_by_id', 'acct_map_successor_fk')->references('id')->on('accounting_source_mappings')->restrictOnDelete();
        });

        Schema::create('accounting_source_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->string('source_event', 60);
            $table->string('source_version', 64);
            $table->string('posting_key', 64);
            $table->foreignId('accounting_posting_rule_id')->constrained('accounting_posting_rules')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('failure_classification', 60)->nullable();
            $table->json('failure_details')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('context', 20)->default('http');
            $table->timestamps();
            $table->unique(['accounting_book_id', 'posting_key'], 'acct_source_posting_key_uq');
            $table->unique(
                ['accounting_book_id', 'source_type', 'source_id', 'source_event', 'source_version'],
                'acct_source_event_version_uq'
            );
            $table->index(['organization_id', 'residence_id', 'status'], 'acct_source_tenant_status_idx');
            $table->index(['source_type', 'source_id', 'source_event'], 'acct_source_lookup_idx');
        });

        Schema::create('accounting_opening_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained('financial_exercises')->restrictOnDelete();
            $table->foreignId('accounting_journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $table->date('opening_date');
            $table->string('reference', 80);
            $table->text('notes')->nullable();
            $table->string('supporting_document_reference')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'financial_exercise_id'], 'acct_open_book_exercise_uq');
            $table->index(['organization_id', 'residence_id', 'status'], 'acct_open_tenant_status_idx');
        });

        Schema::create('accounting_opening_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_opening_batch_id')->constrained('accounting_opening_batches')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('label');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->timestamps();
            $table->unique(['accounting_opening_batch_id', 'sequence'], 'acct_open_line_sequence_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_opening_lines');
        Schema::dropIfExists('accounting_opening_batches');
        Schema::dropIfExists('accounting_source_postings');
        Schema::dropIfExists('accounting_source_mappings');
        Schema::dropIfExists('accounting_posting_rules');
        Schema::dropIfExists('accounting_automations');
    }
};
