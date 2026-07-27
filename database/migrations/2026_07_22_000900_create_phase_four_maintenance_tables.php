<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name_fr');
            $table->string('name_ar');
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('default_priority', 16)->default('normal');
            $table->unsignedInteger('ack_target_minutes')->default(1440);
            $table->unsignedInteger('schedule_target_minutes')->default(2880);
            $table->unsignedInteger('resolution_target_minutes')->default(10080);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'name_fr'], 'maint_cat_org_name_fr_uq');
            $table->index(['organization_id', 'active', 'sort_order'], 'maint_cat_org_active_idx');
        });

        Schema::create('maintenance_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('location')->nullable();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('installed_on')->nullable();
            $table->date('warranty_expires_on')->nullable();
            $table->string('condition', 24)->default('good');
            $table->string('status', 16)->default('active');
            $table->timestamp('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->text('public_description')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'serial_number'], 'maint_equip_org_serial_uq');
            $table->index(['residence_id', 'status', 'maintenance_category_id'], 'maint_equip_res_status_idx');
        });

        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('maintenance_equipment')->restrictOnDelete();
            $table->foreignId('maintenance_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reporter_role', 32);
            $table->string('reference');
            $table->string('title');
            $table->text('description');
            $table->string('location')->nullable();
            $table->string('priority', 16);
            $table->date('observed_on')->nullable();
            $table->string('contact_method', 20)->nullable();
            $table->string('contact_details')->nullable();
            $table->boolean('contact_visible_to_assignees')->default(false);
            $table->string('status', 24)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ack_deadline_at')->nullable();
            $table->timestamp('schedule_deadline_at')->nullable();
            $table->timestamp('resolution_deadline_at')->nullable();
            $table->json('sla_snapshot');
            $table->text('resolution_summary')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('closure_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('reopen_deadline_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'reference'], 'maint_req_org_ref_uq');
            $table->index(['residence_id', 'status', 'priority'], 'maint_req_res_status_idx');
            $table->index(['organization_id', 'ack_deadline_at'], 'maint_req_ack_due_idx');
            $table->index(['organization_id', 'resolution_deadline_at'], 'maint_req_resolve_due_idx');
        });

        Schema::create('maintenance_request_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 64);
            $table->timestamp('transitioned_at');
            $table->timestamps();
            $table->unique(['maintenance_request_id', 'idempotency_key'], 'maint_req_transition_idem_uq');
            $table->index(['maintenance_request_id', 'transitioned_at'], 'maint_req_transition_time_idx');
        });

        Schema::create('maintenance_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 24)->default('responsible');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['maintenance_request_id', 'ended_at'], 'maint_assign_active_idx');
        });

        Schema::create('maintenance_request_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('visibility', 20)->default('internal');
            $table->text('body');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['maintenance_request_id', 'visibility', 'created_at'], 'maint_update_visibility_idx');
        });

        Schema::create('preventive_maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('maintenance_equipment')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('frequency_type', 20);
            $table->unsignedInteger('frequency_interval')->default(1);
            $table->date('starts_on');
            $table->date('next_intervention_on');
            $table->unsignedInteger('reminder_days')->default(7);
            $table->json('checklist');
            $table->boolean('active')->default(true);
            $table->date('last_generated_on')->nullable();
            $table->timestamps();
            $table->index(['residence_id', 'active', 'next_intervention_on'], 'prevent_plan_due_idx');
        });

        Schema::create('preventive_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('preventive_maintenance_plan_id')->constrained()->restrictOnDelete();
            $table->date('due_on');
            $table->string('occurrence_key', 100);
            $table->string('status', 24)->default('due');
            $table->json('schedule_snapshot');
            $table->json('checklist_snapshot');
            $table->json('completion_result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['preventive_maintenance_plan_id', 'occurrence_key'], 'prevent_occurrence_uq');
            $table->index(['residence_id', 'status', 'due_on'], 'prevent_intervention_due_idx');
        });

        Schema::create('maintenance_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('supplier_reference')->nullable();
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->date('submitted_on');
            $table->date('valid_until')->nullable();
            $table->string('status', 16)->default('received');
            $table->text('internal_notes')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('end_reason')->nullable();
            $table->timestamps();
            $table->index(['maintenance_request_id', 'status'], 'maint_quote_req_status_idx');
        });

        Schema::create('maintenance_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('preventive_intervention_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('maintenance_equipment')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('accepted_quotation_id')->nullable()->constrained('maintenance_quotations')->restrictOnDelete();
            $table->foreignId('supplier_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference');
            $table->boolean('is_primary')->default(true);
            $table->text('scope_of_work');
            $table->text('internal_instructions')->nullable();
            $table->text('resident_notes')->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->bigInteger('estimated_cost_cents')->nullable();
            $table->bigInteger('actual_cost_cents')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('completion_report')->nullable();
            $table->text('validation_report')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reference'], 'maint_wo_org_ref_uq');
            $table->index(['residence_id', 'status', 'planned_start_at'], 'maint_wo_res_status_idx');
        });

        Schema::create('maintenance_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('attachable_type', 80);
            $table->unsignedBigInteger('attachable_id');
            $table->string('kind', 32);
            $table->string('visibility', 20)->default('internal');
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->string('disk', 24)->default('local');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id'], 'maint_attachment_entity_idx');
            $table->index(['organization_id', 'residence_id', 'visibility'], 'maint_attachment_scope_idx');
        });

        Schema::create('maintenance_sla_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->string('threshold', 20);
            $table->timestamp('deadline_at');
            $table->timestamp('exceeded_at');
            $table->string('deadline_cycle', 64);
            $table->timestamps();
            $table->unique(['maintenance_request_id', 'threshold', 'deadline_cycle'], 'maint_sla_event_uq');
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->foreignId('maintenance_work_order_id')->nullable()->after('expense_commitment_id')->constrained('maintenance_work_orders')->restrictOnDelete();
            $table->text('maintenance_amount_justification')->nullable()->after('notes');
            $table->unique('maintenance_work_order_id', 'supplier_invoice_work_order_uq');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX maint_quote_one_accepted ON maintenance_quotations(maintenance_request_id) WHERE status = 'accepted'");
            DB::statement("CREATE UNIQUE INDEX maint_wo_primary_request ON maintenance_work_orders(maintenance_request_id) WHERE is_primary = 1 AND status != 'cancelled' AND maintenance_request_id IS NOT NULL");
            DB::statement("CREATE UNIQUE INDEX maint_wo_primary_prevent ON maintenance_work_orders(preventive_intervention_id) WHERE is_primary = 1 AND status != 'cancelled' AND preventive_intervention_id IS NOT NULL");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE maintenance_quotations ADD accepted_scope BIGINT GENERATED ALWAYS AS (CASE WHEN status = 'accepted' THEN maintenance_request_id ELSE NULL END) STORED, ADD UNIQUE INDEX maint_quote_one_accepted (accepted_scope)");
            DB::statement("ALTER TABLE maintenance_work_orders ADD active_request_scope BIGINT GENERATED ALWAYS AS (CASE WHEN is_primary = 1 AND status <> 'cancelled' THEN maintenance_request_id ELSE NULL END) STORED, ADD active_prevent_scope BIGINT GENERATED ALWAYS AS (CASE WHEN is_primary = 1 AND status <> 'cancelled' THEN preventive_intervention_id ELSE NULL END) STORED, ADD UNIQUE INDEX maint_wo_primary_request (active_request_scope), ADD UNIQUE INDEX maint_wo_primary_prevent (active_prevent_scope)");
        }
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropForeign(['maintenance_work_order_id']);
            $table->dropUnique('supplier_invoice_work_order_uq');
            $table->dropColumn(['maintenance_work_order_id', 'maintenance_amount_justification']);
        });

        Schema::disableForeignKeyConstraints();
        foreach (['maintenance_sla_events', 'maintenance_attachments', 'maintenance_work_orders', 'maintenance_quotations', 'preventive_interventions', 'preventive_maintenance_plans', 'maintenance_request_updates', 'maintenance_assignments', 'maintenance_request_transitions', 'maintenance_requests', 'maintenance_equipment', 'maintenance_categories'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
