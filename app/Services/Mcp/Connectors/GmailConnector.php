<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use App\Services\Mcp\ScopeException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Gmail конектор (OAuth2 access_token). Read: list_emails, get_email. Write:
 * send_email, create_draft, reply, label_email. Token refresh се прави в
 * McpClientService ПРЕДИ callTool. Scope enforcement: tool без съответния
 * grant хвърля ScopeException.
 */
class GmailConnector extends AbstractConnector
{
    private const READONLY = 'https://www.googleapis.com/auth/gmail.readonly';

    private const SEND = 'https://www.googleapis.com/auth/gmail.send';

    private const MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    private const COMPOSE = 'https://www.googleapis.com/auth/gmail.compose';

    private const MAILBOX = 'https://mail.google.com/';

    public function listTools(): array
    {
        return [
            ['name' => 'gmail.list_emails', 'description' => 'Последни N имейли с филтри (q, label)', 'writes' => false,
                'parameters' => ['query' => ['type' => 'string'], 'label' => ['type' => 'string'], 'max' => ['type' => 'integer']]],
            ['name' => 'gmail.get_email', 'description' => 'Пълно съдържание по message_id', 'writes' => false,
                'parameters' => ['message_id' => ['type' => 'string']]],
            ['name' => 'gmail.send_email', 'description' => 'Изпраща имейл', 'writes' => true,
                'parameters' => ['to' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'cc' => ['type' => 'string']]],
            ['name' => 'gmail.create_draft', 'description' => 'Създава чернова (без изпращане)', 'writes' => true,
                'parameters' => ['to' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'cc' => ['type' => 'string']]],
            ['name' => 'gmail.reply', 'description' => 'Отговаря на нишка', 'writes' => true,
                'parameters' => ['thread_id' => ['type' => 'string'], 'to' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string']]],
            ['name' => 'gmail.label_email', 'description' => 'Добавя/маха label', 'writes' => true,
                'parameters' => ['message_id' => ['type' => 'string'], 'add' => ['type' => 'array'], 'remove' => ['type' => 'array']]],
        ];
    }

    public function testConnection(): bool
    {
        try {
            return $this->client()->get('/profile')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        try {
            $this->assertScope($tool);

            return match ($tool) {
                'gmail.list_emails' => $this->listEmails($params),
                'gmail.get_email' => $this->getEmail($params),
                'gmail.send_email' => $this->sendEmail($params),
                'gmail.create_draft' => $this->createDraft($params),
                'gmail.reply' => $this->reply($params),
                'gmail.label_email' => $this->labelEmail($params),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (ScopeException $e) {
            return McpToolResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return McpToolResult::fail("Gmail грешка: {$e->getMessage()}");
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('mcp.gmail.api_base'))
            ->withToken((string) ($this->credentials['access_token'] ?? ''))
            ->acceptJson()
            ->timeout(25);
    }

    /** @throws ScopeException */
    private function assertScope(string $tool): void
    {
        $required = match ($tool) {
            'gmail.list_emails', 'gmail.get_email' => [self::READONLY, self::MODIFY, self::MAILBOX],
            'gmail.send_email', 'gmail.reply' => [self::SEND, self::MODIFY, self::MAILBOX],
            'gmail.create_draft' => [self::COMPOSE, self::MODIFY, self::MAILBOX],
            'gmail.label_email' => [self::MODIFY, self::MAILBOX],
            default => [],
        };
        if ($required === []) {
            return;
        }

        $granted = $this->grantedScopes();
        foreach ($required as $scope) {
            if (in_array($scope, $granted, true)) {
                return;
            }
        }

        throw new ScopeException("Конекторът няма нужния scope за {$tool} (нужно: ".implode(' или ', $required).')');
    }

    private function listEmails(array $params): McpToolResult
    {
        $query = [
            'q' => (string) ($params['query'] ?? ''),
            'maxResults' => min(50, max(1, (int) ($params['max'] ?? 10))),
        ];
        if (! empty($params['label'])) {
            $query['labelIds'] = (string) $params['label'];
        }

        $res = $this->client()->get('/messages', array_filter($query, fn ($v) => $v !== ''));
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $messages = (array) $res->json('messages', []);
        $lines = [];
        foreach ($messages as $msg) {
            $meta = $this->client()->get("/messages/{$msg['id']}", [
                'format' => 'metadata',
                'metadataHeaders' => ['From', 'Subject', 'Date'],
            ]);
            if ($meta->failed()) {
                continue;
            }
            $headers = $this->headerMap((array) $meta->json('payload.headers', []));
            $lines[] = "• [{$msg['id']}] {$headers['From']} — {$headers['Subject']} ({$headers['Date']})";
        }

        $text = count($messages).' имейла:'."\n".implode("\n", $lines);

        return McpToolResult::ok($text, ['messages' => $messages]);
    }

    private function getEmail(array $params): McpToolResult
    {
        $id = (string) ($params['message_id'] ?? '');
        if ($id === '') {
            return McpToolResult::fail('Липсва message_id');
        }

        $res = $this->client()->get("/messages/{$id}", ['format' => 'full']);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $payload = (array) $res->json('payload', []);
        $headers = $this->headerMap((array) ($payload['headers'] ?? []));
        $body = $this->extractBody($payload);
        $text = "От: {$headers['From']}\nТема: {$headers['Subject']}\nДата: {$headers['Date']}\n\n".$body;

        return McpToolResult::ok($text, ['headers' => $headers]);
    }

    private function sendEmail(array $params): McpToolResult
    {
        $raw = $this->buildRaw($params);
        $res = $this->client()->post('/messages/send', ['raw' => $raw]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        // §14: без съдържанието на имейла в резюмето.
        return McpToolResult::ok('Изпратен имейл до '.(string) ($params['to'] ?? '?'), ['id' => $res->json('id')]);
    }

    private function createDraft(array $params): McpToolResult
    {
        $raw = $this->buildRaw($params);
        $res = $this->client()->post('/drafts', ['message' => ['raw' => $raw]]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok('Създадена чернова до '.(string) ($params['to'] ?? '?'), ['id' => $res->json('id')]);
    }

    private function reply(array $params): McpToolResult
    {
        $raw = $this->buildRaw($params);
        $message = ['raw' => $raw];
        if (! empty($params['thread_id'])) {
            $message['threadId'] = (string) $params['thread_id'];
        }
        $res = $this->client()->post('/messages/send', $message);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok('Изпратен отговор до '.(string) ($params['to'] ?? '?'), ['id' => $res->json('id')]);
    }

    private function labelEmail(array $params): McpToolResult
    {
        $id = (string) ($params['message_id'] ?? '');
        if ($id === '') {
            return McpToolResult::fail('Липсва message_id');
        }

        $res = $this->client()->post("/messages/{$id}/modify", array_filter([
            'addLabelIds' => array_values((array) ($params['add'] ?? [])),
            'removeLabelIds' => array_values((array) ($params['remove'] ?? [])),
        ], fn ($v) => ! empty($v)));
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok("Обновени labels на имейл {$id}", ['id' => $id]);
    }

    private function buildRaw(array $params): string
    {
        $to = (string) ($params['to'] ?? '');
        $subject = (string) ($params['subject'] ?? '');
        $body = (string) ($params['body'] ?? '');

        $headers = [
            "To: {$to}",
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="UTF-8"',
            'Content-Transfer-Encoding: base64',
        ];
        if (! empty($params['cc'])) {
            array_splice($headers, 1, 0, 'Cc: '.(string) $params['cc']);
        }

        $mime = implode("\r\n", $headers)."\r\n\r\n".chunk_split(base64_encode($body));

        return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    }

    private function headerMap(array $headers): array
    {
        $map = ['From' => '', 'Subject' => '', 'Date' => '', 'To' => ''];
        foreach ($headers as $h) {
            if (isset($map[$h['name'] ?? ''])) {
                $map[$h['name']] = (string) ($h['value'] ?? '');
            }
        }

        return $map;
    }

    private function extractBody(array $payload): string
    {
        if (! empty($payload['body']['data'])) {
            return $this->decode($payload['body']['data']);
        }
        foreach ((array) ($payload['parts'] ?? []) as $part) {
            if (($part['mimeType'] ?? '') === 'text/plain' && ! empty($part['body']['data'])) {
                return $this->decode($part['body']['data']);
            }
        }
        // Влагане (multipart) — рекурсия в първия part с подсекции.
        foreach ((array) ($payload['parts'] ?? []) as $part) {
            if (! empty($part['parts'])) {
                $nested = $this->extractBody($part);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '(няма текстово тяло)';
    }

    private function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), false);
    }
}
