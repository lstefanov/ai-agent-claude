<?php

namespace App\Services\Mcp;

/**
 * Резултат от един MCP tool call. `text` е човешко-четимото описание, което
 * става изход на mcp_action node-а; `data` са структурираните данни (опционално).
 */
readonly class McpToolResult
{
    public function __construct(
        public bool $success,
        public string $text,
        public array $data = [],
        public ?string $error = null,
    ) {}

    public static function ok(string $text, array $data = []): self
    {
        return new self(true, $text, $data);
    }

    public static function fail(string $error): self
    {
        return new self(false, '', [], $error);
    }
}
