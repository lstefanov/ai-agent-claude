<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The planner's intent analysis (phase A) for the most recent generation —
 * linked to the flow so the approved graph can be paired with its intent when
 * captured into the plan library.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->json('plan_intent')->nullable()->after('graph_layout');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('plan_intent');
        });
    }
};
