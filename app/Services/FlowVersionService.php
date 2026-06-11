<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\FlowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lifecycle of flow graph versions ("шаблони"). A flow has many named
 * versions; exactly one is ACTIVE — the default for webhook/scheduler runs and
 * the builder's initial view. EVERY version is materialized into its own
 * flow_nodes/flow_edges rows on create/save, so runs pin a version and execute
 * its graph directly; several versions of one flow can run in parallel.
 */
class FlowVersionService
{
    public function __construct(
        private GraphNormalizer $normalizer,
        private PlanLibraryService $planLibrary,
        private PlanGraphBuilder $graphBuilder,
    ) {}

    /**
     * Create a version straight from finalized planner agents (A/B page "Запази")
     * — the Drawflow layout is built server-side, no browser needed.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array{intent?: ?array, generator?: ?array, cost_usd?: ?float, duration_ms?: ?int}  $meta
     */
    public function createFromAgents(Flow $flow, array $agents, string $name, bool $isActive, array $meta = []): FlowVersion
    {
        $layout = $this->graphBuilder->build($agents, $flow->company_id);

        return $this->create($flow, $layout, $name, $isActive, $agents, $meta);
    }

    /**
     * Create a version from a client-built Drawflow export (builder save dialog).
     *
     * @param  array<int, array<string, mixed>>|null  $agents
     * @param  array{intent?: ?array, generator?: ?array, cost_usd?: ?float, duration_ms?: ?int}  $meta
     */
    public function createFromLayout(Flow $flow, array $export, string $name, bool $isActive, ?array $agents = null, array $meta = []): FlowVersion
    {
        return $this->create($flow, $export, $name, $isActive, $agents, $meta);
    }

    /**
     * Make the version the flow's default ("active") graph — just the flag
     * swap; the graph rows are already materialized per-version. Activating IS
     * the plan approval — the snapshot feeds the plan library (best-effort).
     */
    public function activate(FlowVersion $version): void
    {
        if (! is_array($version->graph_layout)) {
            throw ValidationException::withMessages(['version' => 'Шаблонът няма граф — не може да бъде активиран.']);
        }

        DB::transaction(function () use ($version) {
            $version->flow->versions()->whereKeyNot($version->id)->update(['is_active' => false]);
            $version->update(['is_active' => true]);
        });

        $this->captureForPlanLibrary($version);
    }

    /**
     * Persist a builder save into the version and re-materialize its
     * flow_nodes/flow_edges so they stay in sync with the editor.
     *
     * $extra may carry agents/generator/plan_intent/cost_usd/duration_ms when
     * the save follows a fresh generation (overwrite) — null keys are kept as-is.
     *
     * @param  array{agents?: ?array, generator?: ?array, plan_intent?: ?array, cost_usd?: ?float, duration_ms?: ?int}  $extra
     */
    public function updateLayout(FlowVersion $version, array $export, array $extra = []): void
    {
        DB::transaction(function () use ($version, $export, $extra) {
            $version->update(array_filter([
                'graph_layout' => $export,
                'agents' => $extra['agents'] ?? null,
                'generator' => $extra['generator'] ?? null,
                'plan_intent' => $extra['plan_intent'] ?? null,
                'cost_usd' => $extra['cost_usd'] ?? null,
                'duration_ms' => $extra['duration_ms'] ?? null,
            ], fn ($v) => $v !== null));

            $this->materialize($version->refresh());
        });

        if ($version->is_active) {
            $this->captureForPlanLibrary($version);
        }
    }

    public function rename(FlowVersion $version, string $name): void
    {
        $version->update(['name' => $name]);
    }

    public function delete(FlowVersion $version): void
    {
        if ($version->is_active) {
            throw ValidationException::withMessages(['version' => 'Активният шаблон не може да бъде изтрит — първо активирай друг.']);
        }

        // The version's node rows are what its in-flight run executes — deleting
        // them mid-run would break the executing jobs.
        if ($version->flowRuns()->whereIn('status', ['pending', 'running'])->exists()) {
            throw ValidationException::withMessages(['version' => 'Шаблонът има активно изпълнение — изчакай го да приключи.']);
        }

        $version->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $agents
     * @param  array{intent?: ?array, generator?: ?array, cost_usd?: ?float, duration_ms?: ?int}  $meta
     */
    private function create(Flow $flow, array $layout, string $name, bool $isActive, ?array $agents, array $meta): FlowVersion
    {
        $version = null;

        DB::transaction(function () use ($flow, $layout, $name, $isActive, $agents, $meta, &$version) {
            $version = $flow->versions()->create([
                'name' => $name,
                'is_active' => false,
                'agents' => $agents,
                'graph_layout' => $layout,
                'plan_intent' => $meta['intent'] ?? null,
                'generator' => $meta['generator'] ?? null,
                'cost_usd' => $meta['cost_usd'] ?? null,
                'duration_ms' => $meta['duration_ms'] ?? null,
            ]);

            if ($isActive) {
                $flow->versions()->whereKeyNot($version->id)->update(['is_active' => false]);
                $version->update(['is_active' => true]);
            }

            $this->materialize($version);
        });

        if ($isActive) {
            $this->captureForPlanLibrary($version);
        }

        return $version;
    }

    private function materialize(FlowVersion $version): void
    {
        if (! is_array($version->graph_layout)) {
            return;
        }

        $this->normalizer->sync($version, $version->graph_layout);
    }

    /** Plan-library snapshot is best-effort — it must never fail the save. */
    private function captureForPlanLibrary(FlowVersion $version): void
    {
        try {
            $this->planLibrary->captureApprovedPlan($version->fresh());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
