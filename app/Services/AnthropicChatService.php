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
 *   num_predict (>0) → max_tokens (×OUTPUT_HEADROOM), num_predict -1 → generous default.
 */
class AnthropicChatService
{
    /**
     * num_predict бюджетите в планера/агентите са калибрирани по OpenAI/локални
     * токенизатори; Claude харчи ~3× повече токени за същия български текст,
     * затова капът получава headroom. Таванът остава под output лимита на
     * всички модерни Claude модели (opus 32K, sonnet/haiku 64K) и под
     * http_timeout 600s при ~50 tok/s.
     */
    private const OUTPUT_HEADROOM = 3;

    private const MAX_OUTPUT_TOKENS = 30000;

    private const DEFAULT_MAX_TOKENS = 16384;

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

        // Срязан свободен текст е деградация, не фатална грешка — само следа в лога.
        if ($response->json('stop_reason') === 'max_tokens') {
            Log::warning('[AnthropicChat] '.$model.' output truncated at max_tokens ('.$response->json('usage.output_tokens').').');
        }

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

        // При stop_reason=max_tokens API-то спасява от срязания tool input само
        // завършените полета — частичен резултат НИКОГА не се връща мълчаливо.
        if ($response->json('stop_reason') === 'max_tokens') {
            throw new \RuntimeException(
                'Anthropic отговорът е срязан на max_tokens ('.$response->json('usage.output_tokens')
                .') — структурираният резултат «'.$schemaName.'» е непълен. Увеличи бюджета (num_predict) или опрости заданието.'
            );
        }

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new \RuntimeException("Anthropic did not return a tool_use block for schema {$schemaName}.");
    }

    /**
     * One round of a multi-turn TOOL-CALLING conversation (Builder Copilot) —
     * same neutral message/tool shape as OpenAiChatService::chatTurn(), mapped
     * onto the Messages API (tool_use / tool_result content blocks).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @return array{content: string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>}
     */
    public function chatTurn(string $model, array $messages, array $tools, array $options = []): array
    {
        [$system, $anthropicMessages] = $this->toAnthropicMessages($messages);

        $numPredict = $options['num_predict'] ?? null;
        $payload = [
            'model' => $model,
            'system' => $system,
            'messages' => $anthropicMessages,
            'max_tokens' => (is_numeric($numPredict) && (int) $numPredict > 0) ? (int) $numPredict : self::DEFAULT_MAX_TOKENS,
            'temperature' => is_numeric($options['temperature'] ?? null) ? (float) $options['temperature'] : 0.3,
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $t) => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'input_schema' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass],
            ], $tools);
        }

        // Audit: the latest user/tool message is the most useful "user" text.
        $last = end($anthropicMessages) ?: null;
        $auditUser = is_array($last)
            ? (is_string($last['content']) ? $last['content'] : json_encode($last['content'], JSON_UNESCAPED_UNICODE))
            : '';

        $response = $this->send($payload, $options, 'chat_turn', $system, (string) $auditUser);

        $content = '';
        $toolCalls = [];
        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        return ['content' => $content, 'tool_calls' => $toolCalls];
    }

    /**
     * Neutral messages → Messages API: system messages collapse into the
     * top-level system string; assistant tool calls become tool_use blocks;
     * consecutive tool results merge into ONE user message (the API requires
     * alternating user/assistant turns).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function toAnthropicMessages(array $messages): array
    {
        $system = '';
        $out = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';

            if ($role === 'system') {
                $system .= ($system === '' ? '' : "\n\n").(string) $content;

                continue;
            }

            if ($role === 'tool') {
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content' => (string) $content,
                ];

                $lastIdx = count($out) - 1;
                if ($lastIdx >= 0
                    && $out[$lastIdx]['role'] === 'user'
                    && is_array($out[$lastIdx]['content'])
                    && (($out[$lastIdx]['content'][0]['type'] ?? '') === 'tool_result')) {
                    $out[$lastIdx]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                $blocks = [];
                if ((string) $content !== '') {
                    $blocks[] = ['type' => 'text', 'text' => (string) $content];
                }
                foreach ($message['tool_calls'] as $call) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => (string) $call['id'],
                        'name' => (string) $call['name'],
                        'input' => ($call['arguments'] ?? []) === [] ? new \stdClass : $call['arguments'],
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            $out[] = ['role' => $role, 'content' => (string) $content];
        }

        return [$system, $out];
    }

    /** @param array<string, mixed> $extra */
    private function post(string $model, string $systemPrompt, string $userMessage, array $options, array $extra = [], string $kind = 'chat'): Response
    {
        $numPredict = $options['num_predict'] ?? null;
        $maxTokens = (is_numeric($numPredict) && (int) $numPredict > 0)
            ? min((int) $numPredict * self::OUTPUT_HEADROOM, self::MAX_OUTPUT_TOKENS)
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

        return $this->send($payload, $options, $kind, $systemPrompt, $userMessage);
    }

    /**
     * The single HTTP + usage/audit chokepoint for every Messages API call.
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, array $options, string $kind, string $auditSystem, string $auditUser): Response
    {
        $startMs = (int) (microtime(true) * 1000);

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

        $model = (string) ($payload['model'] ?? '');

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
            $auditSystem,
            $auditUser,
            $responseText,
            array_intersect_key($payload, array_flip(['temperature', 'top_p', 'max_tokens'])),
            $promptTokens,
            $completionTokens,
            (int) (microtime(true) * 1000) - $startMs,
        );

        return $response;
    }
}
