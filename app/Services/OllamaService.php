<?php

namespace App\Services;

use App\Support\LlmRequestRecorder;
use App\Support\LlmUsage;
use App\Support\PaidModel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OllamaService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.ollama.url', 'http://localhost:11434');
    }

    public function chat(string $model, string $systemPrompt, string $userMessage, array $options = [], ?callable $onProgress = null): string
    {
        // Hybrid execution: "openai/<model>" and "anthropic/<model>" agents run
        // on their paid provider. Routing lives here — the single funnel every
        // BaseAgent chat() call goes through — so none of the concrete agent
        // classes know about providers.
        if ($service = $this->paidService($model)) {
            return $service->chat(
                PaidModel::strip($model),
                $systemPrompt,
                $userMessage,
                $options,
                $onProgress,
            );
        }

        $keepAlive = $options['keep_alive'] ?? '10m';
        $httpTimeout = $options['http_timeout'] ?? 600; // caller can set a shorter timeout
        unset($options['keep_alive'], $options['http_timeout']);

        // Ollama structured outputs: "format" is a TOP-LEVEL payload field — a
        // full JSON schema (constrained decoding). Used by the local planner.
        $format = $options['format'] ?? null;
        unset($options['format']);

        // Cost/usage audit (llm_requests): a structured (format) call is the
        // planner; a plain call is runtime. Token counts arrive in the final chunk.
        $kind = $format !== null ? 'chat_json' : 'chat';
        $startMs = (int) (microtime(true) * 1000);
        $promptTokens = 0;
        $completionTokens = 0;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMessage],
            ],
            'stream' => true,   // Ollama API: emit NDJSON chunks as tokens are generated
            'keep_alive' => $keepAlive,
            'options' => array_merge(['temperature' => 0.7], $options),
        ];

        if ($format !== null) {
            $payload['format'] = $format;
        }

        // Use stream:true so Ollama sends tokens immediately (NDJSON).
        // Without streaming, Ollama buffers the entire response before sending ANY bytes,
        // causing "0 bytes received" timeouts on long synthesis responses.
        $response = Http::withOptions(['stream' => true])  // Guzzle: don't buffer the body
            ->timeout($httpTimeout)                         // safety net — matches job timeout
            ->post($this->baseUrl.'/api/chat', $payload);

        $response->throw();

        // Accumulate NDJSON chunks into a single string
        $content = '';
        $buffer = '';
        $body = $response->getBody();
        $lastProgressAt = 0.0;
        $reportProgress = function () use ($onProgress, &$lastProgressAt): void {
            if (! $onProgress) {
                return;
            }

            $now = microtime(true);
            if ($lastProgressAt > 0 && ($now - $lastProgressAt) < 2) {
                return;
            }

            $lastProgressAt = $now;
            $onProgress();
        };

        // Enforce a total-stream deadline. Guzzle's ->timeout() only covers the
        // initial connection/first-byte in streaming mode; the read loop itself
        // can hang forever. We break out once the deadline is reached.
        $streamDeadline = microtime(true) + $httpTimeout;

        while (! $body->eof()) {
            if (microtime(true) > $streamDeadline) {
                break; // deadline exceeded — return whatever was accumulated
            }
            $chunkData = $body->read(8192);
            if ($chunkData !== '') {
                $reportProgress();
                $buffer .= $chunkData;
            }

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $chunk = json_decode($line, true);
                if (is_array($chunk)) {
                    if (isset($chunk['message']['content'])) {
                        $content .= $chunk['message']['content'];
                    }
                    if (isset($chunk['prompt_eval_count'])) {
                        $promptTokens = (int) $chunk['prompt_eval_count'];
                    }
                    if (isset($chunk['eval_count'])) {
                        $completionTokens = (int) $chunk['eval_count'];
                    }
                }
            }
        }

        // Flush any remainder left in the buffer after EOF
        if ($line = trim($buffer)) {
            $chunk = json_decode($line, true);
            if (is_array($chunk)) {
                if (isset($chunk['message']['content'])) {
                    $content .= $chunk['message']['content'];
                }
                if (isset($chunk['prompt_eval_count'])) {
                    $promptTokens = (int) $chunk['prompt_eval_count'];
                }
                if (isset($chunk['eval_count'])) {
                    $completionTokens = (int) $chunk['eval_count'];
                }
            }
        }

        // Audit this local call (provider "ollama", cost 0) for /admin/costs.
        LlmUsage::record('ollama', $model, $promptTokens, $completionTokens);
        LlmRequestRecorder::record(
            'ollama',
            $model,
            $kind,
            $systemPrompt,
            $userMessage,
            $content,
            array_intersect_key($options, array_flip(['temperature', 'top_p', 'num_predict', 'num_ctx', 'repeat_penalty'])),
            $promptTokens,
            $completionTokens,
            (int) (microtime(true) * 1000) - $startMs,
        );

        return $content;
    }

    /**
     * Run many short chat completions CONCURRENTLY (non-streaming).
     *
     * Used by the map-reduce researcher to summarize many pages at once. Each
     * request is independent; failures yield '' for that key without aborting the
     * batch. Requests are sent in waves of $concurrency via Guzzle's HTTP pool.
     *
     * @param  array<int|string, array{model:string,system:string,user:string,options?:array}>  $requests
     * @return array<int|string, string> same keys → message content ('' on failure)
     */
    public function chatBatch(array $requests, int $concurrency = 4, int $httpTimeout = 90): array
    {
        $concurrency = max(1, $concurrency);
        $results = [];

        // Route any paid-provider requests (openai/* or anthropic/*) to their
        // API (sequentially — these are rare planner-pinned steps), keep the
        // rest for the local Ollama pool.
        $ollamaRequests = [];
        foreach ($requests as $key => $req) {
            if ($service = $this->paidService($req['model'] ?? null)) {
                try {
                    $results[$key] = $service->chat(
                        PaidModel::strip($req['model']),
                        $req['system'] ?? '',
                        $req['user'] ?? '',
                        array_merge($req['options'] ?? [], ['http_timeout' => $httpTimeout]),
                    );
                } catch (\Throwable) {
                    $results[$key] = '';
                }
            } else {
                $ollamaRequests[$key] = $req;
            }
        }
        $requests = $ollamaRequests;

        foreach (array_chunk($requests, $concurrency, true) as $wave) {
            $responses = Http::pool(function ($pool) use ($wave, $httpTimeout) {
                $calls = [];
                foreach ($wave as $key => $req) {
                    $options = array_merge(['temperature' => 0.7], $req['options'] ?? []);
                    $calls[] = $pool->as((string) $key)
                        ->timeout($httpTimeout)
                        ->post($this->baseUrl.'/api/chat', [
                            'model' => $req['model'],
                            'messages' => [
                                ['role' => 'system', 'content' => $req['system'] ?? ''],
                                ['role' => 'user',   'content' => $req['user'] ?? ''],
                            ],
                            'stream' => false,
                            'keep_alive' => '10m',
                            'options' => $options,
                        ]);
                }

                return $calls;
            });

            foreach ($wave as $key => $req) {
                $resp = $responses[(string) $key] ?? null;
                $content = '';
                $pt = 0;
                $ct = 0;
                try {
                    if ($resp instanceof Response && $resp->successful()) {
                        $content = (string) ($resp->json('message.content') ?? '');
                        $pt = (int) ($resp->json('prompt_eval_count') ?? 0);
                        $ct = (int) ($resp->json('eval_count') ?? 0);
                    }
                } catch (\Throwable) {
                    $content = '';
                }
                $results[$key] = $content;

                if ($content !== '') {
                    LlmUsage::record('ollama', (string) $req['model'], $pt, $ct);
                    LlmRequestRecorder::record(
                        'ollama',
                        (string) $req['model'],
                        'chat',
                        $req['system'] ?? '',
                        $req['user'] ?? '',
                        $content,
                        array_intersect_key($req['options'] ?? [], array_flip(['temperature', 'top_p', 'num_predict'])),
                        $pt,
                        $ct,
                        0,
                    );
                }
            }
        }

        return $results;
    }

    /** The paid-provider chat service for a prefixed model, or null for local models. */
    private function paidService(?string $model): OpenAiChatService|AnthropicChatService|null
    {
        return match (PaidModel::provider($model)) {
            'openai' => app(OpenAiChatService::class),
            'anthropic' => app(AnthropicChatService::class),
            default => null,
        };
    }

    public function listModels(): array
    {
        $response = Http::timeout(10)->get($this->baseUrl.'/api/tags');

        if ($response->failed()) {
            return [];
        }

        return $response->json('models', []);
    }

    public function pull(string $tag): bool
    {
        try {
            // stream:false waits for the full pull to complete (can take minutes for large models)
            $response = Http::timeout(600)->post($this->baseUrl.'/api/pull', [
                'name' => $tag,
                'stream' => false,
            ]);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    public function isAvailable(): bool
    {
        try {
            Http::timeout(3)->get($this->baseUrl.'/api/tags')->throw();

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
