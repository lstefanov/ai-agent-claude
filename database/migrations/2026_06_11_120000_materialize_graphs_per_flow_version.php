<?php

use App\Models\FlowVersion;
use App\Services\GraphNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-version graph materialization: every FlowVersion owns its flow_nodes/
 * flow_edges rows, runs pin a version and execute its graph directly. The
 * flow-level materialized copy (flows.graph_layout + flows.plan_intent) is
 * dropped — versions are the single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->foreignId('flow_version_id')->nullable()->after('flow_id');
        });
        Schema::table('flow_edges', function (Blueprint $table) {
            $table->foreignId('flow_version_id')->nullable()->after('flow_id');
        });

        // Existing rows ARE the active version's materialization — claim them.
        // Historical runs executed that same graph, so pin them too.
        DB::statement('UPDATE flow_nodes SET flow_version_id = (SELECT id FROM flow_versions WHERE flow_versions.flow_id = flow_nodes.flow_id AND flow_versions.is_active = 1 LIMIT 1)');
        DB::statement('UPDATE flow_edges SET flow_version_id = (SELECT id FROM flow_versions WHERE flow_versions.flow_id = flow_edges.flow_id AND flow_versions.is_active = 1 LIMIT 1)');
        DB::statement('UPDATE flow_runs SET flow_version_id = (SELECT id FROM flow_versions WHERE flow_versions.flow_id = flow_runs.flow_id AND flow_versions.is_active = 1 LIMIT 1) WHERE flow_version_id IS NULL');

        // Rows of flows without an active version cannot be owned by anyone.
        DB::table('flow_nodes')->whereNull('flow_version_id')->delete();
        DB::table('flow_edges')->whereNull('flow_version_id')->delete();

        // MySQL errno 1553: the flow_id FK is backed by the unique we drop next —
        // give it a plain index first.
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->index('flow_id');
        });
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropUnique(['flow_id', 'node_key']);
        });

        // Non-active versions get their own rows. Must run after the old unique
        // is gone — they reuse the active version's node_keys within the flow.
        $normalizer = app(GraphNormalizer::class);
        FlowVersion::query()
            ->where('is_active', false)
            ->whereNotNull('graph_layout')
            ->each(fn (FlowVersion $version) => $normalizer->sync($version, $version->graph_layout));

        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->unsignedBigInteger('flow_version_id')->nullable(false)->change();
        });
        // FK before the unique: MySQL keeps a dedicated FK index, so down() can
        // drop the unique without orphaning the constraint.
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->foreign('flow_version_id')->references('id')->on('flow_versions')->cascadeOnDelete();
        });
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->unique(['flow_version_id', 'node_key']);
        });

        Schema::table('flow_edges', function (Blueprint $table) {
            $table->unsignedBigInteger('flow_version_id')->nullable(false)->change();
        });
        Schema::table('flow_edges', function (Blueprint $table) {
            $table->foreign('flow_version_id')->references('id')->on('flow_versions')->cascadeOnDelete();
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn(['graph_layout', 'plan_intent']);
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->json('graph_layout')->nullable();
            $table->json('plan_intent')->nullable();
        });
        DB::statement('UPDATE flows SET graph_layout = (SELECT graph_layout FROM flow_versions WHERE flow_versions.flow_id = flows.id AND flow_versions.is_active = 1 LIMIT 1), plan_intent = (SELECT plan_intent FROM flow_versions WHERE flow_versions.flow_id = flows.id AND flow_versions.is_active = 1 LIMIT 1)');

        // Lossy: only the active version's materialization survives.
        DB::statement('DELETE FROM flow_nodes WHERE flow_version_id NOT IN (SELECT id FROM flow_versions WHERE is_active = 1)');
        DB::statement('DELETE FROM flow_edges WHERE flow_version_id NOT IN (SELECT id FROM flow_versions WHERE is_active = 1)');

        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->unique(['flow_id', 'node_key']);
        });
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropIndex(['flow_id']);
        });
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropUnique(['flow_version_id', 'node_key']);
        });
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flow_version_id');
        });

        Schema::table('flow_edges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flow_version_id');
        });
    }
};
