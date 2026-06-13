<?php

namespace App\Services;

/**
 * Generic LLM tool-calling loop: call the model, execute the tool calls it
 * proposes, feed the results back, repeat until it answers without tools or
 * the step budget runs out. Extracted from the Builder Copilot so any caller
 * (assistant turns, agentic runtime nodes) shares one loop implementation.
 *
 * The loop is provider-agnostic — it goes through GeneratorService::chatTurn(),
 * so only cloud providers with dependable function calling are supported.
 *
 * Cost accounting stays with the CALLER: this class never touches
 * LlmUsage::take() — the accumulator is global, and both the assistant turn
 * and NodeExecutorService::runOnce() already bracket their own scopes with it.
 */
final class AgentLoop
{
    public function __construct(
        private GeneratorService $generator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $messages  system + history + user
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @param  callable(string $tool, array $args): string  $executor  runs one tool call, returns its result text
     * @param  ?callable(string $tool, array $args): void  $onToolCall  fired before each tool execution (stage/progress UI)
     * @param  ?callable(string $content): void  $onContent  fired with each non-empty model text (per-step pseudo-streaming)
     * @param  ?string  $wrapUpPrompt  appended as a user message for one final no-tools call when the budget runs out
     * @param  array<string, mixed>  $wrapUpOptions  options for the wrap-up call (defaults to $options)
     * @param  ?float  $deadlineTs  Unix timestamp; when reached, tool rounds stop and the loop wraps up,
     *                              so the caller's job returns a partial result instead of dying on its queue timeout
     * @param  ?string  $noToolsNudge  anti-lazy guard: if the FIRST response uses no tools at all, this is
     *                                 injected once as a user message and the loop continues — flash-tier
     *                                 models otherwise answer research missions from memory in one shot
     * @return array{content: string, steps: int, deadline_hit: bool}
     */
    public function run(
        string $provider,
        string $model,
        array $messages,
        array $tools,
        callable $executor,
        int $maxSteps = 8,
        array $options = [],
        ?callable $onToolCall = null,
        ?callable $onContent = null,
        ?string $wrapUpPrompt = null,
        array $wrapUpOptions = [],
        ?float $deadlineTs = null,
        ?string $noToolsNudge = null,
    ): array {
        $final = null;
        $steps = 0;
        $deadlineHit = false;
        $pastDeadline = function () use ($deadlineTs, &$deadlineHit): bool {
            if ($deadlineTs !== null && microtime(true) >= $deadlineTs) {
                $deadlineHit = true;
            }

            return $deadlineHit;
        };

        for ($step = 1; $step <= $maxSteps; $step++) {
            if ($pastDeadline()) {
                break;
            }

            $steps = $step;
            $result = $this->generator->chatTurn($provider, $model, $messages, $tools, $options);

            if ($onContent && $result['content'] !== '') {
                $onContent($result['content']);
            }

            if ($result['tool_calls'] === []) {
                if ($step === 1 && $noToolsNudge !== null && $tools !== [] && ! $pastDeadline()) {
                    if ($result['content'] !== '') {
                        $messages[] = ['role' => 'assistant', 'content' => $result['content']];
                    }
                    $messages[] = ['role' => 'user', 'content' => $noToolsNudge];
                    $noToolsNudge = null; // еднократно — втори директен отговор е финален

                    continue;
                }

                $final = $result['content'];
                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $result['content'],
                'tool_calls' => $result['tool_calls'],
            ];

            foreach ($result['tool_calls'] as $call) {
                if ($onToolCall) {
                    $onToolCall($call['name'], $call['arguments']);
                }

                // Every tool_call_id must get a tool message (provider protocol),
                // but past the deadline we stop EXECUTING tools — a placeholder
                // steers the model towards wrapping up with what it has.
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => $pastDeadline()
                        ? '(пропуснато: времевият бюджет изтече — приключи с наличните данни)'
                        : $executor($call['name'], $call['arguments']),
                ];
            }
        }

        // Step/time budget exhausted mid-investigation — ask for a wrap-up
        // without tools so the user still gets a useful answer.
        if ($final === null && $wrapUpPrompt !== null) {
            $messages[] = ['role' => 'user', 'content' => $wrapUpPrompt];

            $result = $this->generator->chatTurn($provider, $model, $messages, [], $wrapUpOptions ?: $options);

            if ($onContent && $result['content'] !== '') {
                $onContent($result['content']);
            }

            $final = $result['content'];
        }

        return ['content' => trim((string) $final), 'steps' => $steps, 'deadline_hit' => $deadlineHit];
    }
}
