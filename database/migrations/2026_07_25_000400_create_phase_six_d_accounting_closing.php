<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_closing_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')
                ->constrained('accounting_books', indexName: 'acct_close_cfg_book_fk')->restrictOnDelete();
            $table->string('version', 40);
            $table->string('status', 24)->default('draft');
            $table->string('currency', 3)->default('MAD');
            $table->foreignId('closing_journal_id')->nullable()
                ->constrained('accounting_journals', indexName: 'acct_close_cfg_closing_journal_fk')->restrictOnDelete();
            $table->foreignId('opening_journal_id')->nullable()
                ->constrained('accounting_journals', indexName: 'acct_close_cfg_opening_journal_fk')->restrictOnDelete();
            $table->foreignId('result_transfer_account_id')->nullable()
                ->constrained('ledger_accounts', indexName: 'acct_close_cfg_result_account_fk')->restrictOnDelete();
            $table->string('professional_review_status', 40)->default('pending_professional_review');
            $table->string('counsel_review_status', 40)->default('pending_counsel_review');
            $table->date('effective_from');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'version'], 'acct_close_cfg_book_ver_uq');
            $table->index(['organization_id', 'residence_id', 'status'], 'acct_close_cfg_tenant_status_idx');
            $table->foreign('superseded_by_id', 'acct_close_cfg_successor_fk')
                ->references('id')->on('accounting_closing_configurations')->restrictOnDelete();
        });

        Schema::create('accounting_closing_account_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_closing_configuration_id')
                ->constrained('accounting_closing_configurations', indexName: 'acct_close_class_cfg_fk')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')
                ->constrained('ledger_accounts', indexName: 'acct_close_class_account_fk')->restrictOnDelete();
            $table->string('closing_role', 32);
            $table->boolean('carry_forward_eligible')->default(false);
            $table->boolean('requires_third_party_dimensions')->default(false);
            $table->boolean('requires_analytical_dimensions')->default(false);
            $table->string('review_status', 40)->default('pending_professional_review');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['accounting_closing_configuration_id', 'ledger_account_id'],
                'acct_close_class_cfg_account_uq'
            );
            $table->index(['ledger_account_id', 'closing_role'], 'acct_close_class_account_role_idx');
        });

        Schema::create('accounting_closing_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')
                ->constrained('accounting_books', indexName: 'acct_close_pkg_book_fk')->restrictOnDelete();
            $table->foreignId('financial_exercise_id')
                ->constrained('financial_exercises', indexName: 'acct_close_pkg_exercise_fk')->restrictOnDelete();
            $table->foreignId('accounting_closing_configuration_id')->nullable()
                ->constrained('accounting_closing_configurations', indexName: 'acct_close_pkg_cfg_fk')->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->string('state', 32)->default('draft');
            $table->string('currency', 3)->default('MAD');
            $table->unsignedBigInteger('snapshot_entry_id')->default(0);
            $table->json('snapshot_data');
            $table->json('readiness_results');
            $table->json('trial_balance_totals');
            $table->char('integrity_fingerprint', 64);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->string('stale_reason_code', 80)->nullable();
            $table->foreignId('closing_entry_id')->nullable()
                ->constrained('journal_entries', indexName: 'acct_close_pkg_entry_fk')->restrictOnDelete();
            $table->foreignId('carry_forward_batch_id')->nullable()
                ->constrained('accounting_opening_batches', indexName: 'acct_close_pkg_carry_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'financial_exercise_id', 'generation'], 'acct_close_pkg_book_year_gen_uq');
            $table->unique('closing_entry_id', 'acct_close_pkg_entry_uq');
            $table->unique('carry_forward_batch_id', 'acct_close_pkg_carry_uq');
            $table->index(['organization_id', 'residence_id', 'state'], 'acct_close_pkg_tenant_state_idx');
            $table->foreign('supersedes_id', 'acct_close_pkg_successor_fk')
                ->references('id')->on('accounting_closing_packages')->restrictOnDelete();
        });

        Schema::create('accounting_closing_period_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_closing_package_id')
                ->constrained('accounting_closing_packages', indexName: 'acct_close_period_pkg_fk')->cascadeOnDelete();
            $table->foreignId('accounting_period_id')
                ->constrained('accounting_periods', indexName: 'acct_close_period_period_fk')->restrictOnDelete();
            $table->string('status_before', 20);
            $table->unsignedBigInteger('snapshot_entry_id')->default(0);
            $table->char('snapshot_fingerprint', 64);
            $table->json('readiness_results');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['accounting_closing_package_id', 'accounting_period_id'],
                'acct_close_period_pkg_period_uq'
            );
        });

        Schema::create('accounting_closing_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_closing_package_id')
                ->constrained('accounting_closing_packages', indexName: 'acct_close_transition_pkg_fk')->restrictOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('action', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(
                ['accounting_closing_package_id', 'occurred_at'],
                'acct_close_transition_package_time_idx'
            );
        });

        Schema::table('accounting_opening_batches', function (Blueprint $table) {
            $table->string('origin_type', 24)->default('manual')->after('status');
            $table->foreignId('closing_package_id')->nullable()->after('origin_type')
                ->constrained('accounting_closing_packages', indexName: 'acct_open_closing_pkg_fk')->restrictOnDelete();
            $table->unique('closing_package_id', 'acct_open_closing_pkg_uq');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_opening_batches', function (Blueprint $table) {
            $table->dropForeign('acct_open_closing_pkg_fk');
            $table->dropUnique('acct_open_closing_pkg_uq');
            $table->dropColumn(['origin_type', 'closing_package_id']);
        });
        Schema::dropIfExists('accounting_closing_transitions');
        Schema::dropIfExists('accounting_closing_period_snapshots');
        Schema::dropIfExists('accounting_closing_packages');
        Schema::dropIfExists('accounting_closing_account_classifications');
        Schema::dropIfExists('accounting_closing_configurations');
    }
};
