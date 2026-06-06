<?php

namespace App\Console\Commands;

use App\Models\AgentGenerationLog;
use App\Models\Flow;
use App\Services\AgentGeneratorService;
use App\Services\FlowPlannerService;
use App\Services\OllamaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * A/B(/C) на planner провайдърите: планира един и същ flow с OpenAI,
 * Anthropic и/или локалния Ollama planner. Две лица:
 *
 *  - CLI:  php artisan flows:plan-ab 25                       → всички налични
 *          php artisan flows:plan-ab 25 --provider=ollama     → само един
 *  - UI:   ... --token=X [--provider=Y] → резултатите отиват в cache
 *          (poll-ва ги страницата „A/B сравнение" на flow-а)
 *
 * Всяка planner фаза се логва в agent_generation_logs (с цената), така че
 * пълните планове са видими и в панела „Лог на генерирането".
 */
class PlanAbCommand extends Command
{
    public const PROVIDERS = ['ollama', 'openai', 'anthropic'];

    protected $signature = 'flows:plan-ab
        {flow : Flow ID}
        {--token= : Cache token for UI polling}
        {--provider= : Plan with a single provider (ollama|openai|anthropic)}';

    protected $description = 'Plan the same flow with OpenAI, Anthropic and/or local Ollama and compare the resulting pipelines';

    public function handle(FlowPlannerService $planner, AgentGeneratorService $generator): int
    {
        $flow = Flow::with('company')->find($this->argument('flow'));
        $token = (string) $this->option('token');

        if (! $flow) {
            $this->error('Flow не е намерен.');
            $this->putState($token, ['status' => 'failed', 'error' => 'Flow не е намерен.']);

            return self::FAILURE;
        }

        $single = (string) $this->option('provider');
        $providers = $single !== '' ? [$single] : self::PROVIDERS;

        $plans = [];
        $state = ['status' => 'running', 'flow_id' => $flow->id, 'providers' => []];

        foreach ($providers as $provider) {
            if (! in_array($provider, self::PROVIDERS, true)) {
                $this->warn("⤬ {$provider}: непознат provider — пропускам.");

                continue;
            }

            if (! $this->providerAvailable($provider)) {
                $reason = $provider === 'ollama' ? 'Ollama сървърът не отговаря.' : 'Липсва API ключ в .env.';
                $this->warn("⤬ {$provider}: {$reason}");
                $state['providers'][$provider] = ['status' => 'skipped', 'error' => $reason];
                $this->putState($token, $state);

                continue;
            }

            Config::set('services.generator.provider', $provider);
            $this->info("▶ Планиране с {$provider}…");
            $state['providers'][$provider] = ['status' => 'running'];
            $this->putState($token, $state);

            $logToken = ($token ?: 'ab').'-'.$provider;
            $startMs = (int) (microtime(true) * 1000);

            try {
                $agents = $planner->plan($flow, null, $logToken);

                if (count($agents) < 3) {
                    throw new \RuntimeException('Planner върна по-малко от 3 агента.');
                }

                // Same deterministic hardening as normal generation — the chosen
                // plan must be byte-equivalent to what the builder would build.
                $agents = $generator->finalizePlannedAgents($agents);
            } catch (Throwable $e) {
                $this->error("✗ {$provider}: ".$e->getMessage());
                $state['providers'][$provider] = ['status' => 'failed', 'error' => $e->getMessage()];
                $this->putState($token, $state);

                continue;
            }

            $result = [
                'status' => 'completed',
                'agents' => $agents,
                'intent' => $planner->lastIntent(),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                'cost_usd' => round((float) AgentGenerationLog::where('token', $logToken)->sum('cost_usd'), 4),
                'model' => $this->providerModelLabel($provider),
            ];

            $plans[$provider] = $result;
            $state['providers'][$provider] = $result;
            $this->putState($token, $state);
        }

        $state['status'] = 'completed';
        $this->putState($token, $state);

        $this->summarize($plans);

        return $plans === [] ? self::FAILURE : self::SUCCESS;
    }

    private function providerAvailable(string $provider): bool
    {
        return $provider === 'ollama'
            ? app(OllamaService::class)->isAvailable()
            : ! empty(config("services.{$provider}.api_key"));
    }

    private function providerModelLabel(string $provider): string
    {
        return match ($provider) {
            'ollama' => (string) config('services.ollama.planner_model'),
            default => (string) config("services.{$provider}.model"),
        };
    }

    private function putState(string $token, array $state): void
    {
        if ($token !== '') {
            Cache::put("plan_ab_{$token}", $state, now()->addMinutes(30));
        }
    }

    /** CLI summary: per-provider stats + pairwise type differences. */
    private function summarize(array $plans): void
    {
        if ($plans === []) {
            $this->warn('Нито един provider не завърши успешно.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Provider', 'Агенти', 'Време', 'Цена', 'Топология'],
            collect($plans)->map(fn ($p, $provider) => [
                $provider,
                count($p['agents']),
                round($p['duration_ms'] / 1000, 1).'s',
                $p['cost_usd'] > 0 ? '$'.$p['cost_usd'] : 'безплатно',
                collect($p['agents'])->map(fn ($a) => $a['uid'])->implode(' → '),
            ])->values()->all(),
        );

        $providers = array_keys($plans);
        foreach ($providers as $provider) {
            $mine = collect($plans[$provider]['agents'])->pluck('type')->unique();
            $others = collect($providers)
                ->reject(fn ($p) => $p === $provider)
                ->flatMap(fn ($p) => collect($plans[$p]['agents'])->pluck('type'))
                ->unique();

            if ($others->isNotEmpty()) {
                $this->info("Типове само при {$provider}: ".($mine->diff($others)->implode(', ') ?: '—'));
            }
        }

        $this->comment('Пълните планове (промптове, обосновки, цена) са в agent_generation_logs / панела „Лог на генерирането".');
    }
}
