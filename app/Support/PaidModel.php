<?php

namespace App\Support;

/**
 * The single place that understands paid-provider model prefixes.
 *
 * An agent's `model` string decides where it executes:
 *   "mistral-nemo"                 → local Ollama (default)
 *   "openai/gpt-4o-mini"           → OpenAI Chat Completions
 *   "anthropic/claude-haiku-4-5"   → Anthropic Messages
 *   "deepseek/deepseek-v4-flash"   → DeepSeek API (OpenAI-compatible)
 *   "gemini/gemini-3.5-flash"      → Google Gemini API (OpenAI-compatible)
 *   "xai/grok-4.3"                 → xAI API (OpenAI-compatible)
 *   "qwen/qwen3.5-flash"           → Alibaba Qwen API (OpenAI-compatible)
 */
class PaidModel
{
    public const PREFIXES = [
        'openai' => 'openai/',
        'anthropic' => 'anthropic/',
        'deepseek' => 'deepseek/',
        'gemini' => 'gemini/',
        'xai' => 'xai/',
        'qwen' => 'qwen/',
    ];

    /**
     * Providers whose runtime models cost real money per node. Everything else
     * in PREFIXES is the cheap tier (free tier or near-free flash models) and
     * is exempt from the planner's premium-agent budget.
     */
    public const PREMIUM = ['openai', 'anthropic'];

    public static function isPremium(?string $provider): bool
    {
        return in_array($provider, self::PREMIUM, true);
    }

    /** Provider key from PREFIXES, or null (null = local Ollama model). */
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
            'deepseek' => (string) config('services.deepseek.runtime_model', 'deepseek-v4-flash'),
            'gemini' => (string) config('services.gemini.runtime_model', 'gemini-3.1-flash-lite'),
            'xai' => (string) config('services.xai.runtime_model', 'grok-4.3'),
            'qwen' => (string) config('services.qwen.runtime_model', 'qwen3.5-flash'),
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
