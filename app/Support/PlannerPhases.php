<?php

namespace App\Support;

/**
 * The four planner phases + shared formatting for "with what was this plan
 * generated" labels. Used by PlanAbCommand (variant grammar), the builder's
 * generation config popup and the flow-version metadata.
 */
class PlannerPhases
{
    public const PHASES = ['intent_analysis', 'pipeline_design', 'plan_critique', 'agent_revision'];

    /** Short CLI/UI aliases ↔ canonical phase names. */
    public const ALIASES = [
        'intent' => 'intent_analysis',
        'design' => 'pipeline_design',
        'critique' => 'plan_critique',
        'revision' => 'agent_revision',
    ];

    /**
     * Human-readable label: "provider:model" when uniform across phases,
     * otherwise the per-phase map ("intent=gemini:…, design=anthropic:…").
     *
     * @param  array<string, array{provider: string, model: ?string}>  $phases
     */
    public static function label(array $phases): string
    {
        $format = function (array $spec): string {
            $provider = (string) ($spec['provider'] ?? '');
            $model = (string) ($spec['model'] ?? '');
            if ($model === '') {
                $model = self::defaultModelFor($provider);
            }

            return $provider.($model !== '' ? ':'.$model : '');
        };

        $formatted = array_map($format, $phases);

        if (count(array_unique($formatted)) === 1) {
            return (string) reset($formatted);
        }

        $aliases = array_flip(self::ALIASES);

        return collect($formatted)
            ->map(fn ($value, $phase) => ($aliases[$phase] ?? $phase).'='.$value)
            ->implode(', ');
    }

    public static function defaultModelFor(string $provider): string
    {
        return $provider === 'ollama'
            ? (string) config('services.ollama.planner_model')
            : (string) config("services.{$provider}.model");
    }

    /**
     * Selectable cloud planner models per provider — the provider default
     * first, then the pricing-table entries (minus embeddings and bare
     * prefix-match rows like "gemini").
     *
     * @return array<string, list<string>>
     */
    public static function cloudModels(): array
    {
        return collect(['openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen'])->mapWithKeys(function ($provider) {
            $models = collect(array_keys((array) config("services.{$provider}.pricing", [])))
                ->reject(fn ($m) => str_contains((string) $m, 'embedding') || $m === $provider);

            $default = (string) config("services.{$provider}.model");
            if ($default !== '') {
                $models = $models->reject(fn ($m) => $m === $default)->prepend($default);
            }

            return [$provider => $models->values()->all()];
        })->all();
    }

    /**
     * The cloud pricing tables (USD per 1M tokens) — drives the live cost
     * estimate in the generation popup (JS replicates LlmUsage::costFor's
     * prefix matching).
     *
     * @return array<string, array<string, array{in: float, out: float}>>
     */
    public static function pricing(): array
    {
        return collect(['openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen'])
            ->mapWithKeys(fn ($provider) => [$provider => (array) config("services.{$provider}.pricing", [])])
            ->all();
    }
}
