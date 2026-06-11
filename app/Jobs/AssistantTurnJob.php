<?php

namespace App\Jobs;

use App\Models\AssistantMessage;
use App\Models\Flow;
use App\Services\BuilderAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Builder Copilot turn on the queue: the controller persists the user
 * message + pending reply, seeds the assistant_{token} cache entry and
 * dispatches this job; the builder polls the token until completed/failed.
 *
 * Runs on the DEFAULT queue (the queue:listen worker) — the flows queue stays
 * dedicated to node execution so a long chat turn can't starve a run.
 */
class AssistantTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Worst case ≈ 8 steps × 180s http_timeout + 120s wrap-up ≈ 1560s; the cap
     * must stay under DB_QUEUE_RETRY_AFTER (1800s) so a slow turn is never
     * handed to a second worker mid-flight.
     */
    public int $timeout = 1500;

    public int $tries = 1;

    public function __construct(
        public string $token,
        public int $flowId,
        public int $userMessageId,
        public int $replyMessageId,
        public ?array $graph,
        public string $mode,
    ) {}

    public function handle(BuilderAssistantService $assistant): void
    {
        $cacheKey = "assistant_{$this->token}";

        $flow = Flow::find($this->flowId);
        $userMessage = AssistantMessage::find($this->userMessageId);
        $reply = AssistantMessage::find($this->replyMessageId);

        if (! $flow || ! $userMessage || ! $reply) {
            Log::error("[AssistantTurn] Missing flow/messages for token {$this->token}");
            $this->cacheMerge($cacheKey, ['status' => 'failed', 'error' => 'Заявката е невалидна (изтрит flow или съобщение).']);

            return;
        }

        $onStage = fn (string $stage) => $this->cacheMerge($cacheKey, ['status' => 'pending', 'stage' => $stage]);

        // Pseudo-streaming: every model text lands in the cache as it arrives;
        // the builder's poll renders the growing partial in the live bubble.
        $partial = '';
        $onPartial = function (string $content) use (&$partial, $cacheKey): void {
            $partial .= ($partial === '' ? '' : "\n\n").$content;
            $this->cacheMerge($cacheKey, ['status' => 'pending', 'partial' => $partial]);
        };

        try {
            $result = $assistant->turn($flow, $userMessage, $this->graph, $this->mode, $onStage, $onPartial);

            $reply->update([
                'content' => $result['content'] !== '' ? $result['content'] : 'Готово.',
                'ops' => $result['ops'] ?: null,
                'ui' => $result['ui'] ?: null,
                'cost_usd' => $result['cost_usd'],
                'status' => 'completed',
            ]);

            Cache::put($cacheKey, [
                'status' => 'completed',
                'message' => [
                    'id' => $reply->id,
                    'role' => 'assistant',
                    'content' => $reply->content,
                    'ops' => $reply->ops ?? [],
                    'ui' => $reply->ui ?? [],
                    'cost_usd' => $reply->cost_usd,
                ],
            ], now()->addMinutes(15));

            Log::info("[AssistantTurn] Completed for flow {$flow->id} in {$result['steps']} steps");
        } catch (Throwable $e) {
            Log::error('[AssistantTurn] Failed: '.$e->getMessage());

            $reply->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Cache::put($cacheKey, ['status' => 'failed', 'error' => $e->getMessage()], now()->addMinutes(15));
        }
    }

    /**
     * The worker killed the job (timeout) or it died outside handle()'s
     * try/catch — handle() couldn't record anything, do it here.
     */
    public function failed(?Throwable $e): void
    {
        $reply = AssistantMessage::find($this->replyMessageId);
        if (! $reply || $reply->status !== 'pending') {
            return; // handle() already recorded the outcome
        }

        $error = "Асистентът надхвърли лимита от {$this->timeout}s и беше прекъснат."
            .($e ? ' ('.$e->getMessage().')' : '');

        $reply->update(['status' => 'failed', 'error' => $error]);
        Cache::put("assistant_{$this->token}", ['status' => 'failed', 'error' => $error], now()->addMinutes(15));
    }

    private function cacheMerge(string $key, array $values): void
    {
        $current = (array) Cache::get($key, []);
        Cache::put($key, [...$current, ...$values, 'updated_at' => now()->timestamp], now()->addMinutes(15));
    }
}
