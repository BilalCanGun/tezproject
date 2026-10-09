<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Protocol versions hold every parameter that must stay fixed during data
     * collection (model settings, task battery, rubric, quality thresholds).
     */
    public function up(): void
    {
        Schema::create('protocol_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('phase', 20);
            $table->text('description')->nullable();
            $table->string('concept_title')->nullable();
            $table->text('concept_rationale')->nullable();
            $table->json('settings');
            $table->longText('system_prompt_direct');
            $table->longText('system_prompt_socratic');
            $table->boolean('is_active')->default(false);
            $table->timestamp('frozen_at')->nullable();
            $table->foreignId('frozen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('session_number');
            $table->string('kind', 30);
            $table->string('title');
            $table->string('isomorph_group', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->longText('prompt');
            $table->text('starter_code')->nullable();
            $table->text('reference_solution')->nullable();
            $table->json('test_cases')->nullable();
            $table->json('mcq_options')->nullable();
            $table->string('mcq_answer', 20)->nullable();
            $table->decimal('max_score', 6, 2)->default(1);
            $table->unsignedSmallInteger('min_reading_seconds')->default(0);
            $table->unsignedSmallInteger('planning_seconds')->default(0);
            $table->unsignedSmallInteger('time_limit_seconds')->default(600);
            $table->boolean('help_allowed')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['protocol_version_id', 'session_number', 'sort_order']);
        });

        Schema::create('resource_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_version_id')->constrained()->cascadeOnDelete();
            $table->string('section', 30);
            $table->string('title');
            $table->longText('body');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_version_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('min_score')->default(0);
            $table->unsignedTinyInteger('max_score')->default(3);
            $table->json('anchors')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['protocol_version_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rubric_criteria');
        Schema::dropIfExists('resource_articles');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('protocol_versions');
    }
};
