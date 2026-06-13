<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\KnowledgeChatMessage;
use App\Services\Knowledge\KnowledgeChatService;
use App\Support\LlmUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Един ход на чата "Тествай знанията" — по конвенцията на AssistantTurnJob:
 * контролерът записва user + pending reply, seed-ва kb_chat_{token} в кеша и
 * dispatch-ва този job; страницата полва токена до completed/failed.
 * DEFAULT queue — никога не се състезава с node изпълнението.
 */
class KnowledgeChatTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Локалният модел може да е бавен; под supervisor-default timeout (900). */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public string $token,
        public int $companyId,
        public int $userMessageId,
        public int $replyMessageId,
    ) {}

    public function handle(KnowledgeChatService $chat): void
    {
        $cacheKey = "kb_chat_{$this->token}";

        $company = Company::find($this->companyId);
        $userMessage = KnowledgeChatMessage::find($this->userMessageId);
        $reply = KnowledgeChatMessage::find($this->replyMessageId);

        if (! $company || ! $userMessage || ! $reply) {
            Log::error("[KnowledgeChat] Missing company/messages for token {$this->token}");
            $this->cacheMerge($cacheKey, ['status' => 'failed', 'error' => 'Заявката е невалидна.']);

            return;
        }

        $onStage = fn (string $stage) => $this->cacheMerge($cacheKey, ['status' => 'pending', 'stage' => $stage]);

        try {
            $result = $chat->turn($company, (string) $userMessage->content, (string) $userMessage->session, $onStage);

            $reply->update([
                'content' => $result['content'],
                'sources' => $result['sources'] ?: null,
                'source_type' => $result['source_type'] ?? 'kb',
                'cost_usd' => $result['cost_usd'],
                'status' => 'completed',
            ]);

            Cache::put($cacheKey, [
                'status' => 'completed',
                'message' => [
                    'id' => $reply->id,
                    'role' => 'assistant',
                    'content' => $reply->content,
                    'sources' => $reply->sources ?? [],
                    'source_type' => $reply->source_type,
                    'feedback' => $reply->feedback,
                    'cost_usd' => $reply->cost_usd,
                ],
            ], now()->addMinutes(15));
        } catch (Throwable $e) {
            LlmUsage::take();
            Log::error('[KnowledgeChat] Failed: '.$e->getMessage());

            $reply->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Cache::put($cacheKey, ['status' => 'failed', 'error' => $e->getMessage()], now()->addMinutes(15));
        }
    }

    public function failed(?Throwable $e): void
    {
        $reply = KnowledgeChatMessage::find($this->replyMessageId);
        if (! $reply || $reply->status !== 'pending') {
            return; // handle() already recorded the outcome
        }

        $error = "Отговорът надхвърли лимита от {$this->timeout}s и беше прекъснат."
            .($e ? ' ('.$e->getMessage().')' : '');

        $reply->update(['status' => 'failed', 'error' => $error]);
        Cache::put("kb_chat_{$this->token}", ['status' => 'failed', 'error' => $error], now()->addMinutes(15));
    }

    private function cacheMerge(string $key, array $values): void
    {
        $current = (array) Cache::get($key, []);
        Cache::put($key, [...$current, ...$values, 'updated_at' => now()->timestamp], now()->addMinutes(15));
    }
}
