<?php

namespace App\Services;

use App\Support\LlmContext;
use Illuminate\Support\Facades\Log;

/**
 * Provider+model switch for embeddings (знанията и паметта). Every failure
 * is non-fatal — memory/knowledge must never break a run, so embed() returns
 * null and the caller skips similarity.
 *
 * Опции за провайдър (и защо):
 *  - 'ollama' (DEFAULT) — локално, БЕЗПЛАТНО, без API ключ. Модели:
 *      bge-m3 (default; мултиезичен, силен на български, 8k контекст),
 *      nomic-embed-text (по-лек, по-слаб на кирилица).
 *      Моделът трябва да е pull-нат на хоста: `ollama pull bge-m3`.
 *  - 'gemini' — БЕЗПЛАТЕН tier през Google AI Studio ключ (GEMINI_API_KEY);
 *      модел gemini-embedding-001 през OpenAI-compatible /embeddings.
 *  - 'openai' — платен (центове); text-embedding-3-small е най-евтиният
 *      и много стабилен избор, ако локалният хост не е наличен.
 *
 * Vectors from different providers/models have different dimensions and are
 * NOT comparable; providerTag() ("провайдър:модел") is stored next to each
 * vector and similarity only runs within one tag — смяна на провайдър/модел
 * изисква re-ingest (бутонът re-ingest в UI на знанията).
 */
class EmbeddingService
{
    private const PROVIDERS = ['ollama', 'openai', 'gemini'];

    private ?string $providerOverride = null;

    private ?string $modelOverride = null;

    /**
     * Clone with a pinned provider/model — the knowledge base can run on its
     * own provider while flow memory keeps reading the memory config. Invalid
     * or null overrides fall back to the default behavior.
     */
    public function withProvider(?string $provider, ?string $model = null): static
    {
        $clone = clone $this;
        $clone->providerOverride = in_array($provider, self::PROVIDERS, true) ? $provider : null;
        $clone->modelOverride = is_string($model) && trim($model) !== '' ? trim($model) : null;

        return $clone;
    }

    public function provider(): string
    {
        $provider = $this->providerOverride
            ?? (string) config('services.memory.embedding_provider', 'ollama');

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'ollama';
    }

    /** Конкретният embedding модел — override или per-provider default. */
    public function model(): string
    {
        if ($this->modelOverride !== null) {
            return $this->modelOverride;
        }

        // Без изричен override (= паметта) MEMORY_EMBEDDING_MODEL има думата.
        $memoryModel = trim((string) config('services.memory.embedding_model', ''));
        if ($this->providerOverride === null && $memoryModel !== '') {
            return $memoryModel;
        }

        return match ($this->provider()) {
            'ollama' => (string) config('services.ollama.embedding_model', 'bge-m3'),
            'gemini' => (string) config('services.gemini.embedding_model', 'gemini-embedding-001'),
            default => (string) config('services.openai.embedding_model', 'text-embedding-3-small'),
        };
    }

    public function providerTag(): string
    {
        return $this->provider().':'.$this->model();
    }

    /**
     * @param  array<string, mixed>  $context  LlmContext attribution (flow_id, flow_run_id, …)
     * @return array<int, float>|null null when the provider failed/is unconfigured
     */
    public function embed(string $text, array $context = []): ?array
    {
        $provider = $this->provider();

        if (in_array($provider, ['openai', 'gemini'], true)
            && empty(config("services.{$provider}.api_key"))) {
            return null;
        }

        LlmContext::set(array_merge(['purpose' => 'embedding'], $context));

        try {
            return $provider === 'ollama'
                ? app(OllamaService::class)->embed($text, $this->model())
                : OpenAiChatService::for($provider)->embed($text, $this->model());
        } catch (\Throwable $e) {
            Log::warning('[Embedding] Failed ('.$this->providerTag().'): '.$e->getMessage());

            return null;
        } finally {
            LlmContext::clear();
        }
    }

    /**
     * Batch embedding — при ollama една HTTP заявка за всички текстове
     * (N серийни мрежови round-trip-а → 1); cloud провайдърите падат на
     * embed() в цикъл. Резултатът е успореден на $texts (null = провал).
     *
     * @param  array<int, string>  $texts
     * @param  array<string, mixed>  $context
     * @return array<int, array<int, float>|null>
     */
    public function embedMany(array $texts, array $context = []): array
    {
        $texts = array_values($texts);
        if ($texts === []) {
            return [];
        }

        if ($this->provider() !== 'ollama') {
            return array_map(fn (string $text) => $this->embed($text, $context), $texts);
        }

        LlmContext::set(array_merge(['purpose' => 'embedding'], $context));

        try {
            return app(OllamaService::class)->embedMany($texts, $this->model());
        } catch (\Throwable $e) {
            Log::warning('[Embedding] Batch failed ('.$this->providerTag().'): '.$e->getMessage());

            return array_fill(0, count($texts), null);
        } finally {
            LlmContext::clear();
        }
    }

    /** @param array<int, float|int> $a @param array<int, float|int> $b */
    public static function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        return ($normA > 0 && $normB > 0) ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }
}
