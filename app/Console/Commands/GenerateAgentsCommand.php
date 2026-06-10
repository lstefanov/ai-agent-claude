<?php

namespace App\Console\Commands;

use App\Models\AgentGenerationLog;
use App\Models\Company;
use App\Models\Flow;
use App\Services\AgentGeneratorService;
use App\Services\GeneratorService;
use App\Support\PlannerPhases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class GenerateAgentsCommand extends Command
{
    protected $signature = 'flows:generate-agents {token : Cache token for this generation request}';

    protected $description = 'Run agent generation in the background and store result in cache';

    public function handle(AgentGeneratorService $generator): int
    {
        $token = $this->argument('token');
        $cacheKey = "agent_gen_{$token}";

        // Read request data from cache
        $request = Cache::get("agent_gen_request_{$token}");
        if (! $request) {
            Log::error("[GenerateAgents] Token not found in cache: {$token}");

            return Command::FAILURE;
        }

        Log::info("[GenerateAgents] Starting for token {$token}, company {$request['company_id']}");

        try {
            $company = Company::findOrFail($request['company_id']);

            // The flow exists (generation always starts from its builder). The
            // description may differ from the saved one while the user iterates
            // in the popup — plan against the freshly typed text.
            $flow = Flow::findOrFail($request['flow_id']);
            $flow->description = $request['description'];
            $flow->setRelation('company', $company);

            // Per-phase provider/model from the builder's generation popup.
            // Every phase is set EXPLICITLY (same rule as PlanAbCommand) so
            // .env per-phase settings cannot leak into a user-chosen combo.
            $requestedPhases = (array) ($request['phases'] ?? []);
            if ($requestedPhases !== []) {
                $default = (string) config('services.generator.provider', 'openai');
                foreach (PlannerPhases::PHASES as $phase) {
                    Config::set(
                        "services.planner.phases.{$phase}",
                        $requestedPhases[$phase] ?? ['provider' => $default, 'model' => null],
                    );
                }
            }
            $effectivePhases = app(GeneratorService::class)->resolveAllPhases();

            $lastHeartbeatAt = 0.0;
            $onProgress = function (?string $stage = null) use ($cacheKey, &$lastHeartbeatAt): void {
                $now = microtime(true);
                $current = Cache::get($cacheKey, [
                    'status' => 'pending',
                    'agents' => [],
                    'error' => null,
                ]);
                $nextStage = $stage ?: ($current['stage'] ?? 'Генериране...');

                if ($lastHeartbeatAt > 0 && ($now - $lastHeartbeatAt) < 2 && $nextStage === ($current['stage'] ?? null)) {
                    return;
                }

                $lastHeartbeatAt = $now;

                $current['status'] = 'pending';
                $current['stage'] = $nextStage;
                $current['updated_at'] = now()->timestamp;

                Cache::put($cacheKey, $current, now()->addMinutes(15));
            };

            $onProgress('Подготовка на заявката');

            $startMs = (int) (microtime(true) * 1000);
            $agents = $generator->generate($flow, $onProgress, $token);

            if (empty($agents)) {
                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'error' => 'AI не върна валидни агенти. Опитай с по-подробно описание.',
                    'agents' => [],
                    'stage' => 'Генерацията се провали',
                    'updated_at' => now()->timestamp,
                ], now()->addMinutes(10));

                return Command::FAILURE;
            }

            // The builder's save-as-template dialog needs the generation meta:
            // intent (plan library pairing), generator label/phases, cost, time.
            Cache::put($cacheKey, [
                'status' => 'completed',
                'agents' => $agents,
                'intent' => $generator->lastIntent(),
                'generator' => [
                    'label' => PlannerPhases::label($effectivePhases),
                    'phases' => $effectivePhases,
                ],
                'cost_usd' => round((float) AgentGenerationLog::where('token', $token)->sum('cost_usd'), 4),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                'error' => null,
                'stage' => 'Готово',
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(10));

            Log::info("[GenerateAgents] Done — {$token}: ".count($agents).' agents');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            Log::error("[GenerateAgents] Failed {$token}: ".$e->getMessage());

            Cache::put($cacheKey, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'agents' => [],
                'stage' => 'Генерацията се провали',
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(10));

            return Command::FAILURE;
        }
    }
}
