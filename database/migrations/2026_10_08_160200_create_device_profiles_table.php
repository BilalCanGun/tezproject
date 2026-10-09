<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('modality', 20);
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('channel_count')->nullable();
            $table->json('channel_labels')->nullable();
            $table->json('signals')->nullable();
            $table->decimal('sampling_rate_hz', 8, 2)->nullable();
            $table->string('electrode_type', 30)->nullable();
            $table->string('sync_method', 20);
            $table->boolean('raw_data_access')->default(false);
            $table->boolean('requires_subscription')->default(false);
            $table->text('license_notes')->nullable();
            $table->boolean('specs_verified')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_profiles');
    }
};
