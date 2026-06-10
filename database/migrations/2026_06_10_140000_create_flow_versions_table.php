<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named graph versions ("шаблони") per flow. Exactly one is active —
     * the active version is MATERIALIZED into flows.graph_layout +
     * flow_nodes/flow_edges (execution always reads the materialized graph).
     */
    public function up(): void
    {
        Schema::create('flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(false)->index();
            // Finalized planner agents (uid/depends_on shape) — kept for the
            // A/B DAG preview and re-materialization metadata.
            $table->json('agents')->nullable();
            // Raw Drawflow export — the source copied into flows.graph_layout
            // on activation.
            $table->json('graph_layout')->nullable();
            $table->json('plan_intent')->nullable();
            // {label: "anthropic:claude-sonnet-4-6" | "intent=…, design=…", phases: {...}}
            $table->json('generator')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_versions');
    }
};
