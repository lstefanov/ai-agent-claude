<?php

namespace App\Services;

use App\Support\LlmUsage;
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
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $schemaName],
        ]);

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new \RuntimeException("Anthropic did not return a tool_use block for schema {$schemaName}.");
    }

    /** @param array<string, mixed> $extra */
    private function post(string $model, string $systemPrompt, string $userMessage, array $options, array $extra = []): Response
    {
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

        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.api_key'),
            'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
        ])
            ->timeout((int) ($options['http_timeout'] ?? 600))
            ->post(rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/').'/v1/messages', $payload)
            ->throw();

        LlmUsage::record(
            'anthropic',
            $model,
            (int) ($response->json('usage.input_tokens') ?? 0),
            (int) ($response->json('usage.output_tokens') ?? 0),
        );

        return $response;
    }
}
