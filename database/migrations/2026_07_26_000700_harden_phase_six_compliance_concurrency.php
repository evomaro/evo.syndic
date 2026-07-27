<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_template_versions', function (Blueprint $table) {
            $table->text('withdrawal_reason')->nullable()->after('activated_at');
            $table->foreignId('withdrawn_by')->nullable()->after('withdrawal_reason')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by');
        });

        Schema::table('compliance_applicability_decisions', function (Blueprint $table) {
            $table->foreignId('supersedes_id')->nullable()->after('manual_override');
            $table->foreignId('superseded_by_id')->nullable()->after('supersedes_id');
            $table->foreign('supersedes_id', 'cmp_decision_supersedes_fk')
                ->references('id')->on('compliance_applicability_decisions')->restrictOnDelete();
            $table->foreign('superseded_by_id', 'cmp_decision_successor_fk')
                ->references('id')->on('compliance_applicability_decisions')->restrictOnDelete();
            $table->index(['template_version_id', 'superseded_by_id'], 'cmp_decision_current_idx');
        });

        Schema::table('compliance_reminder_occurrences', function (Blueprint $table) {
            $table->date('triggered_for_on')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_reminder_occurrences', function (Blueprint $table) {
            $table->dropColumn('triggered_for_on');
        });

        Schema::table('compliance_applicability_decisions', function (Blueprint $table) {
            $table->dropForeign('cmp_decision_supersedes_fk');
            $table->dropForeign('cmp_decision_successor_fk');
        });
        Schema::table('compliance_applicability_decisions', function (Blueprint $table) {
            $table->index(
                'template_version_id',
                'compliance_applicability_decisions_template_version_id_foreign'
            );
        });
        Schema::table('compliance_applicability_decisions', function (Blueprint $table) {
            $table->dropIndex('cmp_decision_current_idx');
            $table->dropColumn(['supersedes_id', 'superseded_by_id']);
        });

        Schema::table('compliance_template_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn(['withdrawal_reason', 'withdrawn_at']);
        });
    }
};
