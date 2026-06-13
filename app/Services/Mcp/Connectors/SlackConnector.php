<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Slack конектор (bot token от OAuth). Read: list_channels, list_messages.
 * Write: post_message, create_thread, upload_file. Bot токените не изтичат →
 * без refresh.
 */
class SlackConnector extends AbstractConnector
{
    public function listTools(): array
    {
        return [
            ['name' => 'slack.list_channels', 'description' => 'Наличните канали', 'writes' => false,
                'parameters' => ['limit' => ['type' => 'integer']]],
            ['name' => 'slack.list_messages', 'description' => 'Последни N съобщения от канал', 'writes' => false,
                'parameters' => ['channel' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
            ['name' => 'slack.post_message', 'description' => 'Публикува в канал', 'writes' => true,
                'parameters' => ['channel' => ['type' => 'string'], 'text' => ['type' => 'string']]],
            ['name' => 'slack.create_thread', 'description' => 'Отговор в нишка', 'writes' => true,
                'parameters' => ['channel' => ['type' => 'string'], 'thread_ts' => ['type' => 'string'], 'text' => ['type' => 'string']]],
            ['name' => 'slack.upload_file', 'description' => 'Прикачва текстов файл', 'writes' => true,
                'parameters' => ['channel' => ['type' => 'string'], 'content' => ['type' => 'string'], 'filename' => ['type' => 'string'], 'title' => ['type' => 'string']]],
        ];
    }

    public function testConnection(): bool
    {
        try {
            return (bool) $this->client()->post('auth.test')->json('ok', false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        try {
            return match ($tool) {
                'slack.list_channels' => $this->listChannels($params),
                'slack.list_messages' => $this->listMessages($params),
                'slack.post_message' => $this->postMessage($params),
                'slack.create_thread' => $this->postMessage($params, (string) ($params['thread_ts'] ?? '')),
                'slack.upload_file' => $this->uploadFile($params),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("Slack грешка: {$e->getMessage()}");
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://slack.com/api')
            ->withToken((string) ($this->credentials['access_token'] ?? $this->credentials['token'] ?? ''))
            ->timeout(20);
    }

    /** Slack връща ok:false + error в 200 отговор — нормализираме. */
    private function call(string $method, array $payload, bool $get = false): array
    {
        $res = $get ? $this->client()->get($method, $payload) : $this->client()->asForm()->post($method, $payload);
        $json = (array) $res->json();
        if (! ($json['ok'] ?? false)) {
            throw new \RuntimeException($json['error'] ?? "HTTP {$res->status()}");
        }

        return $json;
    }

    private function listChannels(array $params): McpToolResult
    {
        $json = $this->call('conversations.list', ['limit' => min(200, max(1, (int) ($params['limit'] ?? 100))), 'exclude_archived' => true], true);
        $channels = (array) ($json['channels'] ?? []);
        $lines = array_map(fn ($c) => '#'.($c['name'] ?? '?').' ('.($c['id'] ?? '').')', $channels);

        return McpToolResult::ok(count($channels).' канала:'."\n".implode("\n", $lines), ['channels' => $channels]);
    }

    private function listMessages(array $params): McpToolResult
    {
        $json = $this->call('conversations.history', [
            'channel' => (string) ($params['channel'] ?? ''),
            'limit' => min(100, max(1, (int) ($params['limit'] ?? 20))),
        ], true);
        $messages = (array) ($json['messages'] ?? []);
        $lines = array_map(fn ($m) => '• '.mb_substr((string) ($m['text'] ?? ''), 0, 200), $messages);

        return McpToolResult::ok(count($messages).' съобщения:'."\n".implode("\n", $lines), ['messages' => $messages]);
    }

    private function postMessage(array $params, string $threadTs = ''): McpToolResult
    {
        $payload = ['channel' => (string) ($params['channel'] ?? ''), 'text' => (string) ($params['text'] ?? '')];
        if ($threadTs !== '') {
            $payload['thread_ts'] = $threadTs;
        }
        $json = $this->call('chat.postMessage', $payload);

        return McpToolResult::ok('Публикувано в '.$payload['channel'], ['ts' => $json['ts'] ?? null]);
    }

    private function uploadFile(array $params): McpToolResult
    {
        $json = $this->call('files.upload', [
            'channels' => (string) ($params['channel'] ?? ''),
            'content' => (string) ($params['content'] ?? ''),
            'filename' => (string) ($params['filename'] ?? 'file.txt'),
            'title' => (string) ($params['title'] ?? ''),
        ]);

        return McpToolResult::ok('Качен файл в '.(string) ($params['channel'] ?? ''), ['file' => $json['file']['id'] ?? null]);
    }
}
