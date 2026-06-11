<?php

namespace App\Http\Controllers;

use App\Console\Commands\PlanAbCommand;
use App\Models\AgentTemplate;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Services\GeneratorService;
use App\Support\ModelLevel;
use App\Support\PlannerPhases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Фаза 4 UI — A/B сравнение на planner провайдърите за конкретен flow:
 *
 *  - show:   страницата „A/B сравнение" (бутон от flows/show)
 *  - start:  пуска flows:plan-ab във фонов процес и връща token; освен
 *            единичен провайдър приема и provider=hybrid + phases map
 *            (per-phase комбинация → --variant grammar на командата)
 *  - status: poll на cache състоянието (per provider: agents, цена, време)
 *
 * Запазването на план като шаблон е във FlowVersionController::storeFromPlan
 * („💾 Запази" на картите — без redirect, графът се строи сървърно).
 */
class PlanAbController extends Controller
{
    public function __construct(private GeneratorService $generator) {}

    public function show(Flow $flow)
    {
        $flow->load('company');

        $available = $this->availableProviders();

        $agentTypes = collect(config('agent_types', []))
            ->map(fn ($meta, $type) => [
                'type' => $type,
                'label' => $meta['label'] ?? $type,
                'description' => $meta['description'] ?? '',
                'output_role' => $meta['output_role'] ?? 'body',
            ])
            ->values()
            ->all();

        $templateIcons = AgentTemplate::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('company_id')->where('is_active', true))
                ->orWhere('company_id', $flow->company_id)
            )
            ->whereNotNull('icon')
            ->orderByRaw('company_id is not null')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('icon', 'type');

        return view('flows.plan-ab', [
            'flow' => $flow,
            'available' => $available,
            'availability' => collect(PlanAbCommand::PROVIDERS)
                ->mapWithKeys(fn ($p) => [$p => in_array($p, $available, true)])
                ->all(),
            'plannerModels' => collect(PlanAbCommand::PROVIDERS)->mapWithKeys(fn ($p) => [
                $p => PlannerPhases::defaultModelFor($p),
            ])->all(),
            // Phase-picker данни за картата „Хибрид" + DAG прегледа.
            'plannerDefaults' => $this->generator->resolveAllPhases(),
            'cloudModels' => PlannerPhases::cloudModels(),
            'pricing' => PlannerPhases::pricing(),
            'agentTypes' => $agentTypes,
            'templateIcons' => $templateIcons,
            'ollamaModels' => LlmModel::where('is_available', true)
                ->where('is_enabled', true)
                ->orderBy('category')
                ->orderBy('display_name')
                ->get(['ollama_tag', 'display_name', 'category', 'strengths']),
            'saveUrl' => route('flows.versions.from-plan', $flow),
        ]);
    }

    /**
     * Start planning — for ALL available providers (body: {models: {provider:
     * model}}), for a single one (body: {provider, model}) or for a hybrid
     * per-phase combo (body: {provider: 'hybrid', phases: {intent_analysis:
     * {provider, model}, …}}). Chosen models travel as --variant phase specs.
     * Returns the token + which labels it covers, so the page can poll/merge.
     */
    public function start(Request $request, Flow $flow): JsonResponse
    {
        $provider = (string) $request->input('provider', '');
        $available = $this->availableProviders();

        $modelRule = ['nullable', 'string', 'max:120', 'regex:'.FlowController::MODEL_PATTERN];
        $variantArgs = '';

        if ($provider === 'hybrid') {
            $validated = $request->validate([
                'phases' => 'required|array',
                'phases.*.provider' => ['required', Rule::in(PlanAbCommand::PROVIDERS)],
                'phases.*.model' => $modelRule,
            ]);

            $phases = collect($validated['phases'])->only(PlannerPhases::PHASES);

            if ($missing = $phases->pluck('provider')->unique()->reject(fn ($p) => in_array($p, $available, true))->values()->all()) {
                return response()->json(['error' => 'Недостъпни провайдъри: '.implode(', ', $missing).' (ключ/сървър).'], 503);
            }

            $variantArgs = ' --variant='.escapeshellarg($this->variantSpec('hybrid', $phases->all()));
            $covered = ['hybrid'];
        } elseif ($provider !== '') {
            if (! in_array($provider, PlanAbCommand::PROVIDERS, true)) {
                return response()->json(['error' => 'Непознат provider.'], 422);
            }
            if (! in_array($provider, $available, true)) {
                return response()->json(['error' => "Провайдърът {$provider} не е достъпен (ключ/сървър)."], 503);
            }

            $validated = $request->validate(['model' => $modelRule]);
            if (($model = (string) ($validated['model'] ?? '')) !== '') {
                $variantArgs = ' --variant='.escapeshellarg($this->variantSpec($provider, $this->uniformPhases($provider, $model)));
            }
            $covered = [$provider];
        } else {
            if ($available === []) {
                return response()->json(['error' => 'Няма достъпен нито един planner provider (.env / Ollama).'], 503);
            }

            $validated = $request->validate(['models' => 'sometimes|array', 'models.*' => $modelRule]);
            // Избраните модели по карти → по един variant на наличен провайдър
            // (label = provider, за да merge-не страницата по същите ключове).
            if ($models = (array) ($validated['models'] ?? [])) {
                foreach ($available as $p) {
                    $variantArgs .= ' --variant='.escapeshellarg($this->variantSpec($p, $this->uniformPhases($p, $models[$p] ?? null)));
                }
            }
            $covered = $available;
        }

        $token = Str::uuid()->toString();

        Cache::put("plan_ab_{$token}", ['status' => 'running', 'providers' => []], now()->addMinutes(30));

        $php = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tok = escapeshellarg($token);
        $flowId = (int) $flow->id;
        $providerArg = $provider !== '' && $provider !== 'hybrid' && $variantArgs === '' ? ' --provider='.escapeshellarg($provider) : '';
        // Ниво на runtime моделите за агентите (low|medium|high|ultra) — важи
        // за всички варианти в този run; невалидно/липсващо → medium.
        $levelArg = ' --level='.escapeshellarg(ModelLevel::fromRequest($request->input('level'))->value);
        exec("{$php} {$artisan} flows:plan-ab {$flowId} --token={$tok}{$providerArg}{$variantArgs}{$levelArg} >> ".escapeshellarg(storage_path('logs/plan-ab.log')).' 2>&1 &');

        return response()->json(['token' => $token, 'providers' => $covered]);
    }

    /**
     * --variant grammar: "label:intent=provider[:model],design=…" — every
     * phase explicit, so nothing leaks from .env or a previous variant.
     *
     * @param  array<string, array{provider: string, model: ?string}>  $phases
     */
    private function variantSpec(string $label, array $phases): string
    {
        $aliases = array_flip(PlannerPhases::ALIASES);

        return $label.':'.collect($phases)
            ->map(fn ($s, $phase) => ($aliases[$phase] ?? $phase).'='.$s['provider'].(! empty($s['model']) ? ':'.$s['model'] : ''))
            ->implode(',');
    }

    /** @return array<string, array{provider: string, model: ?string}> */
    private function uniformPhases(string $provider, ?string $model): array
    {
        return collect(PlannerPhases::PHASES)
            ->mapWithKeys(fn ($phase) => [$phase => ['provider' => $provider, 'model' => $model !== '' ? $model : null]])
            ->all();
    }

    /** @return array<int, string> */
    private function availableProviders(): array
    {
        return array_values(array_filter(
            PlanAbCommand::PROVIDERS,
            fn ($p) => $this->generator->providerAvailable($p),
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
}
