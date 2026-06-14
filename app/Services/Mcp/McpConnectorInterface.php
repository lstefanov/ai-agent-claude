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

    /**
     * Live опции за select-параметър в builder-а (Drive папки, Slack канали…).
     * $context носи стойностите на родителски параметри (напр. folder_id за
     * списък с файлове).
     *
     * @return array<int, array{value:string, label:string}>
     */
    public function listOptions(string $source, array $context = []): array;

    /** Проверява дали credentials са валидни (лек read-only ping). */
    public function testConnection(): bool;

    /**
     * Връща конфигуриран clone с инжектирани credentials — ЕДИНСТВЕНАТА точка,
     * през която credentials влизат в конектора (вика се само от McpClientService).
     */
    public function withCredentials(array $credentials): static;
}
