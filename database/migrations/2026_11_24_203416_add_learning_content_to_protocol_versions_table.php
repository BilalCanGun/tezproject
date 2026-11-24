<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The common core lesson shown to every condition at the start of session
     * 1, and the learning objectives tasks are mapped to.
     */
    public function up(): void
    {
        Schema::table('protocol_versions', function (Blueprint $table) {
            $table->longText('core_lesson')->nullable()->after('concept_rationale');
            $table->json('learning_objectives')->nullable()->after('core_lesson');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('protocol_versions', function (Blueprint $table) {
            $table->dropColumn(['core_lesson', 'learning_objectives']);
        });
    }
};
