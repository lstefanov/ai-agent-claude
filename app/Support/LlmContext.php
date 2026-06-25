<?php

namespace App\Support;

/**
 * Ambient context for the unit of work that is making paid LLM calls.
 *
 * Callers that know "who" a call is for read attribution from here when they
 * persist each call to llm_requests (LlmRequestRecorder, via the provider
 * chokepoints) — so the concrete agents and the HTTP clients stay
 * context-agnostic.
 *
 * Two scope kinds:
 *  - Top-level boundary owners (NodeExecutorService, BuilderAssistantService,
 *    FlowPlannerService) open a unit of work in their own job/turn: set() the
 *    base frame and clear() it after.
 *  - Nested helpers that run INSIDE such a scope (EmbeddingService,
 *    KnowledgeSynthesizer, ModelRouterService, …) must push() their own frame
 *    and pop() it in a finally — NEVER set()/clear(), which would destroy the
 *    enclosing scope's attribution. push() inherits the parent frame so the
 *    nested call is still attributed; pop() restores the parent untouched.
 *
 * Keys: company_id, flow_id, flow_run_id, node_run_id, agent_name, agent_type,
 *       purpose, session_id. All optional — a call made with no context set is
 *       still logged, just with empty attribution.
 */
class LlmContext
{
    /** @var array<int, array<string, mixed>> Stack of nested context frames. */
    private static array $stack = [];

    /**
     * Top-level entry: replace the active frame.
     *
     * @param  array<string, mixed>  $context
     */
    public static function set(array $context): void
    {
        self::$stack = [$context];
    }

    /** @return array<string, mixed> The current (innermost) frame. */
    public static function get(): array
    {
        return self::$stack === [] ? [] : self::$stack[array_key_last(self::$stack)];
    }

    public static function clear(): void
    {
        self::$stack = [];
    }

    /**
     * Enter a nested scope: inherit the parent frame + overrides.
     *
     * @param  array<string, mixed>  $context
     */
    public static function push(array $context = []): void
    {
        self::$stack[] = array_merge(self::get(), $context);
    }

    /** Leave the nested scope, restoring the parent frame. */
    public static function pop(): void
    {
        array_pop(self::$stack);
    }
}
