<?php

namespace App\Support;

use App\Models\LlmRequest;
use Illuminate\Support\Facades\Log;

/**
 * Persists one row in llm_requests for every paid LLM API call.
 *
 * Called from the provider chokepoints right next to LlmUsage::record(): it
 * combines the call's tokens/content with the ambient LlmContext (who the call
 * was for) and the per-1M-token price table, then writes the audit row.
 *
 * The whole body is guarded — a logging failure must NEVER break the actual
 * agent run or planning phase.
 */
class LlmRequestRecorder
{
    /** @param array<string, mixed> $options */
    public static function record(
        string $provider,
        string $model,
        string $kind,
        ?string $system,
        ?string $user,
        ?string $response,
        array $options,
        int $promptTokens,
        int $completionTokens,
        int $durationMs,
        string $status = 'completed',
        ?string $error = null,
        ?float $costOverride = null,
    ): void {
        try {
            $ctx = LlmContext::get();

            LlmRequest::create([
                'provider' => $provider,
                'model' => $model,
                'kind' => $kind,
                'purpose' => $ctx['purpose'] ?? null,
                'session_id' => $ctx['session_id'] ?? null,

                'company_id' => $ctx['company_id'] ?? null,
                'flow_id' => $ctx['flow_id'] ?? null,
                'flow_run_id' => $ctx['flow_run_id'] ?? null,
                'node_run_id' => $ctx['node_run_id'] ?? null,
                'agent_name' => $ctx['agent_name'] ?? null,
                'agent_type' => $ctx['agent_type'] ?? null,

                'system_prompt' => $system,
                'user_message' => $user,
                'response_text' => $response,
                'options' => $options ?: null,

                'prompt_tokens' => $promptTokens ?: null,
                'completion_tokens' => $completionTokens ?: null,
                'total_tokens' => ($promptTokens + $completionTokens) ?: null,
                // Flat/page-priced услуги (Perplexity, OCR) подават явна цена —
                // token-базираната costFor() не важи за тях.
                'cost_usd' => round($costOverride ?? LlmUsage::costFor($provider, $model, $promptTokens, $completionTokens), 6),
                'duration_ms' => $durationMs,

                'status' => $status,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[LlmRequestRecorder] failed to log call: '.$e->getMessage());
        }
    }
}
