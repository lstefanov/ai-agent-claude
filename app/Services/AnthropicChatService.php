<?php

namespace App\Services;

use App\Support\LlmRequestRecorder;
use App\Support\LlmUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chat client for Anthropic (Claude).
 *
 * Two consumers:
 *  - runtime: agents whose model is "anthropic/<model>" (OllamaService::chat()
 *    delegates here — the concrete agent classes stay provider-agnostic);
 *  - planning: GeneratorService delegates both plain chat and structured JSON
 *    (forced tool call — the schema becomes the tool's input_schema, so the
 *    returned tool_use input is schema-shaped without any text parsing).
 *
 * Ollama-style options map onto the Messages API:
 *   temperature → temperature, top_p → top_p,
 *   num_predict (>0) → max_tokens, num_predict -1 → generous default.
 */
class AnthropicChatService
{
    private const DEFAULT_MAX_TOKENS = 8192;

    public function chat(
        string $model,
        string $systemPrompt,
        string $userMessage,
        array $options = [],
        ?callable $onProgress = null
    ): string {
        if ($onProgress) {
            $onProgress();
        }

        $response = $this->post($model, $systemPrompt, $userMessage, $options);

        $content = '';
        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            }
        }

        Log::info('[AnthropicChat] '.$model.' response length: '.strlen($content));

        return $content;
    }

    /**
     * Structured output via a FORCED tool call — guaranteed schema-shaped JSON.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function chatJson(
        string $model,
        string $systemPrompt,
        string $userMessage,
        string $schemaName,
        array $schema,
        array $options = []
    ): array {
        $response = $this->post($model, $systemPrompt, $userMessage, $options, [
            'tools' => [[
                'name' => $schemaName,
                'description' => 'Върни резултата СТРИКТНО по схемата на този инструмент.',
                // Strict tool use: the API validates the input against the
                // schema, so nested arrays can't come back double-encoded
                // as JSON strings.
                'strict' => true,
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $schemaName],
        ], 'chat_json');

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new \RuntimeException("Anthropic did not return a tool_use block for schema {$schemaName}.");
    }

    /** @param array<string, mixed> $extra */
    private function post(string $model, string $systemPrompt, string $userMessage, array $options, array $extra = [], string $kind = 'chat'): Response
    {
        $startMs = (int) (microtime(true) * 1000);
        $numPredict = $options['num_predict'] ?? null;
        $maxTokens = (is_numeric($numPredict) && (int) $numPredict > 0)
            ? (int) $numPredict
            : self::DEFAULT_MAX_TOKENS;

        $payload = array_merge([
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => is_numeric($options['temperature'] ?? null) ? (float) $options['temperature'] : 0.7,
        ], $extra);

        if (isset($options['top_p']) && is_numeric($options['top_p'])) {
            $payload['top_p'] = (float) $options['top_p'];
        }

        // Transient failures (529 overloaded, rate-limit 429s, 5xx, network
        // blips) get 3 retries with growing backoff; client errors fail fast.
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.api_key'),
            'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
        ])
            ->timeout((int) ($options['http_timeout'] ?? 600))
            ->retry([2000, 5000, 12000], when: function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 500, 502, 503, 504, 529], true);
            })
            ->post(rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/').'/v1/messages', $payload)
            ->throw();

        $promptTokens = (int) ($response->json('usage.input_tokens') ?? 0);
        $completionTokens = (int) ($response->json('usage.output_tokens') ?? 0);

        LlmUsage::record('anthropic', $model, $promptTokens, $completionTokens);

        $responseText = '';
        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $responseText .= $block['text'] ?? '';
            } elseif (($block['type'] ?? '') === 'tool_use' && is_array($block['input'] ?? null)) {
                $responseText .= json_encode($block['input'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }

        LlmRequestRecorder::record(
            'anthropic',
            $model,
            $kind,
            $systemPrompt,
            $userMessage,
            $responseText,
            array_intersect_key($payload, array_flip(['temperature', 'top_p', 'max_tokens'])),
            $promptTokens,
            $completionTokens,
            (int) (microtime(true) * 1000) - $startMs,
        );

        return $response;
    }
}
