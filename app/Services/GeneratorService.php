<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic LLM client for everything PLANNING-related: the
 * FlowPlannerService phases and the small AI-assist endpoints (improve a flow
 * description, generate an agent-template field).
 *
 * Providers (config('services.generator.provider')):
 *  - openai / anthropic — strong cloud planners (best plan quality, paid);
 *  - ollama — FREE local planning on OLLAMA_PLANNER_MODEL via Ollama
 *    structured outputs (constrained JSON decoding). Same three-phase
 *    pipeline; plan quality depends on the local model.
 *
 * The actual HTTP clients live in OpenAiChatService / AnthropicChatService /
 * OllamaService — this class only picks the provider + model.
 */
class GeneratorService
{
    public function chat(
        string $systemPrompt,
        string $userMessage,
        array $options = [],
        ?callable $onProgress = null
    ): string {
        return match ($this->provider()) {
            'anthropic' => app(AnthropicChatService::class)->chat(
                (string) config('services.anthropic.model'),
                $systemPrompt,
                $userMessage,
                $options,
                $onProgress,
            ),
            'openai' => app(OpenAiChatService::class)->chat(
                (string) config('services.openai.model'),
                $systemPrompt,
                $userMessage,
                $options,
                $onProgress,
            ),
            'ollama' => app(OllamaService::class)->chat(
                $this->ollamaPlannerModel(),
                $systemPrompt,
                $userMessage,
                $options,
                $onProgress,
            ),
            default => throw new \RuntimeException(
                'GENERATOR_PROVIDER must be "openai", "anthropic" or "ollama" (got "'.$this->provider().'").'
            ),
        };
    }

    /**
     * Structured JSON call — the decoded array is guaranteed to match the
     * schema (OpenAI Structured Outputs / Anthropic forced tool call).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function chatJson(
        string $systemPrompt,
        string $userMessage,
        string $schemaName,
        array $schema,
        array $options = [],
        ?callable $onProgress = null
    ): array {
        if ($onProgress) {
            $onProgress();
        }

        return match ($this->provider()) {
            'anthropic' => app(AnthropicChatService::class)->chatJson(
                (string) config('services.anthropic.model'),
                $systemPrompt,
                $userMessage,
                $schemaName,
                $schema,
                $options,
            ),
            'openai' => app(OpenAiChatService::class)->chatJson(
                (string) config('services.openai.model'),
                $systemPrompt,
                $userMessage,
                $schemaName,
                $schema,
                $options,
            ),
            'ollama' => $this->chatJsonOllama($systemPrompt, $userMessage, $schemaName, $schema, $options),
            default => throw new \RuntimeException(
                'GENERATOR_PROVIDER must be "openai", "anthropic" or "ollama" (got "'.$this->provider().'").'
            ),
        };
    }

    /**
     * FREE local structured planning: Ollama's `format` parameter constrains
     * decoding to the JSON schema, so the local model can only emit
     * schema-shaped output.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function chatJsonOllama(
        string $systemPrompt,
        string $userMessage,
        string $schemaName,
        array $schema,
        array $options
    ): array {
        $options['format'] = $schema;
        // Local models are slower — give structured phases generous output room.
        $options['num_predict'] = $options['num_predict'] ?? 8000;

        // Small local models (low temperature + constrained JSON decoding) can fall
        // into a token-repetition loop — e.g. repeating one key_tasks string until
        // the num_predict cap, which truncates the JSON mid-array and makes it
        // unparseable. A repeat penalty breaks the loop. Callers can override.
        $options['repeat_penalty'] = $options['repeat_penalty'] ?? 1.3;
        $options['repeat_last_n'] = $options['repeat_last_n'] ?? 256;

        // Safety net for genuine transient empties (server busy / cold load): a
        // single blip should not kill the whole plan — retry a few times.
        $attempts = 3;
        $lastRaw = '';
        for ($i = 1; $i <= $attempts; $i++) {
            $lastRaw = trim(app(OllamaService::class)->chat(
                $this->ollamaPlannerModel(),
                $systemPrompt,
                $userMessage,
                $options,
            ));

            $decoded = json_decode($lastRaw, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            Log::warning(sprintf(
                '[GeneratorService] Ollama planner returned %s for schema %s (model: %s), attempt %d/%d',
                $lastRaw === '' ? 'an EMPTY response' : 'invalid JSON',
                $schemaName,
                $this->ollamaPlannerModel(),
                $i,
                $attempts,
            ));

            if ($i < $attempts) {
                usleep(300_000); // 0.3s — let a busy/just-loaded model settle
            }
        }

        $reason = $lastRaw === '' ? 'returned an empty response' : 'did not return valid JSON';
        throw new \RuntimeException(sprintf(
            'Ollama planner %s for schema %s after %d attempts (model: %s).',
            $reason,
            $schemaName,
            $attempts,
            $this->ollamaPlannerModel(),
        ));
    }

    private function ollamaPlannerModel(): string
    {
        return (string) config('services.ollama.planner_model', 'qwen2.5:14b');
    }

    /**
     * The provider + model that chat() would actually use.
     *
     * @return array{provider: string, model: string}
     */
    public function providerModel(): array
    {
        $provider = $this->provider();

        $model = match ($provider) {
            'anthropic' => (string) config('services.anthropic.model'),
            'openai' => (string) config('services.openai.model'),
            'ollama' => $this->ollamaPlannerModel(),
            default => '',
        };

        return ['provider' => $provider, 'model' => $model];
    }

    public function isAvailable(): bool
    {
        return match ($this->provider()) {
            'anthropic' => ! empty(config('services.anthropic.api_key')),
            'openai' => ! empty(config('services.openai.api_key')),
            'ollama' => app(OllamaService::class)->isAvailable(),
            default => false,
        };
    }

    private function provider(): string
    {
        return (string) config('services.generator.provider', 'openai');
    }
}
