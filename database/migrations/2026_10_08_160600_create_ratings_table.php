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
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rater_type', 10);
            $table->json('scores');
            $table->decimal('total', 6, 2);
            $table->text('notes')->nullable();
            $table->string('model', 80)->nullable();
            $table->timestamps();

            $table->unique(['task_attempt_id', 'rater_id']);
            $table->index(['task_attempt_id', 'rater_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
