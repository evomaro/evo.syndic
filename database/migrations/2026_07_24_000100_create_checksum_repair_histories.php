<?php

use App\Services\FinancialDocumentChecksumService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_documents', function (Blueprint $table) {
            $table->string('checksum_version', 64)
                ->default(FinancialDocumentChecksumService::VERSION)
                ->after('checksum');
        });

        Schema::create('checksum_repair_histories', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 80);
            $table->unsignedBigInteger('record_id');
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('old_checksum', 64);
            $table->string('observed_checksum', 64)->nullable();
            $table->string('new_checksum', 64);
            $table->string('old_checksum_version', 64);
            $table->string('new_checksum_version', 64);
            $table->string('classification', 64);
            $table->string('canonical_payload_fingerprint', 64);
            $table->json('evidence_summary');
            $table->string('repair_key', 64)->unique('checksum_repair_key_uq');
            $table->uuid('command_execution_id');
            $table->string('actor_identity', 120);
            $table->timestamp('created_at');

            $table->index(['record_type', 'record_id'], 'checksum_repair_record_idx');
            $table->index(['organization_id', 'residence_id'], 'checksum_repair_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checksum_repair_histories');

        Schema::table('financial_documents', function (Blueprint $table) {
            $table->dropColumn('checksum_version');
        });
    }
};
