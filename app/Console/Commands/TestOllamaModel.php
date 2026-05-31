<?php

namespace App\Console\Commands;

use App\Models\LlmModel;
use App\Services\OllamaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TestOllamaModel extends Command
{
    protected $signature = 'models:test {model : The LLM model id to test}';

    protected $description = 'Test an Ollama model in the background and store the result in cache';

    public function handle(OllamaService $ollama): int
    {
        $model = LlmModel::find($this->argument('model'));

        if (! $model) {
            $this->error('Model not found.');

            return Command::FAILURE;
        }

        $cacheKey = "llm_model_test_{$model->id}";
        $startedAt = microtime(true);

        Cache::put($cacheKey, [
            'status' => 'testing',
            'success' => null,
            'response' => null,
            'error' => null,
            'elapsed_ms' => null,
        ], now()->addMinutes(10));

        try {
            $response = $ollama->chat(
                model: $model->ollama_tag,
                systemPrompt: 'You are a test assistant. Reply with one very short sentence.',
                userMessage: 'Отговори само с: OK - '.$model->display_name.' работи.',
                options: ['temperature' => 0, 'num_predict' => 16]
            );

            $elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);

            Cache::put($cacheKey, [
                'status' => 'completed',
                'success' => true,
                'response' => trim($response),
                'error' => null,
                'elapsed_ms' => $elapsedMs,
            ], now()->addMinutes(10));

            Log::info('[LlmModelTest] Success', [
                'model' => $model->ollama_tag,
                'elapsed_ms' => $elapsedMs,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'success' => false,
                'response' => $e->getMessage(),
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ], now()->addMinutes(10));

            Log::warning('[LlmModelTest] Failed', [
                'model' => $model->ollama_tag,
                'elapsed_ms' => $elapsedMs,
                'error' => $e->getMessage(),
            ]);

            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
