<?php

namespace App\Agents\Tools;

interface AgentTool
{
    /** Returns the unique tool identifier used by the agent runtime (e.g. 'web_search'). */
    public function name(): string;

    /** One-line LLM-facing description — what the tool does and when to call it. */
    public function description(): string;

    /**
     * JSON Schema for the execute() $params — what an LLM-driven loop sends
     * as tool-call arguments. Must mirror exactly what execute() reads.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Execute the tool and return formatted output ready for LLM consumption.
     * Throws \RuntimeException on unrecoverable failure.
     *
     * @param  array<string, mixed>  $params
     */
    public function execute(array $params): string;
}
