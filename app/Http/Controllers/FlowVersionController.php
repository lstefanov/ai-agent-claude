<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowVersion;
use App\Services\AgentGeneratorService;
use App\Services\FlowVersionService;
use App\Support\ModelLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Graph versions ("шаблони") на един flow:
 *
 *  - store:        builder save-диалог след генерация ("Запази като нов шаблон")
 *  - storeFromPlan: A/B страницата ("💾 Запази") — агентите идват от plan cache
 *                   (или редактирани в DAG прегледа) и графът се строи сървърно
 *  - activate / update (rename) / destroy — dashboard секцията "Шаблони"
 */
class FlowVersionController extends Controller
{
    public function __construct(private FlowVersionService $versions) {}

    public function store(Request $request, Flow $flow): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
            'graph' => 'required|array',
            'agents' => 'nullable|array',
            'generator' => 'nullable|array',
            'intent' => 'nullable|array',
            'model_level' => ['nullable', Rule::in(['low', 'medium', 'high', 'ultra', 'god', 'custom'])],
            'cost_usd' => 'nullable|numeric',
            'duration_ms' => 'nullable|integer',
        ]);

        $version = $this->versions->createFromLayout(
            $flow,
            $validated['graph'],
            $validated['name'],
            (bool) ($validated['is_active'] ?? false),
            $validated['agents'] ?? null,
            [
                'intent' => $validated['intent'] ?? null,
                'generator' => $validated['generator'] ?? null,
                'model_level' => $validated['model_level'] ?? null,
                'cost_usd' => isset($validated['cost_usd']) ? (float) $validated['cost_usd'] : null,
                'duration_ms' => isset($validated['duration_ms']) ? (int) $validated['duration_ms'] : null,
            ],
        );

        return response()->json(['ok' => true, 'version' => $this->payload($version)]);
    }

    public function storeFromPlan(Request $request, Flow $flow, AgentGeneratorService $generator): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'label' => 'required|string',
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
            'agents' => 'nullable|array',
        ]);

        $state = Cache::get("plan_ab_{$validated['token']}");
        $plan = $state['providers'][$validated['label']] ?? null;

        if (! is_array($plan) || ($plan['status'] ?? '') !== 'completed' || empty($plan['agents'])) {
            return response()->json(['error' => 'Планът не е наличен (изтекъл token или провалено планиране).'], 422);
        }

        // The DAG preview allows edits, but every plan re-passes the
        // deterministic hardening — the planner/user proposes, code guarantees.
        $level = ModelLevel::fromRequest($plan['level'] ?? null);
        $agents = $generator->finalizePlannedAgents(
            ! empty($validated['agents']) ? $validated['agents'] : $plan['agents'],
            $level,
        );

        if (count($agents) < 3) {
            return response()->json(['error' => 'Планът съдържа твърде малко агенти.'], 422);
        }

        $version = $this->versions->createFromAgents(
            $flow,
            $agents,
            $validated['name'],
            (bool) ($validated['is_active'] ?? false),
            [
                'intent' => is_array($plan['intent'] ?? null) ? $plan['intent'] : null,
                'generator' => ['label' => (string) ($plan['model'] ?? $validated['label'])],
                'model_level' => $level->value,
                'cost_usd' => isset($plan['cost_usd']) ? (float) $plan['cost_usd'] : null,
                'duration_ms' => isset($plan['duration_ms']) ? (int) $plan['duration_ms'] : null,
            ],
        );

        return response()->json(['ok' => true, 'version' => $this->payload($version)]);
    }

    public function update(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);

        $this->versions->rename($version, $validated['name']);

        return response()->json(['ok' => true, 'version' => $this->payload($version->fresh())]);
    }

    public function activate(Flow $flow, FlowVersion $version): JsonResponse
    {
        $this->versions->activate($version);

        return response()->json(['ok' => true, 'version' => $this->payload($version->fresh())]);
    }

    public function duplicate(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);

        $newVersion = $this->versions->duplicate($version, $validated['name']);

        return response()->json(['ok' => true, 'version' => $this->payload($newVersion)]);
    }

    public function destroy(Flow $flow, FlowVersion $version): JsonResponse
    {
        $this->versions->delete($version);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(FlowVersion $version): array
    {
        return [
            'id' => $version->id,
            'name' => $version->name,
            'is_active' => $version->is_active,
            'generator_label' => $version->generatorLabel(),
            'model_level' => $version->model_level,
        ];
    }
}
