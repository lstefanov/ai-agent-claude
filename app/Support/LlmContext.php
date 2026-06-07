<?php

namespace App\Support;

/**
 * Ambient context for the unit of work that is making paid LLM calls.
 *
 * Mirrors the static pattern of LlmUsage. Callers that know "who" a call is for
 * (NodeExecutorService, FlowPlannerService, PlanLibraryService) set() the
 * context before the work and clear() it after (in a finally block). The
 * provider chokepoints (OpenAiChatService / AnthropicChatService) read() it
 * when they persist each call to llm_requests — so the concrete agents and the
 * HTTP clients stay context-agnostic.
 *
 * Keys: company_id, flow_id, flow_run_id, node_run_id, agent_name, agent_type,
 *       purpose, kind. All optional — a call made with no context set is still
 *       logged, just with empty attribution.
 */
class LlmContext
{
    /** @var array<string, mixed> */
    private static array $context = [];

    /** @param array<string, mixed> $context */
    public static function set(array $context): void
    {
        self::$context = $context;
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        return self::$context;
    }

    public static function clear(): void
    {
        self::$context = [];
    }
}
