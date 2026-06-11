<?php

namespace App\Services;

use App\Support\PlannerPhases;
use App\Support\SchemaCoercion;
use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic LLM client for everything PLANNING-related:
 * FlowPlannerService phases and lightweight AI-assist endpoints.
 *
 * Providers:
 *  - openai / anthropic — strong cloud planners (best plan quality, paid);
 *  - deepseek — almost-free cloud planner (~$0.005/генерация, json_object);
 *  - gemini — free-tier cloud planner (OpenAI-compatible endpoint);
 *  - xai — Grok: planner + runtime (OpenAI-compatible, native json_schema);
 *  - qwen — Alibaba Qwen intl: planner + runtime, изрична BG поддръжка
 *    (OpenAI-compatible, json_object);
 *  - ollama — FREE local planning on OLLAMA_PLANNER_MODEL via Ollama
 *    structured outputs (constrained JSON decoding).
 *
 * Hybrid planning: every planner phase (intent_analysis / pipeline_design /
 * plan_critique / agent_revision) can run on its own provider+model via
 * config('services.planner.phases.{phase}') — e.g. Claude only for the
 * expensive pipeline design, DeepSeek/Gemini for the cheap phases. Unset
 * phases fall back to GENERATOR_PROVIDER + that provider's default model.
 *
 * The actual HTTP clients live in OpenAiChatService (OpenAI-compatible:
 * openai/deepseek/gemini/xai/qwen) / AnthropicChatService / OllamaService —
 * this class only picks the provider + model.
 */
class GeneratorService
{
    /** Providers served by the shared OpenAI-compatible client. */
    public const OPENAI_COMPATIBLE = ['openai', 'deepseek', 'gemini', 'xai', 'qwen'];

    /** Every valid planner provider. */
    public const PROVIDERS = ['openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen', 'ollama'];

    public function chat(
        string $systemPrompt,
        string $userMessage,
        array $options = [],
        ?callable $onProgress = null
    ): string {
        ['provider' => $provider, 'model' => $model] = $this->resolve();

        return match (true) {
            $provider === 'anthropic' => app(AnthropicChatService::class)->chat(
                $model,
                $systemPrompt,
                $userMessage,
                $options,
                $onProgress,
            ),
            in_array($provider, self::OPENAI_COMPATIBLE, true) => OpenAiChatService::for($provider)->chat(
                $model,
                $systemPrompt,
                $userMessage,
                $options,
                $onProgress,
            ),
            $provider === 'ollama' => app(OllamaService::class)->chat(
                $model,
                $systemPrompt,
                $userMessage,
                $options,
                $onProgress,
            ),
            default => throw new \RuntimeException($this->badProviderMessage($provider)),
        };
    }

    public function assist(
        string $systemPrompt,
        string $userMessage,
        array $options = [],
        ?callable $onProgress = null
    ): string {
        $provider = $this->assistProvider();
        $modelKey = $provider.'_model';
        $defaults = [
            'anthropic' => 'claude-haiku-4-5',
            'openai' => 'gpt-4o-mini',
            'deepseek' => 'deepseek-v4-flash',
            'gemini' => 'gemini-3.1-flash-lite',
            'xai' => 'grok-4.1-fast',
            'qwen' => 'qwen3.5-flash',
            'ollama' => 'todorov/bggpt:Gemma-3-12B-IT-Q5_K_M',
        ];
        $model = (string) config('services.assist.'.$modelKey, $defaults[$provider] ?? '');

        return match (true) {
            $provider === 'anthropic' => app(AnthropicChatService::class)->chat(
                $model, $systemPrompt, $userMessage, $options, $onProgress,
            ),
            in_array($provider, self::OPENAI_COMPATIBLE, true) => OpenAiChatService::for($provider)->chat(
                $model, $systemPrompt, $userMessage, $options, $onProgress,
            ),
            $provider === 'ollama' => app(OllamaService::class)->chat(
                $model, $systemPrompt, $userMessage, $options, $onProgress,
            ),
            default => throw new \RuntimeException(
                'ASSIST_PROVIDER must be one of: '.implode(', ', self::PROVIDERS).' (got "'.$provider.'").'
            ),
        };
    }

    /**
     * Structured JSON call — the decoded array matches the schema (native
     * Structured Outputs / forced tool call / validated JSON mode).
     *
     * $schemaName IS the planner phase name (intent_analysis, pipeline_design,
     * plan_critique, agent_revision), so the per-phase provider override from
     * services.planner.phases applies here automatically.
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

        ['provider' => $provider, 'model' => $model] = $this->resolve($schemaName);

        $result = match (true) {
            $provider === 'anthropic' => app(AnthropicChatService::class)->chatJson(
                $model,
                $systemPrompt,
                $userMessage,
                $schemaName,
                $schema,
                $options,
            ),
            in_array($provider, self::OPENAI_COMPATIBLE, true) => OpenAiChatService::for($provider)->chatJson(
                $model,
                $systemPrompt,
                $userMessage,
                $schemaName,
                $schema,
                $options,
            ),
            $provider === 'ollama' => $this->chatJsonOllama($model, $systemPrompt, $userMessage, $schemaName, $schema, $options),
            default => throw new \RuntimeException($this->badProviderMessage($provider)),
        };

        // Models occasionally double-encode nested arrays as JSON strings;
        // repair against the schema so callers always get the declared shape.
        return SchemaCoercion::coerce($result, $schema);
    }

    /**
     * Per-phase provider+model resolution. With a phase name the override from
     * services.planner.phases.{phase} wins; otherwise (or for unset phases)
     * GENERATOR_PROVIDER + the provider's default model apply.
     *
     * @return array{provider: string, model: string}
     */
    public function resolve(?string $phase = null): array
    {
        $cfg = $phase !== null
            ? array_filter((array) config("services.planner.phases.{$phase}", []))
            : [];

        $provider = (string) ($cfg['provider'] ?? config('services.generator.provider', 'openai'));
        $model = (string) ($cfg['model'] ?? $this->defaultModelFor($provider));

        return ['provider' => $provider, 'model' => $model];
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
        string $model,
        string $systemPrompt,
        string $userMessage,
        string $schemaName,
        array $schema,
        array $options
    ): array {
        $options['format'] = $schema;
        // Local models are slower — give structured phases generous output room.
        $options['num_predict'] = $options['num_predict'] ?? 8000;

        // The design-phase prompt (capability catalog + few-shots) easily tops
        // 8K tokens — without an explicit num_ctx Ollama silently clips it.
        $options['num_ctx'] = $options['num_ctx'] ?? (int) config('services.ollama.planner_num_ctx', 16384);

        // Thinking models (qwen3, deepseek-r1) emit a reasoning preamble that
        // wastes the token budget for constrained-JSON planning — disable it for
        // those families. Ollama ERRORS if "think" is sent to a model without
        // the capability, so it is only set when needed (or forced via
        // OLLAMA_PLANNER_THINK).
        if (! array_key_exists('think', $options)) {
            $configured = config('services.ollama.planner_think');
            if ($configured !== null && $configured !== '') {
                $options['think'] = filter_var($configured, FILTER_VALIDATE_BOOL);
            } elseif (preg_match('/^(qwen3|deepseek-r1)\b/', $model)) {
                $options['think'] = false;
            }
        }

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
                $model,
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
                $model,
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
            $model,
        ));
    }

    private function ollamaPlannerModel(): string
    {
        return (string) config('services.ollama.planner_model', 'qwen3:14b');
    }

    private function defaultModelFor(string $provider): string
    {
        return $provider === 'ollama'
            ? $this->ollamaPlannerModel()
            : (string) config("services.{$provider}.model", '');
    }

    /**
     * The provider + model a call would actually use (per-phase aware).
     *
     * @return array{provider: string, model: string}
     */
    public function providerModel(?string $phase = null): array
    {
        return $this->resolve($phase);
    }

    /**
     * The provider + model that assist() would actually use.
     *
     * @return array{provider: string, model: string}
     */
    public function assistProviderModel(): array
    {
        $provider = $this->assistProvider();

        $model = (string) config('services.assist.'.$provider.'_model', '');

        return ['provider' => $provider, 'model' => $model];
    }

    public function isAvailable(?string $phase = null): bool
    {
        return $this->providerAvailable($this->resolve($phase)['provider']);
    }

    /** Can this provider serve a planning call right now (key set / server up)? */
    public function providerAvailable(string $provider): bool
    {
        return $provider === 'ollama'
            ? app(OllamaService::class)->isAvailable()
            : ! empty(config("services.{$provider}.api_key"));
    }

    /**
     * The effective provider+model of every planner phase (env overrides
     * applied) — what a generation started "по подразбиране" would use.
     *
     * @return array<string, array{provider: string, model: string}>
     */
    public function resolveAllPhases(): array
    {
        return collect(PlannerPhases::PHASES)
            ->mapWithKeys(fn ($phase) => [$phase => $this->resolve($phase)])
            ->all();
    }

    private function assistProvider(): string
    {
        return (string) config('services.assist.provider', 'ollama');
    }

    private function badProviderMessage(string $provider): string
    {
        return 'GENERATOR_PROVIDER (или PLANNER_*_PROVIDER) must be one of "'
            .implode('", "', self::PROVIDERS).'" (got "'.$provider.'").';
    }
}
