<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store a snapshot of the flow's Drawflow graph_layout at the moment a run
 * starts. This allows the historical run viewer to show the graph exactly as
 * it was when the run executed — even if the user edits the graph afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            $table->json('graph_snapshot')->nullable()->after('final_output_model');
        });
    }

    public function down(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            $table->dropColumn('graph_snapshot');
        });
    }
};
