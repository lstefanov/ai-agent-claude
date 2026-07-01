<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar конектор (OAuth Google access_token, Calendar API v3). Read:
 * list_calendars, list_events, get_event. Write: create_event. Дава на
 * flow-овете контекст за срещи/график. Token refresh се прави в McpClientService.
 */
class GoogleCalendarConnector extends AbstractConnector
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    public function listTools(): array
    {
        $cal = ['label' => 'Календар', 'widget' => 'select', 'options' => 'calendar_calendars'];

        return [
            ['name' => 'calendar.list_calendars', 'description' => 'Календарите на акаунта', 'writes' => false,
                'parameters' => []],
            ['name' => 'calendar.list_events', 'description' => 'Събития в период (по подразбиране следващите 30 дни)', 'writes' => false,
                'parameters' => [
                    'calendar_id' => $cal,
                    'time_min' => ['label' => 'От (ISO, по избор)', 'widget' => 'text'],
                    'time_max' => ['label' => 'До (ISO, по избор)', 'widget' => 'text'],
                    'max' => ['label' => 'Брой', 'widget' => 'text'],
                ]],
            ['name' => 'calendar.get_event', 'description' => 'Едно събитие по ID', 'writes' => false,
                'parameters' => [
                    'calendar_id' => $cal,
                    'event_id' => ['label' => 'ID на събитие', 'widget' => 'text'],
                ]],
            ['name' => 'calendar.create_event', 'description' => 'Създава събитие', 'writes' => true,
                'parameters' => [
                    'calendar_id' => $cal,
                    'summary' => ['label' => 'Заглавие', 'widget' => 'text'],
                    'start' => ['label' => 'Начало (ISO, напр. 2026-07-05T10:00:00+03:00)', 'widget' => 'text'],
                    'end' => ['label' => 'Край (ISO)', 'widget' => 'text'],
                    'attendees' => ['label' => 'Участници (email-и през запетая, по избор)', 'widget' => 'text'],
                ]],
        ];
    }

    public function listOptions(string $source, array $context = []): array
    {
        if ($source !== 'calendar_calendars') {
            return [];
        }
        try {
            $res = $this->client()->get(self::BASE.'/users/me/calendarList', ['maxResults' => 100, 'fields' => 'items(id,summary)']);

            return collect((array) $res->json('items', []))
                ->map(fn ($c) => ['value' => (string) ($c['id'] ?? ''), 'label' => (string) ($c['summary'] ?? $c['id'] ?? '')])
                ->filter(fn ($o) => $o['value'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function testConnection(): bool
    {
        return $this->googleTokenValid($this->credentials['access_token'] ?? '');
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        try {
            return match ($tool) {
                'calendar.list_calendars' => $this->listCalendars(),
                'calendar.list_events' => $this->listEvents($params),
                'calendar.get_event' => $this->getEvent($params),
                'calendar.create_event' => $this->createEvent($params),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("Calendar грешка: {$e->getMessage()}");
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['access_token'] ?? ''))->acceptJson()->timeout(25);
    }

    private function calendarId(array $params): string
    {
        $id = trim((string) ($params['calendar_id'] ?? ''));

        return $id !== '' ? $id : 'primary';
    }

    private function listCalendars(): McpToolResult
    {
        $res = $this->client()->get(self::BASE.'/users/me/calendarList', ['maxResults' => 100, 'fields' => 'items(id,summary,primary)']);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $items = (array) $res->json('items', []);
        $lines = array_map(fn ($c) => '- '.($c['summary'] ?? '?').' ('.($c['id'] ?? '').')', $items);

        return McpToolResult::ok(count($items).' календара:'."\n".implode("\n", $lines), ['calendars' => $items]);
    }

    private function listEvents(array $params): McpToolResult
    {
        $timeMin = trim((string) ($params['time_min'] ?? '')) ?: now()->toRfc3339String();
        $timeMax = trim((string) ($params['time_max'] ?? '')) ?: now()->addDays(30)->toRfc3339String();

        $res = $this->client()->get(self::BASE.'/calendars/'.rawurlencode($this->calendarId($params)).'/events', [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => min(250, max(1, (int) ($params['max'] ?? 50))),
            'fields' => 'items(id,summary,start,end,location,attendees(email,responseStatus))',
        ]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $events = (array) $res->json('items', []);
        $lines = array_map(function ($e) {
            $start = $e['start']['dateTime'] ?? $e['start']['date'] ?? '?';

            return '- '.$start.' · '.($e['summary'] ?? '(без заглавие)').' ['.($e['id'] ?? '').']';
        }, $events);

        return McpToolResult::ok(count($events).' събития:'."\n".implode("\n", $lines), ['events' => $events]);
    }

    private function getEvent(array $params): McpToolResult
    {
        $eventId = trim((string) ($params['event_id'] ?? ''));
        if ($eventId === '') {
            return McpToolResult::fail('Липсва event_id');
        }

        $res = $this->client()->get(self::BASE.'/calendars/'.rawurlencode($this->calendarId($params)).'/events/'.rawurlencode($eventId));
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $e = (array) $res->json();
        $start = $e['start']['dateTime'] ?? $e['start']['date'] ?? '?';
        $end = $e['end']['dateTime'] ?? $e['end']['date'] ?? '?';
        $text = ($e['summary'] ?? '(без заглавие)')."\n".$start.' → '.$end.(! empty($e['location']) ? "\n".$e['location'] : '');

        return McpToolResult::ok($text, ['event' => $e]);
    }

    private function createEvent(array $params): McpToolResult
    {
        $start = trim((string) ($params['start'] ?? ''));
        $end = trim((string) ($params['end'] ?? ''));
        if ($start === '' || $end === '') {
            return McpToolResult::fail('Трябва start и end (ISO datetime)');
        }

        $attendees = collect(explode(',', (string) ($params['attendees'] ?? '')))
            ->map(fn ($e) => trim($e))
            ->filter()
            ->map(fn ($e) => ['email' => $e])
            ->values()->all();

        $body = [
            'summary' => (string) ($params['summary'] ?? 'Ново събитие'),
            'start' => ['dateTime' => $start],
            'end' => ['dateTime' => $end],
        ];
        if ($attendees !== []) {
            $body['attendees'] = $attendees;
        }

        $res = $this->client()->post(self::BASE.'/calendars/'.rawurlencode($this->calendarId($params)).'/events', $body);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok('Създадено събитие: '.($res->json('summary') ?? '').' (id: '.$res->json('id', '?').')', ['id' => $res->json('id')]);
    }
}
