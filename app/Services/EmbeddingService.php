<?php

namespace App\Services;

use App\Support\LlmContext;
use Illuminate\Support\Facades\Log;

/**
 * Provider switch for the flow-memory embeddings: OpenAI (default) or the
 * local Ollama host (free). Every failure is non-fatal — memory must never
 * break a run, so embed() returns null and the caller skips similarity.
 *
 * Vectors from different providers/models have different dimensions and are
 * NOT comparable; providerTag() is stored next to each vector and similarity
 * only runs within one tag.
 */
class EmbeddingService
{
    private ?string $providerOverride = null;

    /**
     * Clone with a pinned provider — the knowledge base can run on its own
     * provider while flow memory keeps reading the memory config. Invalid or
     * null overrides fall back to the default behavior.
     */
    public function withProvider(?string $provider): static
    {
        $clone = clone $this;
        $clone->providerOverride = in_array($provider, ['openai', 'ollama'], true) ? $provider : null;

        return $clone;
    }

    public function provider(): string
    {
        $provider = $this->providerOverride
            ?? (string) config('services.memory.embedding_provider', 'openai');

        return in_array($provider, ['openai', 'ollama'], true) ? $provider : 'openai';
    }

    public function providerTag(): string
    {
        return $this->provider() === 'ollama'
            ? 'ollama:'.config('services.ollama.embedding_model', 'nomic-embed-text')
            : 'openai:'.config('services.openai.embedding_model', 'text-embedding-3-small');
    }

    /**
     * @param  array<string, mixed>  $context  LlmContext attribution (flow_id, flow_run_id, …)
     * @return array<int, float>|null null when the provider failed/is unconfigured
     */
    public function embed(string $text, array $context = []): ?array
    {
        if ($this->provider() === 'openai' && empty(config('services.openai.api_key'))) {
            return null;
        }

        LlmContext::set(array_merge(['purpose' => 'embedding'], $context));

        try {
            return $this->provider() === 'ollama'
                ? app(OllamaService::class)->embed($text)
                : app(OpenAiChatService::class)->embed($text);
        } catch (\Throwable $e) {
            Log::warning('[FlowMemory] Embedding failed ('.$this->providerTag().'): '.$e->getMessage());

            return null;
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
