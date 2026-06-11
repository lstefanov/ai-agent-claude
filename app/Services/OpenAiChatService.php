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
 * Chat client for OpenAI-compatible APIs (OpenAI, DeepSeek, Gemini).
 *
 * One class serves every provider that speaks the Chat Completions dialect —
 * the provider name selects a config block (services.{provider}) that holds
 * api_key, base_url (INCLUDING the version path, e.g. https://api.openai.com/v1),
 * structured-output capability and pricing. Use ::for('deepseek') etc.;
 * app(OpenAiChatService::class) stays the plain OpenAI instance.
 *
 * Consumers:
 *  - runtime: agents whose model is "openai/<model>" (OllamaService::chat()
 *    delegates here, so the concrete agent classes stay provider-agnostic);
 *  - planning: GeneratorService::chatJson() for the FlowPlannerService phases.
 *
 * Structured outputs per provider (services.{provider}.structured_output):
 *  - json_schema — native Structured Outputs (response cannot violate the schema);
 *  - json_object — JSON mode only (DeepSeek): the schema is appended to the
 *    system prompt and the top-level required keys are validated after decode.
 *    Deeper guarantees stay in AgentGeneratorService (planner proposes, code
 *    guarantees).
 *
 * Ollama-style options map onto Chat Completions:
 *   temperature → temperature, top_p → top_p,
 *   num_predict (>0) → max_completion_tokens/max_tokens, num_predict -1 → no cap.
 * num_ctx / top_k / repeat_penalty / seed have no equivalent and are ignored.
 */
class OpenAiChatService
{
    public function __construct(
        private readonly string $provider = 'openai',
    ) {}

    public static function for(string $provider): self
    {
        return new self($provider);
    }

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

        $response = $this->post(
            $this->buildPayload($model, $systemPrompt, $userMessage, $options),
            (int) ($options['http_timeout'] ?? 600),
        );

        $content = (string) ($response->json('choices.0.message.content') ?? '');

        Log::info('[OpenAiChat:'.$this->provider.'] '.$model.' response length: '.strlen($content));

        return $content;
    }

    /**
     * Structured JSON call. With json_schema capability the model is hard-
     * constrained to the schema; with json_object the schema travels in the
     * system prompt and the top-level required keys are validated here.
     *
     * @param  array<string, mixed>  $schema  Full JSON Schema for the response object.
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
        $mode = (string) $this->cfg('structured_output', 'json_schema');

        if ($mode === 'json_object') {
            $systemPrompt .= "\n\nОтговори САМО с валиден JSON обект, който стриктно следва тази JSON Schema (без markdown, без обяснения):\n"
                .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $payload = $this->buildPayload($model, $systemPrompt, $userMessage, $options);
        $payload['response_format'] = $mode === 'json_object'
            ? ['type' => 'json_object']
            : [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'schema' => $schema,
                    'strict' => true,
                ],
            ];

        $response = $this->post($payload, (int) ($options['http_timeout'] ?? 600), 'chat_json');

        // A safety refusal comes back as a separate field — surface it clearly.
        if ($refusal = $response->json('choices.0.message.refusal')) {
            throw new \RuntimeException(ucfirst($this->provider).' refused the structured request: '.$refusal);
        }

        $raw = (string) ($response->json('choices.0.message.content') ?? '');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException(ucfirst($this->provider).' structured output was not valid JSON ('.json_last_error_msg().').');
        }

        if ($mode === 'json_object') {
            $missing = array_diff((array) ($schema['required'] ?? []), array_keys($decoded));
            if ($missing !== []) {
                throw new \RuntimeException(sprintf(
                    '%s JSON output is missing required keys for schema %s: %s.',
                    ucfirst($this->provider),
                    $schemaName,
                    implode(', ', $missing),
                ));
            }
        }

        return $decoded;
    }

    /**
     * One round of a multi-turn TOOL-CALLING conversation (Builder Copilot).
     * The caller owns the agentic loop; this method does exactly one request.
     *
     * $messages use the provider-neutral shape (see BuilderAssistantService):
     *   role system|user|assistant|tool, content ?string,
     *   tool_calls (assistant): list of {id, name, arguments: array},
     *   tool_call_id (tool).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @return array{content: string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>}
     */
    public function chatTurn(string $model, array $messages, array $tools, array $options = []): array
    {
        $payload = [
            'model' => $model,
            'messages' => array_map($this->toChatCompletionMessage(...), $messages),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $t) => [
                'type' => 'function',
                'function' => [
                    'name' => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
                ],
            ], $tools);
        }

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $numPredict = $options['num_predict'] ?? null;
        if (is_numeric($numPredict) && (int) $numPredict > 0) {
            $cap = (int) $this->cfg('max_output_cap', 0);
            $payload[(string) $this->cfg('max_tokens_param', 'max_completion_tokens')] =
                $cap > 0 ? min((int) $numPredict, $cap) : (int) $numPredict;
        }

        $response = $this->post($payload, (int) ($options['http_timeout'] ?? 180), 'chat_turn');

        $message = (array) ($response->json('choices.0.message') ?? []);

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $toolCalls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : [],
                // The provider's verbatim tool_call object, replayed as-is on
                // the next round: Gemini 3 rejects a replay that drops its
                // thought_signature (extra_content), so reconstructing the
                // object from id/name/arguments is not an option.
                'raw' => $call,
            ];
        }

        return [
            'content' => (string) ($message['content'] ?? ''),
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Neutral message → Chat Completions dialect.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function toChatCompletionMessage(array $message): array
    {
        $role = (string) ($message['role'] ?? 'user');

        if ($role === 'tool') {
            return [
                'role' => 'tool',
                'tool_call_id' => (string) ($message['tool_call_id'] ?? ''),
                'content' => (string) ($message['content'] ?? ''),
            ];
        }

        $out = ['role' => $role, 'content' => (string) ($message['content'] ?? '')];

        if ($role === 'assistant' && ! empty($message['tool_calls'])) {
            // The API rejects an empty string next to tool_calls — null is the
            // documented "no text" value there.
            $out['content'] = $out['content'] === '' ? null : $out['content'];
            $out['tool_calls'] = array_map(fn (array $call) => $call['raw'] ?? [
                'id' => (string) $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => (string) $call['name'],
                    'arguments' => json_encode($call['arguments'] ?: new \stdClass, JSON_UNESCAPED_UNICODE) ?: '{}',
                ],
            ], $message['tool_calls']);
        }

        return $out;
    }

    /**
     * Embedding vector for a text (plan-library vector retrieval). Pinned to
     * the OpenAI config regardless of $provider — embeddings are only used by
     * the plan library and only OpenAI is wired for them. Usage is recorded
     * for cost tracking like every other paid call.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
        $input = mb_substr($text, 0, 8000);
        $startMs = (int) (microtime(true) * 1000);

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->timeout(60)
            ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/embeddings', [
                'model' => $model,
                'input' => $input,
            ])
            ->throw();

        $promptTokens = (int) ($response->json('usage.prompt_tokens') ?? 0);

        LlmUsage::record('openai', $model, $promptTokens, 0);

        LlmRequestRecorder::record(
            'openai', $model, 'embedding',
            null, $input, null, [],
            $promptTokens, 0,
            (int) (microtime(true) * 1000) - $startMs,
        );

        $vector = $response->json('data.0.embedding');

        if (! is_array($vector) || $vector === []) {
            throw new \RuntimeException('OpenAI embeddings response had no vector.');
        }

        return array_map('floatval', $vector);
    }

    /** @return array<string, mixed> */
    private function buildPayload(string $model, string $systemPrompt, string $userMessage, array $options): array
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['top_p']) && is_numeric($options['top_p'])) {
            $payload['top_p'] = (float) $options['top_p'];
        }

        // num_predict -1 means "unlimited" in Ollama → simply omit the cap here.
        // Providers cap the output differently (DeepSeek rejects > 8K), so an
        // optional services.{provider}.max_output_cap clamps the request.
        $numPredict = $options['num_predict'] ?? null;
        if (is_numeric($numPredict) && (int) $numPredict > 0) {
            $cap = (int) $this->cfg('max_output_cap', 0);
            $payload[(string) $this->cfg('max_tokens_param', 'max_completion_tokens')] =
                $cap > 0 ? min((int) $numPredict, $cap) : (int) $numPredict;
        }

        return $payload;
    }

    private function post(array $payload, int $timeout, string $kind = 'chat'): Response
    {
        $startMs = (int) (microtime(true) * 1000);

        // Transient failures (free-tier capacity 503s, rate-limit 429s, network
        // blips) get 3 retries with growing backoff; client errors fail fast.
        $response = Http::withToken((string) $this->cfg('api_key'))
            ->timeout($timeout)
            ->retry([2000, 5000, 12000], when: function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 500, 502, 503, 504, 529], true);
            })
            ->post(rtrim((string) $this->cfg('base_url'), '/').'/chat/completions', $payload)
            ->throw();

        $promptTokens = (int) ($response->json('usage.prompt_tokens') ?? 0);
        $completionTokens = (int) ($response->json('usage.completion_tokens') ?? 0);

        LlmUsage::record($this->provider, (string) $payload['model'], $promptTokens, $completionTokens);

        LlmRequestRecorder::record(
            $this->provider,
            (string) $payload['model'],
            $kind,
            $payload['messages'][0]['content'] ?? null,
            $payload['messages'][1]['content'] ?? null,
            (string) ($response->json('choices.0.message.content') ?? ''),
            array_intersect_key($payload, array_flip(['temperature', 'top_p', 'max_completion_tokens', 'max_tokens'])),
            $promptTokens,
            $completionTokens,
            (int) (microtime(true) * 1000) - $startMs,
        );

        return $response;
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return config("services.{$this->provider}.{$key}", $default);
    }
}
