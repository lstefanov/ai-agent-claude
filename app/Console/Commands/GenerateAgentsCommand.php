<?php

namespace App\Console\Commands;

use App\Models\AgentGenerationLog;
use App\Models\Company;
use App\Models\Flow;
use App\Models\FlowDraft;
use App\Services\AgentGeneratorService;
use App\Services\FlowVersionService;
use App\Services\GeneratorService;
use App\Support\ModelLevel;
use App\Support\PlannerPhases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class GenerateAgentsCommand extends Command
{
    protected $signature = 'flows:generate-agents {token : Cache token for this generation request}';

    protected $description = 'Run agent generation in the background and store result in cache';

    public function handle(AgentGeneratorService $generator, FlowVersionService $versions): int
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

            // Model-cost level for the agents' runtime models (popup choice).
            $level = ModelLevel::fromRequest($request['level'] ?? null);

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
            $agents = $generator->generate($flow, $onProgress, $token, $level, (bool) ($request['minimal_qa'] ?? false));

            if (empty($agents)) {
                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'error' => 'Планиращият модел върна непълен план. Опитай отново или смени провайдъра за фазата «дизайн».',
                    'agents' => [],
                    'stage' => 'Генерацията се провали',
                    'updated_at' => now()->timestamp,
                ], now()->addMinutes(10));

                return Command::FAILURE;
            }

            $generatorMeta = [
                'label' => PlannerPhases::label($effectivePhases),
                'phases' => $effectivePhases,
            ];
            $costUsd = round((float) AgentGenerationLog::where('token', $token)->sum('cost_usd'), 4);
            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            // Клиентският wizard няма builder — там фоновата команда сама записва
            // активната версия (иначе flow-ът остава без агенти/шаблон). Записваме
            // ПРЕДИ 'completed' кеша, за да не редиректне поллерът към празен flow.
            if ((bool) ($request['persist'] ?? false)) {
                try {
                    $versions->createFromAgents($flow, $agents, 'Основен план', isActive: true, meta: [
                        'intent' => $generator->lastIntent(),
                        'generator' => $generatorMeta,
                        'model_level' => $level->value,
                        'cost_usd' => $costUsd,
                        'duration_ms' => $durationMs,
                    ]);

                    // Scoped update — не записва in-memory description override-а.
                    Flow::whereKey($flow->id)->update(['status' => 'active']);

                    if (! empty($request['draft_id'])) {
                        FlowDraft::whereKey((int) $request['draft_id'])->update(['status' => 'completed']);
                    }
                } catch (\Throwable $e) {
                    Log::error("[GenerateAgents] Persist failed {$token}: ".$e->getMessage(), ['exception' => $e]);

                    Cache::put($cacheKey, [
                        'status' => 'failed',
                        'error' => 'Планът се генерира, но записът се провали. Опитай отново.',
                        'agents' => [],
                        'stage' => 'Записът се провали',
                        'updated_at' => now()->timestamp,
                    ], now()->addMinutes(10));

                    return Command::FAILURE;
                }
            }

            // The builder's save-as-template dialog needs the generation meta:
            // intent (plan library pairing), generator label/phases, cost, time.
            Cache::put($cacheKey, [
                'status' => 'completed',
                'agents' => $agents,
                'intent' => $generator->lastIntent(),
                'generator' => $generatorMeta,
                'level' => $level->value,
                'cost_usd' => $costUsd,
                'duration_ms' => $durationMs,
                'error' => null,
                'stage' => 'Готово',
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(10));

            Log::info("[GenerateAgents] Done — {$token}: ".count($agents).' agents'.((bool) ($request['persist'] ?? false) ? ' (persisted)' : ''));

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            Log::error("[GenerateAgents] Failed {$token}: ".$e->getMessage(), ['exception' => $e]);

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
