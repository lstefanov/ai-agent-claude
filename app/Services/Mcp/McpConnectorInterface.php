<?php

namespace App\Services\Mcp;

interface McpConnectorInterface
{
    /**
     * Налични tools с техните JSON schemas.
     *
     * @return array<int, array{name: string, description: string, parameters: array, writes: bool}>
     */
    public function listTools(): array;

    /** Изпълнява един tool call. */
    public function callTool(string $tool, array $params): McpToolResult;

    /** Проверява дали credentials са валидни (лек read-only ping). */
    public function testConnection(): bool;

    /**
     * Връща конфигуриран clone с инжектирани credentials — ЕДИНСТВЕНАТА точка,
     * през която credentials влизат в конектора (вика се само от McpClientService).
     */
    public function withCredentials(array $credentials): static;
}
