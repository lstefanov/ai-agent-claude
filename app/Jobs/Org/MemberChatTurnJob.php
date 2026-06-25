<?php

namespace App\Jobs\Org;

use App\Models\MemberChat;
use App\Models\MemberMessage;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\MemberChatService;
use App\Support\BillableUnit;
use App\Support\LlmContext;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Чат ход с член (§4.4) — `org` queue (НЕ default). Огледало на AssistantTurnJob:
 * вика MemberChatService::turn (billable контекст member_chat), записва assistant
 * съобщението, ъпдейтва `member_chat_{token}` кеша за поллинг.
 */
class MemberChatTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $token, public int $chatId, public int $userMessageId, public int $replyMessageId) {}

    public function handle(MemberChatService $chatService, CreditMeterService $meter): void
    {
        $key = "member_chat_{$this->token}";
        $chat = MemberChat::find($this->chatId);
        $userMessage = MemberMessage::find($this->userMessageId);
        $reply = MemberMessage::find($this->replyMessageId);

        if (! $chat || ! $userMessage || ! $reply) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Невалидна заявка.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        $reservation = null;
        try {
            $reservation = $meter->reserve(
                $chat->company_id, 'member_chat', $chat->orgMember,
                BillableUnit::estimateFor('member_chat', ModelLevel::Medium),
            );
        } catch (InsufficientCreditsException) {
            Log::info('[MemberChat] best-effort (no credits) company '.$chat->company_id);
        }

        if ($reservation) {
            LlmContext::set([
                'purpose' => 'member_chat',
                'company_id' => $chat->company_id,
                'context_type' => 'member_chat',
                'subject_type' => $chat->orgMember->getMorphClass(),
                'subject_id' => $chat->org_member_id,
                'reservation_id' => $reservation->id,
            ]);
        }

        try {
            $onStage = fn (string $s) => Cache::put($key, ['status' => 'pending', 'stage' => $s, 'updated_at' => now()->timestamp], now()->addMinutes(15));
            $result = $chatService->turn($chat, $userMessage, $onStage);

            $reply->update([
                'content' => $result['reply'],
                'payload' => ['proposal' => $result['proposal']],
                'cost_usd' => $result['cost_usd'],
                'status' => 'completed',
            ]);
            $chat->update(['last_message_at' => now()]);

            Cache::put($key, [
                'status' => 'completed',
                'reply' => $result['reply'],
                'proposal' => $result['proposal'],
                'message_id' => $reply->id,
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(15));
        } catch (\Throwable $e) {
            Log::error('[MemberChat] failed: '.$e->getMessage());
            $reply->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Cache::put($key, ['status' => 'failed', 'error' => 'Грешка в чата. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(15));
        } finally {
            if ($reservation) {
                LlmContext::clear();
                $meter->settle($reservation, $meter->actualFor($reservation));
            }
        }
    }
}
