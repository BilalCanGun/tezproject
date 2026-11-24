<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task profile and scoring key: what the task measures, the expected
     * approach, difficulty, alternative and partially correct solutions,
     * misconceptions, target bugs and explanation elements. Researcher-only.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('scoring_key')->nullable()->after('mcq_answer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('scoring_key');
        });
    }
};
