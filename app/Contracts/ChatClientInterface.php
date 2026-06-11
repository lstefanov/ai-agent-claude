<?php

namespace App\Contracts;

/**
 * Common contract for the paid-provider chat clients (OpenAiChatService for
 * every OpenAI-compatible API, AnthropicChatService for Claude). GeneratorService
 * and OllamaService::paidService() dispatch against this interface so provider
 * differences stay inside the implementations.
 *
 * NOT bound in the container — there are two implementations plus per-provider
 * instances via OpenAiChatService::for($provider); resolve concrete classes.
 *
 * Both implementations are buffered HTTP calls: $onProgress fires once before
 * the request, $onChunk once with the full content after it. Real per-step
 * streaming granularity lives in AgentLoop, not here.
 */
interface ChatClientInterface
{
    public function chat(
        string $model,
        string $systemPrompt,
        string $userMessage,
        array $options = [],
        ?callable $onProgress = null
    ): string;

    /**
     * Structured JSON call — the decoded array matches the schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function chatJson(
        string $model,
        string $systemPrompt,
        string $userMessage,
        string $schemaName,
        array $schema,
        array $options = []
    ): array;

    /**
     * One round of a multi-turn tool-calling conversation. The caller owns the
     * agentic loop; this method does exactly one request.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @return array{content: string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>}
     */
    public function chatTurn(
        string $model,
        array $messages,
        array $tools,
        array $options = [],
        ?callable $onChunk = null
    ): array;
}
