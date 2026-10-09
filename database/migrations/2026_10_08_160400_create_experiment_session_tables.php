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
        Schema::create('experiment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('session_number');
            $table->string('status', 20)->default('scheduled');
            $table->string('condition', 30)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('ended_at', 3)->nullable();
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kiosk_token_hash', 64)->nullable()->unique();
            $table->timestamp('kiosk_token_expires_at')->nullable();
            $table->timestamp('kiosk_connected_at')->nullable();
            $table->timestamp('kiosk_last_seen_at')->nullable();
            $table->json('bridge_status')->nullable();
            $table->integer('client_clock_offset_ms')->nullable();

            $table->json('ai_settings_snapshot')->nullable();
            $table->json('environment')->nullable();
            $table->json('setup_checklist')->nullable();
            $table->boolean('is_paused')->default(false);
            $table->string('abort_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'session_number']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('session_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 30);
            $table->foreignId('task_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label');
            $table->json('config')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('ended_at', 3)->nullable();
            $table->timestamps();

            $table->unique(['experiment_session_id', 'position']);
        });

        Schema::create('task_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_step_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->string('phase', 20)->default('reading');
            $table->boolean('help_available')->default(false);

            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('planning_started_at', 3)->nullable();
            $table->timestamp('coding_started_at', 3)->nullable();
            $table->timestamp('checking_started_at', 3)->nullable();
            $table->timestamp('submitted_at', 3)->nullable();
            $table->string('end_reason', 20)->nullable();

            $table->longText('final_code')->nullable();
            $table->longText('explanation_text')->nullable();
            $table->string('mcq_answer', 20)->nullable();
            $table->unsignedSmallInteger('tests_passed')->nullable();
            $table->unsignedSmallInteger('tests_total')->nullable();
            $table->decimal('auto_score', 6, 2)->nullable();

            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('failed_run_count')->default(0);
            $table->timestamp('first_failure_at', 3)->nullable();
            $table->unsignedInteger('changed_runs_after_failure')->default(0);
            $table->string('last_run_code_hash', 64)->nullable();
            $table->unsignedInteger('typed_chars')->default(0);
            $table->unsignedInteger('deleted_chars')->default(0);
            $table->unsignedInteger('pasted_chars')->default(0);
            $table->unsignedInteger('ai_pasted_chars')->default(0);
            $table->unsignedInteger('help_request_count')->default(0);
            $table->unsignedInteger('resource_view_count')->default(0);
            $table->unsignedInteger('planning_input_violations')->default(0);
            $table->unsignedInteger('focus_loss_count')->default(0);
            $table->timestamps();

            $table->index(['experiment_session_id', 'task_id']);
        });

        Schema::create('interaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 40);
            $table->unsignedSmallInteger('marker_code')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('client_epoch_ms');
            $table->double('client_perf_ms');
            $table->double('lsl_time')->nullable();
            $table->timestamp('received_at', 3);

            $table->unique(['experiment_session_id', 'sequence']);
            $table->index(['experiment_session_id', 'type']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->longText('raw_content')->nullable();
            $table->string('condition', 30);
            $table->string('provider', 30)->nullable();
            $table->string('model', 80)->nullable();
            $table->json('request_params')->nullable();
            $table->unsignedTinyInteger('hint_level')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('stop_reason', 40)->nullable();
            $table->json('metrics')->nullable();
            $table->string('guard_action', 20)->nullable();
            $table->string('error')->nullable();
            $table->timestamp('created_at', 3)->nullable();

            $table->index(['task_attempt_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('interaction_events');
        Schema::dropIfExists('task_attempts');
        Schema::dropIfExists('session_steps');
        Schema::dropIfExists('experiment_sessions');
    }
};
