<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft');
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['residence_id', 'status', 'starts_on'], 'fin_ex_res_status_start_idx');
        });

        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('type');
            $table->string('bank_name')->nullable();
            $table->string('rib')->nullable();
            $table->string('iban')->nullable();
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->date('opening_balance_on')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedTinyInteger('default_slot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['residence_id', 'code'], 'fin_acc_res_code_unique');
            $table->unique(['residence_id', 'default_slot'], 'fin_acc_one_default_unique');
            $table->index(['residence_id', 'active', 'type'], 'fin_acc_res_active_type_idx');
        });

        Schema::create('charge_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('type')->default('ordinary');
            $table->string('default_distribution_method')->default('allocation_key');
            $table->foreignId('default_allocation_key_id')->nullable()->constrained('allocation_keys')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['residence_id', 'code'], 'charge_cat_res_code_unique');
            $table->index(['residence_id', 'active', 'type'], 'charge_cat_res_active_type_idx');
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['residence_id', 'kind', 'year'], 'doc_seq_scope_unique');
        });

        Schema::create('fund_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->string('number')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status')->default('draft');
            $table->bigInteger('total_cents')->default(0);
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['residence_id', 'number'], 'fund_call_res_number_unique');
            $table->index(['residence_id', 'status', 'issue_date'], 'fund_call_res_status_issue_idx');
            $table->index(['financial_exercise_id', 'status'], 'fund_call_ex_status_idx');
        });

        Schema::create('fund_call_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_category_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->string('distribution_method');
            $table->foreignId('allocation_key_id')->nullable()->constrained('allocation_keys')->restrictOnDelete();
            $table->string('target_type')->default('all');
            $table->json('target_ids')->nullable();
            $table->bigInteger('amount_cents');
            $table->bigInteger('fixed_amount_cents')->nullable();
            $table->json('manual_allocations')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lot_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('fund_call_id')->constrained()->restrictOnDelete();
            $table->foreignId('fund_call_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('billed_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status')->default('unpaid');
            $table->string('lot_reference_snapshot');
            $table->string('contact_name_snapshot')->nullable();
            $table->string('distribution_method_snapshot');
            $table->decimal('distribution_value_snapshot', 18, 6)->nullable();
            $table->decimal('distribution_total_snapshot', 18, 6)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['residence_id', 'lot_id', 'due_date', 'status'], 'lot_charge_outstanding_idx');
            $table->index(['billed_contact_id', 'issue_date'], 'lot_charge_contact_issue_idx');
        });

        Schema::create('fund_call_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->json('template');
            $table->string('frequency');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('generation_day')->default(1);
            $table->unsignedSmallInteger('due_offset_days')->default(15);
            $table->date('next_generation_on');
            $table->boolean('active')->default(true);
            $table->boolean('auto_validate')->default(false);
            $table->timestamp('last_generated_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['residence_id', 'active', 'next_generation_on'], 'fund_sched_due_idx');
        });

        Schema::create('schedule_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_call_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('generation_date');
            $table->foreignId('fund_call_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['fund_call_schedule_id', 'generation_date'], 'schedule_generation_unique');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->string('number')->nullable();
            $table->foreignId('payer_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->string('received_from')->nullable();
            $table->date('payment_date');
            $table->bigInteger('amount_cents');
            $table->string('method');
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->string('bank_reference')->nullable();
            $table->string('cheque_number')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['residence_id', 'number'], 'payment_res_number_unique');
            $table->unique(['residence_id', 'idempotency_key'], 'payment_idempotency_unique');
            $table->index(['residence_id', 'status', 'payment_date'], 'payment_res_status_date_idx');
            $table->index(['payer_contact_id', 'payment_date'], 'payment_payer_date_idx');
            $table->index(['financial_account_id', 'payment_date'], 'payment_account_date_idx');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_charge_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->unsignedInteger('allocation_order');
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['payment_id', 'reversed_at'], 'pay_alloc_payment_active_idx');
            $table->index(['lot_charge_id', 'reversed_at'], 'pay_alloc_charge_active_idx');
        });

        Schema::create('financial_account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->string('direction');
            $table->bigInteger('amount_cents');
            $table->date('occurred_on');
            $table->nullableMorphs('source', 'fin_move_source');
            $table->string('description');
            $table->foreignId('reversal_of_id')->nullable()->constrained('financial_account_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['financial_account_id', 'occurred_on'], 'fin_move_account_date_idx');
            $table->index(['residence_id', 'financial_exercise_id'], 'fin_move_res_ex_idx');
        });

        Schema::create('financial_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('number');
            $table->nullableMorphs('subject', 'fin_doc_subject');
            $table->string('locale', 2);
            $table->unsignedInteger('version')->default(1);
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('checksum', 64);
            $table->string('verification_token_hash', 64)->unique();
            $table->text('verification_token_encrypted');
            $table->string('status')->default('valid');
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['residence_id', 'number'], 'fin_doc_res_number_unique');
            $table->index(['residence_id', 'type', 'status'], 'fin_doc_res_type_status_idx');
        });

        Schema::create('contact_user', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['contact_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach (['contact_user', 'financial_documents', 'financial_account_movements', 'payment_allocations', 'payments', 'schedule_generations', 'fund_call_schedules', 'lot_charges', 'fund_call_lines', 'fund_calls', 'document_sequences', 'charge_categories', 'financial_accounts', 'financial_exercises'] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
