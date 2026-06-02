<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Flow;
use App\Services\AgentGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateAgentsCommand extends Command
{
    protected $signature   = 'flows:generate-agents {token : Cache token for this generation request}';
    protected $description = 'Run agent generation in the background and store result in cache';

    public function handle(AgentGeneratorService $generator): int
    {
        $token = $this->argument('token');
        $cacheKey = "agent_gen_{$token}";

        // Read request data from cache
        $request = Cache::get("agent_gen_request_{$token}");
        if (!$request) {
            Log::error("[GenerateAgents] Token not found in cache: {$token}");
            return Command::FAILURE;
        }

        Log::info("[GenerateAgents] Starting for token {$token}, company {$request['company_id']}");

        try {
            $company = Company::findOrFail($request['company_id']);

            $flow             = new Flow([
                'name'        => $request['name'],
                'description' => $request['description'],
            ]);
            $flow->company_id = $company->id;
            $flow->setRelation('company', $company);

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

            $agents = $generator->generate($flow, $onProgress);

            if (empty($agents)) {
                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'error'  => 'AI не върна валидни агенти. Опитай с по-подробно описание.',
                    'agents' => [],
                    'stage' => 'Генерацията се провали',
                    'updated_at' => now()->timestamp,
                ], now()->addMinutes(10));

                return Command::FAILURE;
            }

            Cache::put($cacheKey, [
                'status' => 'completed',
                'agents' => $agents,
                'error'  => null,
                'stage' => 'Готово',
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(10));

            Log::info("[GenerateAgents] Done — {$token}: " . count($agents) . " agents");

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            Log::error("[GenerateAgents] Failed {$token}: " . $e->getMessage());

            Cache::put($cacheKey, [
                'status' => 'failed',
                'error'  => $e->getMessage(),
                'agents' => [],
                'stage' => 'Генерацията се провали',
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(10));

            return Command::FAILURE;
        }
    }
}
