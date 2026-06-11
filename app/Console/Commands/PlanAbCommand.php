<?php

namespace App\Console\Commands;

use App\Models\AgentGenerationLog;
use App\Models\Flow;
use App\Services\AgentGeneratorService;
use App\Services\FlowPlannerService;
use App\Services\GeneratorService;
use App\Support\ModelLevel;
use App\Support\PlannerPhases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Throwable;

/**
 * A/B на planner варианти: планира един и същ flow с различни провайдъри И/ИЛИ
 * различни per-phase хибридни комбинации, после сравнява pipeline-ите.
 *
 *  - CLI:  php artisan flows:plan-ab 25                        → всички налични провайдъри
 *          php artisan flows:plan-ab 25 --provider=ollama      → само един
 *          php artisan flows:plan-ab 25 \
 *              --variant="cheap:deepseek" \
 *              --variant="hybrid:design=anthropic:claude-sonnet-4-6,intent=gemini,critique=gemini"
 *          php artisan flows:plan-ab 25 --variant=hybrid       → preset от
 *          config('services.planner.ab_presets')
 *  - UI:   ... --token=X [--provider=Y] → резултатите отиват в cache
 *          (poll-ва ги страницата „A/B сравнение" на flow-а)
 *
 * Variant grammar: "label:spec", spec = provider[:model] (всички фази) или
 * "phase=provider[:model],..." с фази intent|design|critique|revision.
 * Label-ът може да съвпада с provider-име, когато spec-ът е phase map
 * ("openai:design=openai:gpt-4o-mini,…") — така UI картите пращат избран
 * модел под label = провайдъра. Всеки вариант сетва ВСИЧКИ четири фази
 * изрично — настройки от .env или от предишен вариант не могат да изтекат
 * в следващия.
 *
 * Всяка planner фаза се логва в agent_generation_logs (с цената), така че
 * пълните планове са видими и в панела „Лог на генерирането".
 */
class PlanAbCommand extends Command
{
    public const PROVIDERS = GeneratorService::PROVIDERS;

    private const PHASE_ALIASES = PlannerPhases::ALIASES;

    protected $signature = 'flows:plan-ab
        {flow : Flow ID}
        {--token= : Cache token for UI polling}
        {--provider= : Plan with a single provider (ollama|openai|anthropic|deepseek|gemini|xai|qwen)}
        {--variant=* : "label:provider[:model]" или "label:phase=provider[:model],..." (фази: intent|design|critique|revision); само име → preset от services.planner.ab_presets}
        {--level= : Ниво на runtime моделите за агентите (low|medium|high|ultra); празно → medium}';

    protected $description = 'Plan the same flow with different providers / hybrid per-phase combos and compare the resulting pipelines';

    public function handle(FlowPlannerService $planner, AgentGeneratorService $generator): int
    {
        $flow = Flow::with('company')->find($this->argument('flow'));
        $token = (string) $this->option('token');

        if (! $flow) {
            $this->error('Flow не е намерен.');
            $this->putState($token, ['status' => 'failed', 'error' => 'Flow не е намерен.']);

            return self::FAILURE;
        }

        $variants = $this->collectVariants();
        $level = ModelLevel::fromRequest((string) $this->option('level'));

        $plans = [];
        $state = ['status' => 'running', 'flow_id' => $flow->id, 'providers' => []];

        foreach ($variants as $label => $phases) {
            if ($phases === null) {
                $this->warn("⤬ {$label}: невалиден variant — пропускам.");
                $state['providers'][$label] = ['status' => 'skipped', 'error' => 'Невалиден variant.'];
                $this->putState($token, $state);

                continue;
            }

            if ($missing = $this->unavailableProviders($phases)) {
                $reason = 'Недостъпни провайдъри: '.implode(', ', $missing)
                    .' (липсва API ключ в .env или Ollama не отговаря).';
                $this->warn("⤬ {$label}: {$reason}");
                $state['providers'][$label] = ['status' => 'skipped', 'error' => $reason];
                $this->putState($token, $state);

                continue;
            }

            // Every phase is set explicitly so neither .env per-phase settings
            // nor a previous variant can leak into this run.
            foreach ($phases as $phase => $spec) {
                Config::set("services.planner.phases.{$phase}", $spec);
            }

            $this->info("▶ Планиране с {$label}…");
            $state['providers'][$label] = ['status' => 'running'];
            $this->putState($token, $state);

            $logToken = ($token ?: 'ab').'-'.Str::slug($label);
            $startMs = (int) (microtime(true) * 1000);

            try {
                $agents = $planner->plan($flow, null, $logToken, $level);

                if (count($agents) < 3) {
                    throw new \RuntimeException('Planner върна по-малко от 3 агента.');
                }

                // Same deterministic hardening as normal generation — the chosen
                // plan must be byte-equivalent to what the builder would build.
                $agents = $generator->finalizePlannedAgents($agents, $level);
            } catch (Throwable $e) {
                $this->error("✗ {$label}: ".$e->getMessage());
                $state['providers'][$label] = ['status' => 'failed', 'error' => $e->getMessage()];
                $this->putState($token, $state);

                continue;
            }

            $result = [
                'status' => 'completed',
                'agents' => $agents,
                'intent' => $planner->lastIntent(),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                'cost_usd' => round((float) AgentGenerationLog::where('token', $logToken)->sum('cost_usd'), 4),
                'model' => PlannerPhases::label($phases),
            ];

            $plans[$label] = $result;
            $state['providers'][$label] = $result;
            $this->putState($token, $state);
        }

        $state['status'] = 'completed';
        $this->putState($token, $state);

        $this->summarize($plans);

        return $plans === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The variant list to run: explicit --variant definitions, the UI's
     * --provider shorthand, or (neither given) one variant per provider.
     *
     * @return array<string, array<string, array{provider: string, model: ?string}>|null>
     */
    private function collectVariants(): array
    {
        $variants = [];

        foreach ((array) $this->option('variant') as $raw) {
            [$label, $phases] = $this->parseVariant((string) $raw);
            $variants[$label] = $phases;
        }

        if (($single = (string) $this->option('provider')) !== '') {
            $variants[$single] = in_array($single, self::PROVIDERS, true)
                ? $this->allPhases($single, null)
                : null;
        }

        if ($variants === []) {
            foreach (self::PROVIDERS as $provider) {
                $variants[$provider] = $this->allPhases($provider, null);
            }
        }

        return $variants;
    }

    /**
     * @return array{0: string, 1: array<string, array{provider: string, model: ?string}>|null}
     */
    private function parseVariant(string $raw): array
    {
        $raw = trim($raw);

        // Bare preset name from config('services.planner.ab_presets').
        $presets = (array) config('services.planner.ab_presets', []);
        if (! str_contains($raw, ':') && ! str_contains($raw, '=') && isset($presets[$raw])) {
            return [$raw, $this->parseSpec(implode(',', array_map(
                fn ($phase, $spec) => "{$phase}={$spec}",
                array_keys((array) $presets[$raw]),
                (array) $presets[$raw],
            )))];
        }

        // "label:spec" — unless the part before the first ":" is a provider
        // name, in which case the whole string is provider[:model]. A phase
        // map след provider-име ("openai:design=…") все пак е label:spec —
        // така UI-ят праща избран модел с label = самия провайдър.
        $label = $raw;
        $spec = $raw;
        if (str_contains($raw, ':')) {
            [$left, $rest] = explode(':', $raw, 2);
            if (! in_array($left, self::PROVIDERS, true) || str_contains($rest, '=')) {
                $label = $left;
                $spec = $rest;
            }
        }

        return [$label, $this->parseSpec($spec)];
    }

    /**
     * spec = "provider[:model]" (all phases) or "phase=provider[:model],..."
     *
     * @return array<string, array{provider: string, model: ?string}>|null
     */
    private function parseSpec(string $spec): ?array
    {
        $spec = trim($spec);

        if (! str_contains($spec, '=')) {
            [$provider, $model] = $this->splitProviderModel($spec);

            return in_array($provider, self::PROVIDERS, true)
                ? $this->allPhases($provider, $model)
                : null;
        }

        $phases = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $pair) {
            if (! str_contains($pair, '=')) {
                return null;
            }
            [$alias, $rest] = array_map('trim', explode('=', $pair, 2));
            $phase = self::PHASE_ALIASES[$alias] ?? (in_array($alias, self::PHASE_ALIASES, true) ? $alias : null);
            [$provider, $model] = $this->splitProviderModel($rest);

            if ($phase === null || ! in_array($provider, self::PROVIDERS, true)) {
                return null;
            }

            $phases[$phase] = ['provider' => $provider, 'model' => $model];
        }

        // Unspecified phases run on the global default provider — explicitly,
        // so the variant is fully self-describing.
        $default = (string) config('services.generator.provider', 'openai');
        foreach (self::PHASE_ALIASES as $phase) {
            $phases[$phase] ??= ['provider' => $default, 'model' => null];
        }

        return $phases;
    }

    /** @return array{0: string, 1: ?string} */
    private function splitProviderModel(string $value): array
    {
        $parts = explode(':', trim($value), 2);

        return [trim($parts[0]), isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null];
    }

    /** @return array<string, array{provider: string, model: ?string}> */
    private function allPhases(string $provider, ?string $model): array
    {
        return array_combine(
            array_values(self::PHASE_ALIASES),
            array_fill(0, count(self::PHASE_ALIASES), ['provider' => $provider, 'model' => $model]),
        );
    }

    /**
     * @param  array<string, array{provider: string, model: ?string}>  $phases
     * @return array<int, string>
     */
    private function unavailableProviders(array $phases): array
    {
        $providers = array_unique(array_column($phases, 'provider'));

        return array_values(array_filter($providers, fn ($p) => ! $this->providerAvailable($p)));
    }

    private function providerAvailable(string $provider): bool
    {
        return app(GeneratorService::class)->providerAvailable($provider);
    }

    private function putState(string $token, array $state): void
    {
        if ($token !== '') {
            Cache::put("plan_ab_{$token}", $state, now()->addMinutes(30));
        }
    }

    /** CLI summary: per-variant stats + pairwise type differences. */
    private function summarize(array $plans): void
    {
        if ($plans === []) {
            $this->warn('Нито един variant не завърши успешно.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Variant', 'Модели', 'Агенти', 'Време', 'Цена', 'Топология'],
            collect($plans)->map(fn ($p, $label) => [
                $label,
                $p['model'],
                count($p['agents']),
                round($p['duration_ms'] / 1000, 1).'s',
                $p['cost_usd'] > 0 ? '$'.$p['cost_usd'] : 'безплатно',
                collect($p['agents'])->map(fn ($a) => $a['uid'])->implode(' → '),
            ])->values()->all(),
        );

        $labels = array_keys($plans);
        foreach ($labels as $label) {
            $mine = collect($plans[$label]['agents'])->pluck('type')->unique();
            $others = collect($labels)
                ->reject(fn ($l) => $l === $label)
                ->flatMap(fn ($l) => collect($plans[$l]['agents'])->pluck('type'))
                ->unique();

            if ($others->isNotEmpty()) {
                $this->info("Типове само при {$label}: ".($mine->diff($others)->implode(', ') ?: '—'));
            }
        }

        $this->comment('Пълните планове (промптове, обосновки, цена) са в agent_generation_logs / панела „Лог на генерирането".');
    }
}
