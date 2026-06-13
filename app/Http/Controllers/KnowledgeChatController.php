<?php

namespace App\Http\Controllers;

use App\Jobs\IngestResourceJob;
use App\Jobs\KnowledgeChatTurnJob;
use App\Models\Company;
use App\Models\KnowledgeChatMessage;
use App\Services\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Чатът "Тествай знанията" на страницата на базата знания — по конвенциите
 * на FlowAssistantController: send (dispatch на KnowledgeChatTurnJob на
 * default опашката), poll по token, history per session.
 */
class KnowledgeChatController extends Controller
{
    public function send(Request $request, Company $company, KnowledgeService $knowledge): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|min:1|max:2000',
            'session' => 'nullable|uuid',
        ]);

        if ($knowledge->isEmpty($company)) {
            return response()->json([
                'error' => 'Базата знания е празна — добави ресурс (URL, файл или бележка), за да има в какво да се търси.',
            ], 422);
        }

        $session = (string) ($request->input('session') ?: Str::uuid());

        $userMessage = KnowledgeChatMessage::create([
            'company_id' => $company->id,
            'session' => $session,
            'role' => 'user',
            'content' => (string) $request->input('message'),
            'status' => 'completed',
        ]);

        $reply = KnowledgeChatMessage::create([
            'company_id' => $company->id,
            'session' => $session,
            'role' => 'assistant',
            'status' => 'pending',
        ]);

        $token = Str::uuid()->toString();

        Cache::put("kb_chat_{$token}", [
            'status' => 'pending',
            'stage' => 'Мисля…',
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(15));

        KnowledgeChatTurnJob::dispatch($token, $company->id, $userMessage->id, $reply->id);

        return response()->json(['token' => $token, 'session' => $session, 'reply_id' => $reply->id]);
    }

    public function status(string $token): JsonResponse
    {
        $result = Cache::get("kb_chat_{$token}");

        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът е изтекъл. Изпрати въпроса отново.'], 404);
        }

        return response()->json($result);
    }

    public function history(Request $request, Company $company): JsonResponse
    {
        $session = (string) ($request->query('session')
            ?: KnowledgeChatMessage::where('company_id', $company->id)->latest('id')->value('session')
            ?: '');

        if ($session === '') {
            return response()->json(['session' => null, 'messages' => []]);
        }

        $messages = KnowledgeChatMessage::where('company_id', $company->id)
            ->where('session', $session)
            ->where('status', '!=', 'pending')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->status === 'failed' ? ($m->error ?: 'Грешка.') : $m->content,
                'failed' => $m->status === 'failed',
                'sources' => $m->sources ?? [],
                'source_type' => $m->source_type,
                'feedback' => $m->feedback,
                'cost_usd' => $m->cost_usd,
            ])
            ->values();

        return response()->json(['session' => $session, 'messages' => $messages]);
    }

    /** Списък минали разговори (сесии) за страничната лента / dropdown-а. */
    public function sessions(Company $company): JsonResponse
    {
        $rows = KnowledgeChatMessage::where('company_id', $company->id)
            ->where('status', '!=', 'pending')
            ->selectRaw('session, MAX(created_at) as last_at, COUNT(*) as cnt')
            ->groupBy('session')
            ->orderByDesc('last_at')
            ->limit(30)
            ->get();

        // Първото потребителско съобщение на всяка сесия = заглавието ѝ.
        $firstIds = KnowledgeChatMessage::where('company_id', $company->id)
            ->whereIn('session', $rows->pluck('session'))
            ->where('role', 'user')
            ->selectRaw('session, MIN(id) as first_id')
            ->groupBy('session')
            ->pluck('first_id', 'session');

        $titles = KnowledgeChatMessage::whereIn('id', $firstIds->values())->pluck('content', 'id');

        $sessions = $rows->map(function ($row) use ($firstIds, $titles) {
            $firstId = $firstIds[$row->session] ?? null;
            $title = $firstId ? trim((string) ($titles[$firstId] ?? '')) : '';

            return [
                'session' => $row->session,
                'title' => mb_substr($title !== '' ? $title : 'Разговор', 0, 80),
                'last_at' => $row->last_at ? Carbon::parse($row->last_at)->format('d.m H:i') : '',
                'count' => (int) $row->cnt,
            ];
        })->values();

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * 👍/👎 на интернет-отговор. 👍 промотира въпроса/отговора в знанието
     * като нов ресурс тип `chat` (минава през ingest → чанкове + факти), за
     * да го намира базата следващия път. 👎 само се записва.
     */
    public function feedback(Request $request, Company $company, KnowledgeChatMessage $message): JsonResponse
    {
        abort_unless($message->company_id === $company->id, 404);

        $data = $request->validate(['vote' => 'required|in:up,down']);

        if ($message->role !== 'assistant' || $message->source_type !== 'web') {
            return response()->json(['error' => 'Само интернет-отговорите може да се записват в знанието.'], 422);
        }

        if ($data['vote'] === 'down') {
            $message->update(['feedback' => 'down']);

            return response()->json(['ok' => true]);
        }

        $message->update(['feedback' => 'up']);

        // Вече записан → не дублираме ресурса.
        if ($message->saved_resource_id) {
            return response()->json(['ok' => true, 'saved_resource_id' => $message->saved_resource_id]);
        }

        $question = trim((string) KnowledgeChatMessage::where('company_id', $company->id)
            ->where('session', $message->session)
            ->where('role', 'user')
            ->where('id', '<', $message->id)
            ->latest('id')
            ->value('content'));

        $answer = trim((string) $message->content);

        $urls = collect($message->sources ?? [])
            ->pluck('url')->filter()->unique()->values()
            ->map(fn ($u) => "- {$u}")->implode("\n");

        $content = "Въпрос: {$question}\n\nОтговор: {$answer}".($urls !== '' ? "\n\nИзточници:\n{$urls}" : '');

        $resource = $company->knowledgeResources()->create([
            'type' => 'chat',
            'title' => mb_substr($question !== '' ? $question : $answer, 0, 120),
            'content' => $content,
            'status' => 'pending',
        ]);

        $message->update(['saved_resource_id' => $resource->id]);
        IngestResourceJob::dispatch($resource->id);

        return response()->json(['ok' => true, 'saved_resource_id' => $resource->id], 201);
    }
}
