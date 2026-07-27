<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allocation_keys', function (Blueprint $table) {
            $table->boolean('applies_to_all_lots')->default(true)->after('type');
            $table->unsignedTinyInteger('default_slot')->nullable()->after('is_default');
            $table->unique(['residence_id', 'default_slot'], 'allocation_one_default_per_residence');
        });
        DB::table('allocation_keys')->where('is_default', true)->update(['default_slot' => 1]);

        Schema::create('allocation_key_lot', function (Blueprint $table) {
            $table->foreignId('allocation_key_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->primary(['allocation_key_id', 'lot_id']);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->string('preferred_language', 2)->default('fr')->after('role');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('mime_type')->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->string('file_hash', 64)->nullable()->after('file_size');
            $table->unsignedInteger('total_rows')->default(0)->after('status');
            $table->unsignedInteger('processed_rows')->default(0)->after('total_rows');
            $table->unsignedInteger('created_rows')->default(0)->after('processed_rows');
            $table->unsignedInteger('updated_rows')->default(0)->after('created_rows');
            $table->unsignedInteger('skipped_rows')->default(0)->after('updated_rows');
            $table->unsignedInteger('failed_rows')->default(0)->after('skipped_rows');
            $table->dateTime('processing_started_at')->nullable()->after('report');
            $table->index(['organization_id', 'file_hash'], 'import_org_file_hash_idx');
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status');
            $table->string('action')->nullable();
            $table->nullableMorphs('subject', 'import_row_subject');
            $table->json('source_values')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'row_number'], 'import_batch_row_unique');
            $table->index(['import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex('import_org_file_hash_idx');
            $table->dropColumn(['mime_type', 'file_size', 'file_hash', 'total_rows', 'processed_rows', 'created_rows', 'updated_rows', 'skipped_rows', 'failed_rows', 'processing_started_at']);
        });
        Schema::table('team_invitations', fn (Blueprint $table) => $table->dropColumn('preferred_language'));
        Schema::dropIfExists('allocation_key_lot');
        Schema::table('allocation_keys', function (Blueprint $table) {
            $table->dropUnique('allocation_one_default_per_residence');
            $table->dropColumn(['applies_to_all_lots', 'default_slot']);
        });
    }
};
