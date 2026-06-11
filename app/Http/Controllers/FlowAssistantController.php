<?php

namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Models\AssistantNote;
use App\Models\Flow;
use App\Services\BuilderAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Builder Copilot endpoints: send a chat message (spawns the background
 * flows:assistant-turn process), poll its token, load history, manage notes.
 */
class FlowAssistantController extends Controller
{
    public function send(Request $request, Flow $flow, BuilderAssistantService $assistant): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|min:1|max:6000',
            'mode' => 'nullable|in:edit,run,view',
            'session' => 'nullable|uuid',
            'graph' => 'nullable|array',
        ]);

        if (! $assistant->isAvailable()) {
            $provider = $assistant->providerModel()['provider'];

            return response()->json([
                'error' => "Асистентът изисква cloud провайдър с tool calling — липсва API ключ за „{$provider}“. "
                    .'Задай BUILDER_ASSISTANT_PROVIDER/BUILDER_ASSISTANT_MODEL (или API ключ) в .env.',
            ], 503);
        }

        $session = (string) ($request->input('session') ?: Str::uuid());

        $userMessage = AssistantMessage::create([
            'flow_id' => $flow->id,
            'session' => $session,
            'role' => 'user',
            'content' => (string) $request->input('message'),
            'status' => 'completed',
        ]);

        $reply = AssistantMessage::create([
            'flow_id' => $flow->id,
            'session' => $session,
            'role' => 'assistant',
            'status' => 'pending',
        ]);

        $token = Str::uuid()->toString();

        Cache::put("assistant_request_{$token}", [
            'flow_id' => $flow->id,
            'user_message_id' => $userMessage->id,
            'reply_message_id' => $reply->id,
            'graph' => $request->input('graph'),
            'mode' => (string) $request->input('mode', 'edit'),
        ], now()->addMinutes(15));

        Cache::put("assistant_{$token}", [
            'status' => 'pending',
            'stage' => 'Мисля…',
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(15));

        // Background artisan command — the same survives-the-request pattern
        // as flows:generate-agents (no queue worker dependency).
        $php = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tok = escapeshellarg($token);
        exec("{$php} {$artisan} flows:assistant-turn {$tok} >> ".escapeshellarg(storage_path('logs/assistant.log')).' 2>&1 &');

        return response()->json(['token' => $token, 'session' => $session, 'reply_id' => $reply->id]);
    }

    public function status(string $token): JsonResponse
    {
        $result = Cache::get("assistant_{$token}");

        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът е изтекъл. Изпрати съобщението отново.'], 404);
        }

        return response()->json($result);
    }

    /**
     * Messages of one conversation thread — the given session, or the flow's
     * latest one. Ops/ui are NOT replayed on reload (the canvas already
     * reflects whatever the user kept), so history ships text + cost only.
     */
    public function history(Request $request, Flow $flow): JsonResponse
    {
        $session = (string) ($request->query('session')
            ?: $flow->assistantMessages()->latest('id')->value('session')
            ?: '');

        if ($session === '') {
            return response()->json(['session' => null, 'messages' => []]);
        }

        $messages = $flow->assistantMessages()
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
                'cost_usd' => $m->cost_usd,
                'has_ops' => ! empty($m->ops),
            ])
            ->values();

        return response()->json(['session' => $session, 'messages' => $messages]);
    }

    public function notes(Flow $flow): JsonResponse
    {
        $notes = AssistantNote::where('company_id', $flow->company_id)
            ->where(fn ($q) => $q->whereNull('flow_id')->orWhere('flow_id', $flow->id))
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'note' => $n->note,
                'scope' => $n->flow_id ? 'flow' : 'company',
                'created_at' => $n->created_at?->toDateTimeString(),
            ]);

        return response()->json(['notes' => $notes]);
    }

    public function destroyNote(AssistantNote $note): JsonResponse
    {
        $note->delete();

        return response()->json(['ok' => true]);
    }
}
