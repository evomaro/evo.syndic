<?php

use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX financial_exercises_one_open ON financial_exercises(residence_id) WHERE status = 'open'");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE financial_exercises ADD open_residence_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN residence_id ELSE NULL END) STORED, ADD UNIQUE INDEX financial_exercises_one_open (open_residence_id)");
        }

        Schema::table('lot_charges', function (Blueprint $table) {
            $table->json('validation_snapshot')->nullable()->after('distribution_total_snapshot');
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->bigInteger('restored_charge_cents')->nullable()->after('reversal_reason');
        });

        Schema::table('financial_account_movements', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->string('operational_kind', 24)->nullable()->after('direction');
            $table->unique(['payment_id', 'operational_kind'], 'fin_move_payment_kind_unique');
        });

        DB::table('financial_account_movements')->where('source_type', Payment::class)->orderBy('id')->each(function ($movement) {
            DB::table('financial_account_movements')->where('id', $movement->id)->update([
                'payment_id' => $movement->source_id,
                'operational_kind' => $movement->reversal_of_id ? 'payment_reversal' : 'payment_receipt',
            ]);
        });

        Schema::table('financial_documents', function (Blueprint $table) {
            $table->unique(['subject_type', 'subject_id', 'type', 'version'], 'fin_doc_subject_version_unique');
        });

        Schema::table('fund_call_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('custom_interval_months')->nullable()->after('frequency');
            $table->dateTime('last_failed_at')->nullable()->after('last_generated_at');
            $table->text('last_error')->nullable()->after('last_failed_at');
        });

        Schema::table('schedule_generations', function (Blueprint $table) {
            $table->json('template_snapshot')->nullable()->after('fund_call_id');
        });

        Schema::table('contact_user', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('linked_by')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
            $table->dateTime('linked_at')->nullable()->after('linked_by');
            $table->dateTime('revoked_at')->nullable()->after('linked_at');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'user_id', 'revoked_at'], 'contact_user_access_idx');
        });

        DB::table('contact_user')->orderBy('contact_id')->each(function ($link) {
            $organizationId = DB::table('contacts')->where('id', $link->contact_id)->value('organization_id');
            DB::table('contact_user')->where('contact_id', $link->contact_id)->where('user_id', $link->user_id)->update([
                'organization_id' => $organizationId,
                'linked_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS financial_exercises_one_open');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE financial_exercises DROP INDEX financial_exercises_one_open, DROP COLUMN open_residence_id');
        }

        Schema::table('contact_user', function (Blueprint $table) {
            $table->dropIndex('contact_user_access_idx');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['revoked_at', 'linked_at']);
            $table->dropConstrainedForeignId('linked_by');
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('schedule_generations', fn (Blueprint $table) => $table->dropColumn('template_snapshot'));
        Schema::table('fund_call_schedules', fn (Blueprint $table) => $table->dropColumn(['custom_interval_months', 'last_failed_at', 'last_error']));
        Schema::table('financial_documents', fn (Blueprint $table) => $table->dropUnique('fin_doc_subject_version_unique'));
        Schema::table('financial_account_movements', function (Blueprint $table) {
            $table->dropUnique('fin_move_payment_kind_unique');
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn('operational_kind');
        });
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropColumn(['reversal_reason', 'restored_charge_cents']));
        Schema::table('lot_charges', fn (Blueprint $table) => $table->dropColumn('validation_snapshot'));
    }
};
