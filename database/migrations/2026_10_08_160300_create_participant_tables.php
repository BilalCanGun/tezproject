<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Research data is keyed by participant code only. Identifying contact data
     * lives in a separate, encrypted table so it can be purged independently.
     */
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_version_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('status', 30)->default('screening');
            $table->string('condition', 30)->nullable();
            $table->string('stratum', 20)->nullable();
            $table->decimal('baseline_score', 6, 2)->nullable();
            $table->decimal('baseline_max', 6, 2)->nullable();
            $table->timestamp('randomized_at')->nullable();
            $table->foreignId('randomized_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('consent_version', 20)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('data_sharing_level', 30)->nullable();

            $table->unsignedTinyInteger('age')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('occupation_type', 20)->nullable();
            $table->string('education_level', 30)->nullable();
            $table->unsignedSmallInteger('programming_experience_months')->nullable();
            $table->string('primary_language', 40)->nullable();
            $table->string('vision_correction', 20)->nullable();
            $table->string('handedness', 10)->nullable();
            $table->json('screening')->nullable();

            $table->string('exclusion_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['protocol_version_id', 'status']);
            $table->index(['protocol_version_id', 'condition']);
        });

        Schema::create('participant_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('full_name')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->date('purge_after')->nullable();
            $table->timestamps();
        });

        Schema::create('randomization_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_version_id')->constrained()->cascadeOnDelete();
            $table->string('stratum', 20);
            $table->unsignedSmallInteger('block_number');
            $table->unsignedTinyInteger('block_size');
            $table->unsignedSmallInteger('position');
            $table->string('condition', 30);
            $table->foreignId('participant_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['protocol_version_id', 'stratum', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('randomization_slots');
        Schema::dropIfExists('participant_contacts');
        Schema::dropIfExists('participants');
    }
};
