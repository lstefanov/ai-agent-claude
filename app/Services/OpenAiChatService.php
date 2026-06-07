<?php

namespace App\Services;

use App\Support\LlmRequestRecorder;
use App\Support\LlmUsage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chat client for OpenAI.
 *
 * Two consumers:
 *  - runtime: agents whose model is "openai/<model>" (OllamaService::chat()
 *    delegates here, so the concrete agent classes stay provider-agnostic);
 *  - planning: GeneratorService::chatJson() for the FlowPlannerService phases
 *    (Structured Outputs — the response cannot violate the JSON schema).
 *
 * Ollama-style options map onto Chat Completions:
 *   temperature → temperature, top_p → top_p,
 *   num_predict (>0) → max_completion_tokens, num_predict -1 → no cap.
 * num_ctx / top_k / repeat_penalty / seed have no equivalent and are ignored.
 */
class OpenAiChatService
{
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

        Log::info('[OpenAiChat] '.$model.' response length: '.strlen($content));

        return $content;
    }

    /**
     * Structured Outputs call: the model is constrained to the given JSON schema
     * (response_format json_schema, strict: true) and the decoded array is returned.
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
        $payload = $this->buildPayload($model, $systemPrompt, $userMessage, $options);
        $payload['response_format'] = [
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
            throw new \RuntimeException('OpenAI refused the structured request: '.$refusal);
        }

        $raw = (string) ($response->json('choices.0.message.content') ?? '');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenAI structured output was not valid JSON ('.json_last_error_msg().').');
        }

        return $decoded;
    }

    /**
     * Embedding vector for a text (plan-library vector retrieval). Usage is
     * recorded for cost tracking like every other paid call.
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
            ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/').'/v1/embeddings', [
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
        $numPredict = $options['num_predict'] ?? null;
        if (is_numeric($numPredict) && (int) $numPredict > 0) {
            $payload['max_completion_tokens'] = (int) $numPredict;
        }

        return $payload;
    }

    private function post(array $payload, int $timeout, string $kind = 'chat'): Response
    {
        $startMs = (int) (microtime(true) * 1000);

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->timeout($timeout)
            ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/').'/v1/chat/completions', $payload)
            ->throw();

        $promptTokens = (int) ($response->json('usage.prompt_tokens') ?? 0);
        $completionTokens = (int) ($response->json('usage.completion_tokens') ?? 0);

        LlmUsage::record('openai', (string) $payload['model'], $promptTokens, $completionTokens);

        LlmRequestRecorder::record(
            'openai',
            (string) $payload['model'],
            $kind,
            $payload['messages'][0]['content'] ?? null,
            $payload['messages'][1]['content'] ?? null,
            (string) ($response->json('choices.0.message.content') ?? ''),
            array_intersect_key($payload, array_flip(['temperature', 'top_p', 'max_completion_tokens'])),
            $promptTokens,
            $completionTokens,
            (int) (microtime(true) * 1000) - $startMs,
        );

        return $response;
    }
}
