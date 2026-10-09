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
        Schema::create('session_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_profile_id')->constrained()->restrictOnDelete();
            $table->string('serial_number')->nullable();
            $table->string('lsl_stream_name')->nullable();
            $table->json('checks')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['experiment_session_id', 'device_profile_id']);
        });

        Schema::create('quality_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('eeg_usable_epoch_ratio', 5, 4)->nullable();
            $table->decimal('gaze_valid_ratio', 5, 4)->nullable();
            $table->decimal('wearable_loss_ratio', 5, 4)->nullable();
            $table->unsignedInteger('markers_expected')->nullable();
            $table->unsignedInteger('markers_acknowledged')->nullable();
            $table->boolean('all_steps_completed')->default(false);
            $table->string('verdict', 20);
            $table->boolean('needs_repeat')->default(false);
            $table->json('failed_checks')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('data_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_session_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('original_name');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamp('recorded_start_utc', 3)->nullable();
            $table->timestamp('recorded_end_utc', 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_files');
        Schema::dropIfExists('quality_reviews');
        Schema::dropIfExists('session_devices');
    }
};
