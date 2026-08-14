<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('experience_mode', 16)->default('pro')->after('type')->index();
        });

        Schema::create('financial_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('destination_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->date('transferred_on');
            $table->bigInteger('amount_cents');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['residence_id', 'idempotency_key'], 'financial_transfer_idem_unique');
            $table->index(['residence_id', 'transferred_on']);
        });

        Schema::table('financial_account_movements', function (Blueprint $table) {
            $table->foreignId('financial_transfer_id')->nullable()->after('supplier_settlement_id')->constrained('financial_transfers')->restrictOnDelete();
            $table->unique(['financial_transfer_id', 'financial_account_id'], 'fin_move_transfer_account_unique');
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_movements', function (Blueprint $table) {
            $table->dropUnique('fin_move_transfer_account_unique');
            $table->dropConstrainedForeignId('financial_transfer_id');
        });
        Schema::dropIfExists('financial_transfers');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('experience_mode'));
    }
};
