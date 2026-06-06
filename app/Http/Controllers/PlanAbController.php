<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Фаза 4 UI — A/B сравнение на planner провайдърите за конкретен flow:
 *
 *  - show:   страницата „A/B сравнение" (бутон от flows/show)
 *  - start:  пуска flows:plan-ab във фонов процес и връща token
 *  - status: poll на cache състоянието (per provider: agents, цена, време)
 *  - apply:  стейджва избрания план и препраща към builder-а, който го
 *            изгражда като граф (същият път като нормалното генериране)
 */
class PlanAbController extends Controller
{
    public function show(Flow $flow)
    {
        $flow->load('company');

        $available = $this->availableProviders();

        return view('flows.plan-ab', [
            'flow' => $flow,
            'available' => $available,
            'availability' => collect(\App\Console\Commands\PlanAbCommand::PROVIDERS)
                ->mapWithKeys(fn ($p) => [$p => in_array($p, $available, true)])
                ->all(),
            'plannerModels' => [
                'ollama' => (string) config('services.ollama.planner_model'),
                'openai' => (string) config('services.openai.model'),
                'anthropic' => (string) config('services.anthropic.model'),
            ],
        ]);
    }

    /**
     * Start planning — for ALL available providers (no body) or for a single
     * one (body: {provider}). Returns the token + which providers it covers,
     * so the page can poll/merge per provider.
     */
    public function start(Request $request, Flow $flow): JsonResponse
    {
        $provider = (string) $request->input('provider', '');
        $available = $this->availableProviders();

        if ($provider !== '') {
            if (! in_array($provider, \App\Console\Commands\PlanAbCommand::PROVIDERS, true)) {
                return response()->json(['error' => 'Непознат provider.'], 422);
            }
            if (! in_array($provider, $available, true)) {
                return response()->json(['error' => "Провайдърът {$provider} не е достъпен (ключ/сървър)."], 503);
            }
            $covered = [$provider];
        } else {
            if ($available === []) {
                return response()->json(['error' => 'Няма достъпен нито един planner provider (.env / Ollama).'], 503);
            }
            $covered = $available;
        }

        $token = Str::uuid()->toString();

        Cache::put("plan_ab_{$token}", ['status' => 'running', 'providers' => []], now()->addMinutes(30));

        $php = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tok = escapeshellarg($token);
        $flowId = (int) $flow->id;
        $providerArg = $provider !== '' ? ' --provider='.escapeshellarg($provider) : '';
        exec("{$php} {$artisan} flows:plan-ab {$flowId} --token={$tok}{$providerArg} >> ".escapeshellarg(storage_path('logs/plan-ab.log')).' 2>&1 &');

        return response()->json(['token' => $token, 'providers' => $covered]);
    }

    /** @return array<int, string> */
    private function availableProviders(): array
    {
        return array_values(array_filter(
            \App\Console\Commands\PlanAbCommand::PROVIDERS,
            fn ($p) => $p === 'ollama'
                ? app(\App\Services\OllamaService::class)->isAvailable()
                : ! empty(config("services.{$p}.api_key")),
        ));
    }

    public function status(string $token): JsonResponse
    {
        $state = Cache::get("plan_ab_{$token}");

        if (! $state) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът е изтекъл. Стартирай ново сравнение.'], 404);
        }

        return response()->json($state);
    }

    /**
     * "Използвай този план": stage the chosen provider's agents and send the
     * builder to materialize them (it reuses the exact generation pipeline —
     * applyGeneratedGraph + auto-save, which also feeds the plan library).
     */
    public function apply(Request $request, Flow $flow): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'provider' => 'required|in:openai,anthropic',
        ]);

        $state = Cache::get("plan_ab_{$validated['token']}");
        $plan = $state['providers'][$validated['provider']] ?? null;

        if (! is_array($plan) || ($plan['status'] ?? '') !== 'completed' || empty($plan['agents'])) {
            return response()->json(['error' => 'Избраният план не е наличен (изтекъл token или провалено планиране).'], 422);
        }

        // Pair the flow with the chosen plan's intent — the plan library snapshot
        // on save (= approval) needs it.
        if (is_array($plan['intent'] ?? null)) {
            $flow->update(['plan_intent' => $plan['intent']]);
        }

        // One-shot staging consumed by FlowBuilderController on next open.
        Cache::put("staged_plan_{$flow->id}", $plan['agents'], now()->addMinutes(10));

        return response()->json([
            'ok' => true,
            'redirect' => route('flows.builder', ['flow' => $flow, 'staged' => 1]),
        ]);
    }
}
