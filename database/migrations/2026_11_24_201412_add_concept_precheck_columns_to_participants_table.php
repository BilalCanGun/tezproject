<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The concept pre-check is scored apart from the general baseline so that
     * prior knowledge of the target concept never shifts the stratification.
     */
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->decimal('concept_precheck_score', 6, 2)->nullable()->after('baseline_max');
            $table->decimal('concept_precheck_max', 6, 2)->nullable()->after('concept_precheck_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn(['concept_precheck_score', 'concept_precheck_max']);
        });
    }
};
