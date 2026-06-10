<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill: every pre-versions flow with a saved graph gets its active
     * graph as the "Default" template (flow_nodes/flow_edges are already
     * materialized — no sync needed, this only snapshots the layout).
     */
    public function up(): void
    {
        $flows = DB::table('flows')
            ->whereNotNull('graph_layout')
            ->whereNotIn('id', DB::table('flow_versions')->select('flow_id'))
            ->get(['id', 'graph_layout', 'plan_intent']);

        foreach ($flows as $flow) {
            DB::table('flow_versions')->insert([
                'flow_id' => $flow->id,
                'name' => 'Default',
                'is_active' => true,
                'graph_layout' => $flow->graph_layout,
                'plan_intent' => $flow->plan_intent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data backfill — nothing to restore.
    }
};
