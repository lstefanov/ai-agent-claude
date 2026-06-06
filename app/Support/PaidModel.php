<?php

namespace App\Support;

/**
 * The single place that understands paid-provider model prefixes.
 *
 * An agent's `model` string decides where it executes:
 *   "mistral-nemo"              → local Ollama (default)
 *   "openai/gpt-4o-mini"        → OpenAI Chat Completions
 *   "anthropic/claude-haiku-4-5"→ Anthropic Messages
 */
class PaidModel
{
    public const PREFIXES = [
        'openai' => 'openai/',
        'anthropic' => 'anthropic/',
    ];

    /** "openai" | "anthropic" | null (null = local Ollama model). */
    public static function provider(?string $model): ?string
    {
        if (! is_string($model)) {
            return null;
        }

        foreach (self::PREFIXES as $provider => $prefix) {
            if (str_starts_with($model, $prefix)) {
                return $provider;
            }
        }

        return null;
    }

    public static function isPaid(?string $model): bool
    {
        return self::provider($model) !== null;
    }

    /** Strip the provider prefix: "openai/gpt-4o-mini" → "gpt-4o-mini". */
    public static function strip(string $model): string
    {
        $provider = self::provider($model);

        return $provider === null ? $model : substr($model, strlen(self::PREFIXES[$provider]));
    }

    /**
     * The prefixed model string for pinning an agent to a paid provider,
     * using that provider's configured runtime model.
     * pin('anthropic') → "anthropic/claude-haiku-4-5".
     */
    public static function pin(string $provider): string
    {
        $runtime = match ($provider) {
            'anthropic' => (string) config('services.anthropic.runtime_model', 'claude-haiku-4-5'),
            default => (string) config('services.openai.runtime_model', 'gpt-4o-mini'),
        };

        return (self::PREFIXES[$provider] ?? self::PREFIXES['openai']).$runtime;
    }

    /** True when the provider has an API key configured. */
    public static function available(string $provider): bool
    {
        return ! empty(config("services.{$provider}.api_key"));
    }
}
