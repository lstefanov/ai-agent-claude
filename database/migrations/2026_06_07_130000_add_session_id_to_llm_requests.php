<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups the individual planner calls of ONE "auto agent creator" run.
 *
 * A planning session fires several phases (intent_analysis → pipeline_design →
 * plan_critique), each a separate llm_requests row. They share the planner's
 * log token; storing it here lets the Costs page show one row per generation
 * session instead of one per flow. Runtime calls leave this null — they group
 * by flow_run_id instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_requests', function (Blueprint $table) {
            $table->string('session_id', 64)->nullable()->after('purpose')->index();
        });
    }

    public function down(): void
    {
        Schema::table('llm_requests', function (Blueprint $table) {
            $table->dropColumn('session_id');
        });
    }
};
