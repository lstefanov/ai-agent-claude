<?php

namespace App\Http\Controllers;

use App\Jobs\KnowledgeChatTurnJob;
use App\Models\Company;
use App\Models\KnowledgeChatMessage;
use App\Services\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'cost_usd' => $m->cost_usd,
            ])
            ->values();

        return response()->json(['session' => $session, 'messages' => $messages]);
    }
}
