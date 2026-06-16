<?php

namespace App\Jobs;

use App\Models\FlowDraft;
use App\Models\FlowDraftMessage;
use App\Services\ClientFlowWizardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Един ход на разговорния създател на queue-то (като AssistantTurnJob).
 * Контролерът сийдва wizard_{token} и dispatch-ва това; wizard-ът поллва
 * токена до completed/failed. Върви на DEFAULT опашката (не на `flows`).
 */
class ClientWizardTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    public function __construct(
        public string $token,
        public int $draftId,
        public int $userMessageId,
        public int $replyMessageId,
    ) {}

    public function handle(ClientFlowWizardService $wizard): void
    {
        $cacheKey = "wizard_{$this->token}";

        $draft = FlowDraft::find($this->draftId);
        $userMessage = FlowDraftMessage::find($this->userMessageId);
        $reply = FlowDraftMessage::find($this->replyMessageId);

        if (! $draft || ! $userMessage || ! $reply) {
            $this->cacheMerge($cacheKey, ['status' => 'failed', 'error' => 'Заявката е невалидна.']);

            return;
        }

        $onStage = fn (string $stage) => $this->cacheMerge($cacheKey, ['status' => 'pending', 'stage' => $stage]);

        try {
            $result = $wizard->turn($draft, $userMessage, $onStage);

            $reply->update([
                'content' => $result['reply'] !== '' ? $result['reply'] : '…',
                'payload' => [
                    'phase' => $result['phase'],
                    'question' => $result['question'],
                    'recap' => $result['recap'],
                    'description_draft' => $result['description_draft'],
                    'suggested_title' => $result['suggested_title'],
                ],
                'cost_usd' => $result['cost_usd'],
                'status' => 'completed',
            ]);

            // Сървърната чернова следва бота (клиентът може да я override-ва визуално).
            $patch = ['status' => $result['phase'] === 'ready' ? 'ready' : 'interviewing'];
            if (filled($result['description_draft'])) {
                $patch['description'] = $result['description_draft'];
            }
            if (blank($draft->title) && filled($result['suggested_title'])) {
                $patch['title'] = $result['suggested_title'];
            }
            $draft->update($patch);

            Cache::put($cacheKey, [
                'status' => 'completed',
                'message_id' => $reply->id,
                'phase' => $result['phase'],
                'reply' => $reply->content,
                'question' => $result['question'],
                'description_draft' => $result['description_draft'],
                'recap' => $result['recap'],
                'suggested_title' => $result['suggested_title'],
                'progress' => $result['progress'] ?? null,
                'cost_usd' => $result['cost_usd'],
            ], now()->addMinutes(15));
        } catch (Throwable $e) {
            Log::error('[ClientWizardTurn] Failed: '.$e->getMessage());
            $reply->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Cache::put($cacheKey, ['status' => 'failed', 'error' => 'Възникна грешка. Опитай отново.'], now()->addMinutes(15));
        }
    }

    public function failed(?Throwable $e): void
    {
        $reply = FlowDraftMessage::find($this->replyMessageId);
        if (! $reply || $reply->status !== 'pending') {
            return;
        }

        $reply->update(['status' => 'failed', 'error' => 'Прекъснато (лимит на времето).']);
        Cache::put("wizard_{$this->token}", ['status' => 'failed', 'error' => 'Прекъснато (лимит на времето).'], now()->addMinutes(15));
    }

    private function cacheMerge(string $key, array $values): void
    {
        $current = (array) Cache::get($key, []);
        Cache::put($key, [...$current, ...$values, 'updated_at' => now()->timestamp], now()->addMinutes(15));
    }
}
