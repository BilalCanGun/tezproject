<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blind human scoring of coding attempts against the task's scoring key:
     * partial credit, misconceptions shown and target bugs found. Stored next
     * to the automatic test score, which it never overwrites.
     */
    public function up(): void
    {
        Schema::create('code_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('users')->restrictOnDelete();
            $table->decimal('score', 6, 2);
            $table->json('partial_credit')->nullable();
            $table->json('misconceptions')->nullable();
            $table->json('bugs_found')->nullable();
            $table->boolean('alternative_solution')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['task_attempt_id', 'rater_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_reviews');
    }
};
