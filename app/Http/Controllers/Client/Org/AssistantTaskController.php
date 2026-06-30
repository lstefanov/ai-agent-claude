<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\TaskRunService;
use App\Support\ModelLevel;
use App\Support\QueueHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Контрол на задачите: per-task ниво (Фаза 2), генерация на Flow + ръчно пускане (Фаза 3).
 * Цялата логика минава през TaskRunService (споделен с director tick/callback) — никаква
 * дублирана генерационна логика, никакво синхронно чакане.
 */
class AssistantTaskController extends Controller
{
    /** „Генерирай" — материализира задачата във Flow през launcher-а (без авто-run). */
    public function generate(AssistantTask $task, TaskRunService $runner): JsonResponse
    {
        $this->authorizeTask($task);
        $result = $runner->generate($task, runAfterGenerate: false);

        return response()->json($result);
    }

    /** Поллинг на генерацията (същия глобален cache като wizard-а). */
    public function genStatus(AssistantTask $task, string $token): JsonResponse
    {
        $this->authorizeTask($task);
        $status = Cache::get("agent_gen_{$token}");
        if (! $status) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът изтече.'], 404);
        }

        return response()->json($status + ['task_status' => $task->fresh()->status]);
    }

    /**
     * „Изпълни": готова задача → wallet гейт + FlowRun с org-контекст; без flow_id →
     * асинхронна генерация + run_after_generate (без синхронно чакане). Недостиг → 402.
     */
    public function run(AssistantTask $task, TaskRunService $runner): JsonResponse
    {
        $this->authorizeTask($task);

        if (! QueueHeartbeat::flowsAlive()) {
            return response()->json(['message' => 'Системата за изпълнение не е активна. Опитай след малко.'], 503);
        }

        try {
            $result = $runner->requestRun($task, runAfterGenerate: true);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'message' => 'Недостатъчно кредити за пускане.',
                'needed' => $e->needed,
                'available' => $e->available,
                'upsell' => true,
            ], 402);
        } catch (KnowledgeRequiredException $e) {
            // Гейт по знание: задачата чака знание → UI отваря popup „Добави знания".
            return response()->json([
                'message' => 'Нужни са знания преди изпълнение.',
                'needs_knowledge' => true,
                'requirements' => $e->requirements,
            ], 422);
        } catch (\RuntimeException $e) {
            // Status-машина: задачата не е в пускаемо състояние (напр. чака одобрение).
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (($result['status'] ?? null) === 'running' && isset($result['run_id'])) {
            $result['poll_url'] = route('client.runs.progress', $result['run_id']);
        }

        return response()->json($result);
    }

    /** Per-task ниво: задава явен override или го маха (null = наследява члена). */
    public function setTier(Request $request, AssistantTask $task): JsonResponse
    {
        $this->authorizeTask($task);

        $raw = $request->input('tier');
        // Празно/„inherit" → null (наследява нивото на члена).
        $tier = in_array($raw, [null, '', 'inherit'], true) ? null : ModelLevel::tryFrom((string) $raw)?->value;
        if ($raw !== null && $raw !== '' && $raw !== 'inherit' && $tier === null) {
            return response()->json(['ok' => false, 'error' => 'Невалидно ниво.'], 422);
        }

        $task->update(['star_tier' => $tier]);

        return response()->json([
            'ok' => true,
            'inherits' => $task->inheritsTier(),
            'effective' => $task->effectiveStarTier()->value,
        ]);
    }

    /**
     * Гейт по знание — добавя бележка в базата на фирмата (async ingest). UI после поллва
     * knowledgeStatus до приключване и пре-оценка.
     */
    public function addKnowledgeNote(Request $request, AssistantTask $task): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $task->orgMember?->company;
        abort_unless((bool) $company, 404);

        $data = $request->validate([
            'title' => 'required|string|max:300',
            'content' => 'required|string|min:3|max:100000',
        ]);

        $resource = $company->knowledgeResources()->create([
            'type' => 'note',
            'title' => 'Знание за задача: '.trim($data['title']),
            'content' => $data['content'],
            'status' => 'pending',
        ]);

        IngestResourceJob::dispatch($resource->id);

        return response()->json(['resource_id' => $resource->id], 201);
    }

    /** Гейт по знание — Управителят разрешава уеб-търсене за публични изисквания. */
    public function ackKnowledge(Request $request, AssistantTask $task, KnowledgeRequirementService $kr): JsonResponse
    {
        $this->authorizeTask($task);

        $keys = array_values(array_filter((array) $request->input('keys', []), 'is_string'));
        $task->knowledgeRequirements()
            ->where('sourceability', 'public')
            ->whereIn('key', $keys)
            ->update(['acknowledged' => true]);

        // Пре-оцени статуса (acknowledged може да отпуши публично изискване).
        $kr->evaluate($task);
        $task->refresh();

        return response()->json(['knowledge_status' => $task->knowledge_status, 'requirements' => $this->knowledgeRequirements($task)]);
    }

    /**
     * Гейт по знание — поллинг + „Провери". Докато тече ingest → чакай. Иначе пре-оцени и при
     * флип към достатъчно знание тръгни по правилния път: готова задача (с flow) → пускане;
     * preflight-паркирана (без flow) → генерация.
     */
    public function knowledgeStatus(AssistantTask $task, KnowledgeRequirementService $kr, TaskRunService $runner): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $task->orgMember?->company;

        // Чанковете се embed-ват ПРЕДИ ресурсът да стане ready → докато тече ingest, не оценявай.
        if ($company?->knowledgeResources()->whereIn('status', ['pending', 'processing'])->exists()) {
            return response()->json(['ingesting' => true]);
        }

        // Последно добавеният ресурс се е провалил при ingest → покажи грешка в UI.
        $last = $company?->knowledgeResources()->latest('id')->first();
        if ($last && $last->status === 'failed') {
            return response()->json(['ingest_failed' => true, 'message' => 'Обработката на знанието се провали. Опитай отново.']);
        }

        // TTL-aware: пре-оценява само ако има ново знание след последната оценка (евтино иначе).
        $kr->evaluate($task);
        $task->refresh();

        if ($task->knowledge_status === 'ready') {
            // Готова задача (има flow) с durable намерение → пусни веднага.
            if ($task->status === 'ready' && $task->flow_id && $task->run_after_generate) {
                try {
                    $run = $runner->launchReadyRun($task);
                    $task->update(['run_after_generate' => false]);

                    return response()->json([
                        'status' => 'running',
                        'run_id' => $run->id,
                        'poll_url' => route('client.runs.progress', $run->id),
                    ]);
                } catch (InsufficientCreditsException) {
                    return response()->json(['knowledge_status' => 'ready', 'message' => 'Недостатъчно кредити за пускане.', 'requirements' => $this->knowledgeRequirements($task)]);
                } catch (KnowledgeRequiredException) {
                    // Появи се ново изискване между оценката и пускането — върни актуалното.
                    $task->refresh();
                }
            }

            // Preflight-паркирана задача (още без flow) → тръгни към генерация.
            if (in_array($task->status, ['proposed', 'failed'], true) && ! $task->flow_id) {
                $result = $runner->generate($task, runAfterGenerate: $task->run_after_generate, firstReviewDone: true);

                return response()->json($result);
            }
        }

        return response()->json(['knowledge_status' => $task->knowledge_status, 'requirements' => $this->knowledgeRequirements($task)]);
    }

    /** @return array<int, array<string, mixed>> Изискванията за UI (popup). */
    private function knowledgeRequirements(AssistantTask $task): array
    {
        return $task->knowledgeRequirements()->get()->map(fn ($r) => [
            'key' => $r->key,
            'label' => $r->label,
            'sourceability' => $r->sourceability,
            'status' => $r->status,
            'acknowledged' => $r->acknowledged,
            'how_to_provide' => $r->how_to_provide,
        ])->all();
    }

    private function authorizeTask(AssistantTask $task): void
    {
        abort_unless($task->orgMember?->company_id === (int) session('client_company_id'), 403);
    }
}
