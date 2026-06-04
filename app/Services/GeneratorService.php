<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic LLM client used for auto-generating Flow agents.
 *
 * Exposes the same chat() signature as OllamaService so the agent-generation
 * call sites are drop-in. The active provider is chosen via
 * config('services.generator.provider') — ollama (default), anthropic or openai.
 */
class GeneratorService
{
    public function __construct(
        private OllamaService $ollama,
    ) {}

    public function chat(
        string $systemPrompt,
        string $userMessage,
        array $options = [],
        ?callable $onProgress = null
    ): string {
        $provider = config('services.generator.provider', 'ollama');

        return match ($provider) {
            'anthropic' => $this->chatAnthropic($systemPrompt, $userMessage, $options, $onProgress),
            'openai'    => $this->chatOpenAi($systemPrompt, $userMessage, $options, $onProgress),
            default     => $this->ollama->chat(
                model: config('services.ollama.generator_model', 'mistral-nemo'),
                systemPrompt: $systemPrompt,
                userMessage: $userMessage,
                options: $options,
                onProgress: $onProgress,
            ),
        };
    }

    /**
     * The provider + model that chat() would actually use for the active provider.
     * Mirrors the provider switch in chat() so logging can record the real model.
     *
     * @return array{provider: string, model: string}
     */
    public function providerModel(): array
    {
        $provider = config('services.generator.provider', 'ollama');

        $model = match ($provider) {
            'anthropic' => (string) config('services.anthropic.model'),
            'openai'    => (string) config('services.openai.model'),
            default     => (string) config('services.ollama.generator_model', 'mistral-nemo'),
        };

        return ['provider' => $provider, 'model' => $model];
    }

    public function isAvailable(): bool
    {
        return match (config('services.generator.provider', 'ollama')) {
            'anthropic' => ! empty(config('services.anthropic.api_key')),
            'openai'    => ! empty(config('services.openai.api_key')),
            default     => $this->ollama->isAvailable(),
        };
    }

    private function chatAnthropic(
        string $systemPrompt,
        string $userMessage,
        array $options,
        ?callable $onProgress
    ): string {
        if ($onProgress) {
            $onProgress();
        }

        $response = Http::withHeaders([
            'x-api-key'         => (string) config('services.anthropic.api_key'),
            'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
        ])
            ->timeout($options['http_timeout'] ?? 600)
            ->post(rtrim((string) config('services.anthropic.base_url'), '/') . '/v1/messages', [
                'model'       => config('services.anthropic.model'),
                'system'      => $systemPrompt,
                'messages'    => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'max_tokens'  => (int) ($options['num_predict'] ?? 4096),
                'temperature' => $options['temperature'] ?? 0.7,
            ]);

        $response->throw();

        $content = '';
        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            }
        }

        Log::info('[Generator] Anthropic response length: ' . strlen($content));

        return $content;
    }

    private function chatOpenAi(
        string $systemPrompt,
        string $userMessage,
        array $options,
        ?callable $onProgress
    ): string {
        if ($onProgress) {
            $onProgress();
        }

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->timeout($options['http_timeout'] ?? 600)
            ->post(rtrim((string) config('services.openai.base_url'), '/') . '/v1/chat/completions', [
                'model'       => config('services.openai.model'),
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMessage],
                ],
                'max_tokens'  => (int) ($options['num_predict'] ?? 4096),
                'temperature' => $options['temperature'] ?? 0.7,
            ]);

        $response->throw();

        $content = (string) ($response->json('choices.0.message.content') ?? '');

        Log::info('[Generator] OpenAI response length: ' . strlen($content));

        return $content;
    }
}
