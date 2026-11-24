<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Near transfer, far transfer and debugging tasks come in two isomorphic
     * forms (A/B). A form task has no fixed session: it appears in the session
     * its form is assigned to by the participant's counterbalanced form order
     * (AB or BA).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('session_number')->nullable()->change();
            $table->string('form', 1)->nullable()->after('session_number');
        });

        Schema::table('randomization_slots', function (Blueprint $table) {
            $table->string('form_order', 2)->nullable()->after('condition');
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->string('form_order', 2)->nullable()->after('condition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn('form_order');
        });

        Schema::table('randomization_slots', function (Blueprint $table) {
            $table->dropColumn('form_order');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('form');
            $table->unsignedTinyInteger('session_number')->nullable(false)->change();
        });
    }
};
