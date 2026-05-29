<?php

namespace App\Agents\Tools;

interface AgentTool
{
    /** Returns the unique tool identifier used by the agent runtime (e.g. 'web_search'). */
    public function name(): string;

    /**
     * Execute the tool and return formatted output ready for LLM consumption.
     * Throws \RuntimeException on unrecoverable failure.
     *
     * @param array<string, mixed> $params
     */
    public function execute(array $params): string;
}
