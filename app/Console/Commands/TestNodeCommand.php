<?php

namespace App\Console\Commands;

use App\Services\OllamaService;
use App\Support\LlmUsage;
use App\Support\ReasoningStripper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TestNodeCommand extends Command
{
    protected $signature = 'flows:test-node {token : Cache token for this ad-hoc node test}';

    protected $description = 'Run an ad-hoc agent test (pure LLM chat, nothing persisted) and store the result in cache';

    public function handle(OllamaService $ollama): int
    {
        $token = $this->argument('token');
        $cacheKey = "node_test_{$token}";

        $request = Cache::get("node_test_request_{$token}");
        if (! $request) {
            Log::error("[TestNode] Token not found in cache: {$token}");

            return Command::FAILURE;
        }

        Log::info("[TestNode] Starting {$token}: run {$request['flow_run_id']}, node {$request['node_key']}, model {$request['model']}");

        Cache::put($cacheKey, [
            'status' => 'running',
            'output' => null,
            'error' => null,
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(60));

        $startMs = (int) (microtime(true) * 1000);

        try {
            $options = (array) ($request['options'] ?? []);
            $options['http_timeout'] = 900; // local models can take minutes

            // OllamaService routes paid-prefixed models (openai/…, anthropic/…)
            // to the matching cloud service and records usage in LlmUsage.
            $raw = $ollama->chat(
                $request['model'],
                (string) $request['system_prompt'],
                (string) $request['user_message'],
                $options,
            );

            $output = ReasoningStripper::strip($raw);
            $usage = LlmUsage::take();

            Cache::put($cacheKey, array_merge($usage, [
                'status' => 'completed',
                'output' => $output,
                // Thinking models with a small num_predict can burn the whole
                // budget inside <think> — surface the raw text so the test
                // popup can show what actually came back.
                'raw_output' => $raw !== $output ? $raw : null,
                'model' => $request['model'],
                'tokens_used' => (($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0)) ?: null,
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                'error' => null,
                'updated_at' => now()->timestamp,
            ]), now()->addMinutes(60));

            Log::info("[TestNode] Done {$token}: ".mb_strlen($output).' chars');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            LlmUsage::take(); // discard the partial accumulation

            Log::error("[TestNode] Failed {$token}: ".$e->getMessage(), ['exception' => $e]);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'output' => null,
                'error' => $e->getMessage(),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(60));

            return Command::FAILURE;
        }
    }
}
