<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_language', 2)->default('fr');
            $table->foreignId('current_organization_id')->nullable()->index();
            $table->foreignId('current_residence_id')->nullable()->index();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type');
            $table->string('legal_name')->nullable();
            $table->string('ice')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->string('rc')->nullable();
            $table->string('professional_tax_number')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('MA');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_language', 2)->default('fr');
            $table->string('timezone')->default('Africa/Casablanca');
            $table->string('currency', 3)->default('MAD');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role')->default('manager');
            $table->boolean('all_residences')->default(true);
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'organization_id', 'role']);
        });

        Schema::create('residences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city');
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->default('MA');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('default_language', 2)->default('fr');
            $table->string('timezone')->default('Africa/Casablanca');
            $table->string('currency', 3)->default('MAD');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->unsignedTinyInteger('fiscal_year_start_day')->default(1);
            $table->string('property_registration_reference')->nullable();
            $table->date('syndic_mandate_start')->nullable();
            $table->date('syndic_mandate_end')->nullable();
            $table->string('status')->default('setup');
            $table->boolean('ownership_incomplete_acknowledged')->default(false);
            $table->boolean('allocations_deferred')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_residence_id')->references('id')->on('residences')->nullOnDelete();
        });

        Schema::create('residence_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['residence_id', 'user_id']);
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['residence_id', 'code']);
        });
        Schema::create('entrances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['building_id', 'code']);
        });
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('entrance_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->integer('level_number')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('entrance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference');
            $table->string('lot_number');
            $table->string('type');
            $table->string('title')->nullable();
            $table->decimal('surface', 12, 2)->nullable();
            $table->string('property_title_number')->nullable();
            $table->string('occupancy_status')->default('vacant');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['residence_id', 'reference']);
            $table->index(['residence_id', 'building_id', 'type', 'occupancy_status', 'active']);
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('cin')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('ice')->nullable();
            $table->string('primary_email')->nullable();
            $table->string('primary_phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('whatsapp_phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('MA');
            $table->string('preferred_language', 2)->default('fr');
            $table->string('notification_channel')->default('none');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'active']);
            $table->index(['organization_id', 'phone_normalized']);
            $table->index(['organization_id', 'primary_email']);
            $table->index(['organization_id', 'cin']);
        });
        Schema::create('lot_ownerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->decimal('ownership_percentage', 7, 4);
            $table->boolean('is_primary_contact')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['lot_id', 'starts_on', 'ends_on']);
        });
        Schema::create('lot_occupancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->boolean('is_primary_occupant')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['lot_id', 'starts_on', 'ends_on']);
        });
        Schema::create('allocation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('type')->default('general');
            $table->text('description')->nullable();
            $table->decimal('expected_total', 15, 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['residence_id', 'code']);
            $table->index(['residence_id', 'is_default']);
        });
        Schema::create('lot_allocation_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_key_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->decimal('value', 15, 4)->default(0);
            $table->timestamps();
            $table->unique(['allocation_key_id', 'lot_id']);
        });
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('status')->default('uploaded');
            $table->json('column_mapping')->nullable();
            $table->json('report')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'residence_id', 'status']);
        });
        Schema::create('onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('residence_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('current_step')->default('organization');
            $table->json('completed_steps')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'organization_id', 'residence_id'], 'onboarding_context_unique');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->string('event')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_residence_id']);
            $table->dropForeign(['current_organization_id']);
        });
        foreach (['media', 'activity_log', 'onboarding_progress', 'import_batches', 'team_invitations', 'lot_allocation_values', 'allocation_keys', 'lot_occupancies', 'lot_ownerships', 'contacts', 'lots', 'floors', 'entrances', 'buildings', 'residence_user', 'organization_user', 'residences', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['preferred_language', 'current_organization_id', 'current_residence_id']));
    }
};
