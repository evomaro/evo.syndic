<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'year'], 'org_doc_seq_scope_unique');
        });

        Schema::create('supplier_service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'supplier_service_cat_code_unique');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type')->default('company');
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('ice', 15)->nullable();
            $table->string('tax_id')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('professional_tax_number')->nullable();
            $table->string('cin')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('MA');
            $table->string('website')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('rib')->nullable();
            $table->string('iban')->nullable();
            $table->string('preferred_language', 2)->default('fr');
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->boolean('active')->default(true);
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'ice'], 'supplier_org_ice_idx');
            $table->index(['organization_id', 'status', 'legal_name'], 'supplier_org_status_name_idx');
        });

        Schema::create('supplier_service_category', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_service_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['supplier_id', 'supplier_service_category_id'], 'supplier_service_cat_pk');
        });

        Schema::create('supplier_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_service_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->string('billing_frequency')->nullable();
            $table->string('renewal_type')->default('none');
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->boolean('auto_renew')->default(false);
            $table->string('status')->default('active');
            $table->date('terminated_on')->nullable();
            $table->text('termination_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['residence_id', 'reference'], 'supplier_contract_res_ref_unique');
            $table->index(['residence_id', 'status', 'ends_on'], 'supplier_contract_expiry_idx');
        });

        Schema::create('supplier_contract_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->string('type')->default('ordinary');
            $table->string('default_visibility')->default('private');
            $table->string('future_accounting_code')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['residence_id', 'code'], 'expense_cat_res_code_unique');
        });

        Schema::create('expense_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('committed_on');
            $table->date('expected_invoice_date')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['residence_id', 'number'], 'commitment_res_number_unique');
            $table->index(['residence_id', 'status', 'committed_on'], 'commitment_status_date_idx');
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('primary_residence_id')->constrained('residences')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('expense_commitment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number')->nullable();
            $table->string('supplier_invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('received_date')->nullable();
            $table->date('due_date');
            $table->date('service_period_start')->nullable();
            $table->date('service_period_end')->nullable();
            $table->string('currency', 3)->default('MAD');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('credited_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->string('status')->default('draft');
            $table->string('idempotency_key', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('duplicate_warning_acknowledged_at')->nullable();
            $table->foreignId('duplicate_warning_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('duplicate_warning_reason')->nullable();
            $table->json('validation_snapshot')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number'], 'supplier_invoice_org_number_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'supplier_invoice_idem_unique');
            $table->index(['supplier_id', 'status', 'due_date'], 'supplier_invoice_payable_idx');
            $table->index(['primary_residence_id', 'status', 'invoice_date'], 'supplier_invoice_res_status_idx');
            $table->index(['supplier_id', 'supplier_invoice_number'], 'supplier_invoice_external_idx');
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->bigInteger('unit_price_cents');
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->date('service_date')->nullable();
            $table->string('visibility')->default('private');
            $table->json('immutable_snapshot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['residence_id', 'financial_exercise_id', 'expense_category_id'], 'invoice_line_budget_idx');
        });

        Schema::create('supplier_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('supporting');
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->boolean('immutable')->default(false);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_supplier_invoice_id')->nullable()->constrained('supplier_invoices')->restrictOnDelete();
            $table->string('number')->nullable();
            $table->string('supplier_credit_number')->nullable();
            $table->date('credit_date');
            $table->bigInteger('amount_cents');
            $table->string('status')->default('draft');
            $table->text('reason')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number'], 'supplier_credit_org_number_unique');
            $table->index(['supplier_id', 'status', 'credit_date'], 'supplier_credit_status_idx');
        });

        Schema::create('supplier_credit_note_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_credit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_line_id')->nullable();
            $table->foreign('supplier_invoice_line_id', 'credit_alloc_invoice_line_fk')
                ->references('id')->on('supplier_invoice_lines')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_invoice_id', 'reversed_at'], 'credit_alloc_invoice_idx');
        });

        Schema::create('supplier_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->string('number')->nullable();
            $table->date('settlement_date');
            $table->bigInteger('amount_cents');
            $table->string('method');
            $table->string('bank_reference')->nullable();
            $table->string('cheque_number')->nullable();
            $table->string('status')->default('draft');
            $table->string('idempotency_key', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['residence_id', 'number'], 'supplier_settlement_res_number_unique');
            $table->unique(['residence_id', 'idempotency_key'], 'supplier_settlement_idem_unique');
            $table->index(['supplier_id', 'status', 'settlement_date'], 'supplier_settlement_status_idx');
            $table->index(['financial_account_id', 'settlement_date'], 'supplier_settlement_account_idx');
        });

        Schema::create('supplier_settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->unsignedInteger('allocation_order');
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->date('allocated_on');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['supplier_invoice_id', 'reversed_at'], 'settlement_alloc_invoice_idx');
        });

        Schema::table('financial_account_movements', function (Blueprint $table) {
            $table->string('operational_kind', 40)->nullable()->change();
            $table->foreignId('supplier_settlement_id')->nullable()->after('payment_id')->constrained()->restrictOnDelete();
            $table->unique(['supplier_settlement_id', 'operational_kind'], 'fin_move_settlement_kind_unique');
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->string('title');
            $table->bigInteger('total_budget_cents')->default(0);
            $table->string('approval_reference')->nullable();
            $table->text('revision_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('budgets')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['residence_id', 'financial_exercise_id', 'version'], 'budget_res_ex_version_unique');
            $table->index(['residence_id', 'status'], 'budget_res_status_idx');
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->bigInteger('planned_cents');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['budget_id', 'expense_category_id'], 'budget_line_category_unique');
        });

        Schema::table('supplier_invoice_lines', function (Blueprint $table) {
            $table->foreignId('budget_line_id')->nullable()->after('expense_category_id')->constrained()->restrictOnDelete();
        });

        Schema::create('residence_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('status')->default('draft');
            $table->string('audience')->default('staff');
            $table->date('document_date')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['residence_id', 'status', 'category'], 'res_doc_status_cat_idx');
            $table->index(['residence_id', 'audience', 'published_at'], 'res_doc_audience_publish_idx');
        });

        Schema::create('residence_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['residence_document_id', 'version'], 'res_doc_version_unique');
        });

        Schema::create('residence_document_lots', function (Blueprint $table) {
            $table->foreignId('residence_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->primary(['residence_document_id', 'lot_id'], 'res_doc_lot_pk');
        });

        Schema::create('residence_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('title_fr')->nullable();
            $table->string('title_ar')->nullable();
            $table->text('body_fr')->nullable();
            $table->text('body_ar')->nullable();
            $table->string('priority')->default('normal');
            $table->string('status')->default('draft');
            $table->string('audience')->default('all_residents');
            $table->json('audience_snapshot')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['residence_id', 'status', 'scheduled_for'], 'announcement_publish_idx');
        });

        Schema::create('residence_announcement_lots', function (Blueprint $table) {
            $table->foreignId('residence_announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->primary(['residence_announcement_id', 'lot_id'], 'announcement_lot_pk');
        });

        Schema::create('announcement_document', function (Blueprint $table) {
            $table->foreignId('residence_announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('residence_document_id')->constrained()->cascadeOnDelete();
            $table->primary(['residence_announcement_id', 'residence_document_id'], 'announcement_doc_pk');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->boolean('database_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->json('muted_events')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'organization_id'], 'notification_pref_scope_unique');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_recipient_read_idx');
        });

        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 100);
            $table->string('channel', 20);
            $table->timestamp('dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_key', 'channel'], 'notification_dispatch_unique');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX budgets_one_approved ON budgets(residence_id, financial_exercise_id) WHERE status = 'approved'");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE budgets ADD approved_scope VARCHAR(80) GENERATED ALWAYS AS (CASE WHEN status = 'approved' THEN CONCAT(residence_id, ':', financial_exercise_id) ELSE NULL END) STORED, ADD UNIQUE INDEX budgets_one_approved (approved_scope)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS budgets_one_approved');
        }
        DB::table('financial_account_movements')->whereNotNull('supplier_settlement_id')->delete();
        Schema::table('financial_account_movements', function (Blueprint $table) {
            $table->dropUnique('fin_move_settlement_kind_unique');
            $table->dropConstrainedForeignId('supplier_settlement_id');
            $table->string('operational_kind', 24)->nullable()->change();
        });
        Schema::disableForeignKeyConstraints();
        foreach (['notification_dispatches', 'notifications', 'notification_preferences', 'announcement_document', 'residence_announcement_lots', 'residence_announcements', 'residence_document_lots', 'residence_document_versions', 'residence_documents', 'budget_lines', 'budgets', 'supplier_settlement_allocations', 'supplier_settlements', 'supplier_credit_note_allocations', 'supplier_credit_notes', 'supplier_invoice_attachments', 'supplier_invoice_lines', 'supplier_invoices', 'expense_commitments', 'expense_categories', 'supplier_contract_attachments', 'supplier_contracts', 'supplier_service_category', 'suppliers', 'supplier_service_categories', 'organization_document_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
