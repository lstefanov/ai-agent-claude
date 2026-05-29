<?php

namespace App\Agents\Tools;

interface AgentTool
{
    public function name(): string;

    /** @param array<string, mixed> $params */
    public function execute(array $params): string;
}
