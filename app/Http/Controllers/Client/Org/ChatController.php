<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\MemberChatTurnJob;
use App\Models\MemberChat;
use App\Models\MemberMessage;
use App\Models\OrgMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Персона-консистентен чат с всеки член (§4.4) — token-poll, като клиентския wizard.
 */
class ChatController extends Controller
{
    public function show(OrgMember $member)
    {
        $this->authorizeMember($member);

        $chat = MemberChat::firstOrCreate([
            'company_id' => $member->company_id,
            'org_member_id' => $member->id,
        ]);

        $messages = $chat->messages()->orderBy('id')->get();
        $proposalIds = $messages
            ->map(function (MemberMessage $m) {
                $proposal = $m->payload['proposal'] ?? null;

                return is_array($proposal) ? ($proposal['id'] ?? null) : null;
            })
            ->filter()
            ->unique()
            ->values();
        $proposalStatuses = OrgProposal::whereIn('id', $proposalIds)->pluck('status', 'id');

        return view('client.org.chat', [
            'member' => $member->load('persona'),
            'chat' => $chat,
            'messages' => $messages,
            'proposalStatuses' => $proposalStatuses,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'chat_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $chat = MemberChat::findOrFail($request->integer('chat_id'));
        abort_unless($chat->company_id === (int) session('client_company_id'), 403);

        $userMessage = MemberMessage::create([
            'member_chat_id' => $chat->id,
            'role' => 'user',
            'content' => trim((string) $request->input('message')),
            'status' => 'completed',
        ]);
        $reply = MemberMessage::create([
            'member_chat_id' => $chat->id,
            'role' => 'assistant',
            'status' => 'pending',
        ]);

        $token = (string) Str::uuid();
        Cache::put("member_chat_{$token}", ['status' => 'pending', 'stage' => 'Мисля…', 'updated_at' => now()->timestamp], now()->addMinutes(15));
        MemberChatTurnJob::dispatch($token, $chat->id, $userMessage->id, $reply->id)->onQueue('org');

        return response()->json(['token' => $token]);
    }

    public function status(string $token): JsonResponse
    {
        $result = Cache::get("member_chat_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът изтече.'], 404);
        }

        return response()->json($result);
    }

    private function authorizeMember(OrgMember $member): void
    {
        abort_unless($member->company_id === (int) session('client_company_id'), 403);
    }
}
