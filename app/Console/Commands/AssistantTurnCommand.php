<?php

namespace App\Console\Commands;

use App\Models\AssistantMessage;
use App\Models\Flow;
use App\Services\BuilderAssistantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Builder Copilot turn in the background (same pattern as
 * flows:generate-agents): the controller caches the request under a token and
 * spawns this command; the builder polls the token until completed/failed.
 */
class AssistantTurnCommand extends Command
{
    protected $signature = 'flows:assistant-turn {token : Cache token for this assistant request}';

    protected $description = 'Run one Builder Copilot turn in the background and store the result in cache';

    public function handle(BuilderAssistantService $assistant): int
    {
        $token = $this->argument('token');
        $cacheKey = "assistant_{$token}";

        $request = Cache::get("assistant_request_{$token}");
        if (! $request) {
            Log::error("[AssistantTurn] Token not found in cache: {$token}");

            return Command::FAILURE;
        }

        $flow = Flow::find($request['flow_id'] ?? 0);
        $userMessage = AssistantMessage::find($request['user_message_id'] ?? 0);
        $reply = AssistantMessage::find($request['reply_message_id'] ?? 0);

        if (! $flow || ! $userMessage || ! $reply) {
            Log::error("[AssistantTurn] Missing flow/messages for token {$token}");
            Cache::put($cacheKey, ['status' => 'failed', 'error' => 'Заявката е невалидна (изтрит flow или съобщение).'], now()->addMinutes(15));

            return Command::FAILURE;
        }

        $onStage = function (string $stage) use ($cacheKey): void {
            $current = Cache::get($cacheKey, []);
            $current['status'] = 'pending';
            $current['stage'] = $stage;
            $current['updated_at'] = now()->timestamp;
            Cache::put($cacheKey, $current, now()->addMinutes(15));
        };

        try {
            $result = $assistant->turn(
                $flow,
                $userMessage,
                is_array($request['graph'] ?? null) ? $request['graph'] : null,
                (string) ($request['mode'] ?? 'edit'),
                $onStage,
            );

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

            return Command::SUCCESS;
        } catch (Throwable $e) {
            Log::error('[AssistantTurn] Failed: '.$e->getMessage());

            $reply->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Cache::put($cacheKey, ['status' => 'failed', 'error' => $e->getMessage()], now()->addMinutes(15));

            return Command::FAILURE;
        }
    }
}
