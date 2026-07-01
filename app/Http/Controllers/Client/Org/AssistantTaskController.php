<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\CompanyConnector;
use App\Services\BraveSearchService;
use App\Services\Knowledge\KnowledgeIntakeService;
use App\Services\Knowledge\KnowledgeRequirementService;
use App\Services\KnowledgeService;
use App\Services\McpClientService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\KnowledgeRequiredException;
use App\Services\Org\TaskRunService;
use App\Services\PerplexitySearchService;
use App\Support\ModelLevel;
use App\Support\QueueHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Контрол на задачите: per-task ниво (Фаза 2), генерация на Flow + ръчно пускане (Фаза 3)
 * и гейтът по знание (Фаза 5) — многоизточников попъп „Добави знания". Цялата генерационна
 * логика минава през TaskRunService; всичкото добавяне на знание минава през
 * KnowledgeIntakeService (един път, споделен с порталната База знания).
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

    // ──────────────────────────────────────────────────────────────────────
    // Гейт по знание — многоизточников попъп „Добави знания"
    // ──────────────────────────────────────────────────────────────────────

    /** Бележка (заглавие + текст) → note ресурс, тагнат за задачата. */
    public function addKnowledgeNote(Request $request, AssistantTask $task, KnowledgeIntakeService $intake): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $data = $request->validate([
            'title' => 'required|string|max:300',
            'content' => 'required|string|min:3|max:100000',
            'requirement_key' => 'nullable|string|max:200',
        ]);

        $resource = $intake->fromText($company, 'Знание за задача: '.trim($data['title']), $data['content'], [
            'source' => 'task_note',
            'assistant_task_id' => $task->id,
            'requirement_key' => $request->input('requirement_key'),
        ]);

        return response()->json(['resource_id' => $resource->id], 201);
    }

    /** Качени файлове → upload/image ресурси. */
    public function uploadKnowledge(Request $request, AssistantTask $task, KnowledgeIntakeService $intake): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);
        $maxKb = (int) config('services.knowledge.max_file_mb', 20) * 1024;

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => "required|file|max:{$maxKb}|extensions:pdf,txt,md,csv,xlsx,xls,docx,jpg,jpeg,png",
            'requirement_key' => 'nullable|string|max:200',
        ]);

        $ctx = [
            'source' => 'task_upload',
            'assistant_task_id' => $task->id,
            'requirement_key' => $request->input('requirement_key'),
        ];

        $created = [];
        $skipped = [];
        foreach ($request->file('files', []) as $file) {
            $resource = $intake->fromUpload($company, $file, $ctx);
            if ($resource === null) {
                $skipped[] = $file->getClientOriginalName();

                continue;
            }
            $created[] = $resource->id;
        }

        return response()->json(['created' => $created, 'skipped' => $skipped]);
    }

    /** URL/сайт → url ресурс (BFS краул, като порталната База знания). */
    public function addUrl(Request $request, AssistantTask $task, KnowledgeIntakeService $intake): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $data = $request->validate([
            'url' => 'required|string|max:2048|url:http,https',
            'requirement_key' => 'nullable|string|max:200',
        ]);

        try {
            $resource = $intake->fromUrl($company, $data['url'], [
                'source' => 'task_url',
                'assistant_task_id' => $task->id,
                'requirement_key' => $request->input('requirement_key'),
            ]);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Невалиден URL адрес.'], 422);
        }

        if ($resource === null) {
            return response()->json(['error' => 'Този URL вече е добавен.'], 422);
        }

        return response()->json(['resource_id' => $resource->id], 201);
    }

    /** Предложени готови ресурси, които вече покриват изискването (за таб „От знание"). */
    public function suggestExisting(Request $request, AssistantTask $task, KnowledgeService $knowledge): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $query = trim((string) $request->query('query', ''));
        if ($query === '') {
            $key = (string) $request->query('requirement_key', '');
            $req = $key !== '' ? $task->knowledgeRequirements()->where('key', $key)->first() : null;
            $query = (string) ($req?->query ?? '');
        }
        if ($query === '') {
            return response()->json(['resources' => []]);
        }

        $hits = $knowledge->search($company, $query, topK: 8, logGaps: false);

        // Ранг по РЕДА на хитовете (RRF вече ги подрежда по релевантност) — суровият
        // score е ненадежден (keyword хитове носят 0, factхитове нямат resource_id).
        $order = [];
        foreach ($hits as $hit) {
            $rid = $hit['resource_id'] ?? null;
            if ($rid === null || array_key_exists($rid, $order)) {
                continue;
            }
            $order[$rid] = count($order);
        }
        if ($order === []) {
            return response()->json(['resources' => []]);
        }

        $resources = $company->knowledgeResources()
            ->whereIn('id', array_keys($order))
            ->get()
            ->sortBy(fn ($r) => $order[$r->id] ?? PHP_INT_MAX)
            ->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'type' => $r->type,
            ])
            ->values()
            ->all();

        return response()->json(['resources' => $resources]);
    }

    /** Линква съществуващ ресурс към задачата (без копие) + пре-оценка. */
    public function linkExisting(Request $request, AssistantTask $task, KnowledgeIntakeService $intake, KnowledgeRequirementService $kr): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $data = $request->validate([
            'resource_id' => 'required|integer',
            'requirement_key' => 'nullable|string|max:200',
        ]);

        $resource = $company->knowledgeResources()->whereKey($data['resource_id'])->first();
        abort_unless((bool) $resource, 404);

        $intake->linkExisting($company, $resource, [
            'assistant_task_id' => $task->id,
            'requirement_key' => $request->input('requirement_key'),
        ]);

        $kr->evaluate($task, force: true);
        $task->refresh();

        return response()->json($this->statusPayload($task, $company));
    }

    /** Списък активни интеграции + описание на picker-а (какви селекти да покаже UI). */
    public function connectorFiles(AssistantTask $task): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $catalog = collect(config('mcp.catalog', []));

        $connectors = $company->connectors()->active()->get()
            ->map(function ($c) use ($catalog) {
                $meta = (array) ($catalog->firstWhere('type', $c->connector_type) ?? []);

                return [
                    'id' => $c->id,
                    'type' => $c->connector_type,
                    'label' => $meta['label'] ?? $c->connector_type,
                    'icon' => $meta['icon'] ?? '🧩',
                    'account' => $c->display_name ?: '',
                    'status' => $c->status,
                    'browsable' => $this->connectorBrowsable($c),
                    'picker' => $this->connectorPicker($c->connector_type),
                ];
            })
            ->filter(fn ($c) => $c['picker'] !== [])
            ->values()
            ->all();

        return response()->json(['connectors' => $connectors]);
    }

    /** Може ли конекторът да ЛИСТВА файлове (browse dropdown) при текущите OAuth scopes. */
    private function connectorBrowsable(CompanyConnector $c): bool
    {
        $scopes = array_map('strtolower', (array) ($c->scopes ?? []));
        $has = fn (string $s) => in_array(strtolower($s), $scopes, true);
        $driveRead = $has('https://www.googleapis.com/auth/drive')
            || $has('https://www.googleapis.com/auth/drive.readonly')
            || $has('https://www.googleapis.com/auth/drive.metadata.readonly');

        return match ($c->connector_type) {
            'google_drive', 'google_calendar' => true,
            'google_docs', 'google_sheets' => $driveRead,
            default => false, // gmail → ръчно (message_id)
        };
    }

    /**
     * Описание на picker-а за даден конектор — верига от select/text стъпки, които UI-ът
     * рендира, за да построи file_ref. Празен масив → конекторът не е източник на знание.
     *
     * @return array<int, array<string, mixed>>
     */
    private function connectorPicker(string $type): array
    {
        return match ($type) {
            'google_drive' => [
                ['param' => 'folder_id', 'label' => 'Папка (по избор)', 'source' => 'drive_folders', 'optional' => true],
                ['param' => 'file_id', 'label' => 'Файл', 'source' => 'drive_files', 'depends_on' => 'folder_id'],
            ],
            'google_sheets' => [
                ['param' => 'spreadsheet_id', 'label' => 'Google Sheet', 'source' => 'sheets_spreadsheets', 'manual_fallback' => true],
                ['param' => 'sheet', 'label' => 'Лист (по избор — иначе всички)', 'source' => 'sheets_tabs', 'depends_on' => 'spreadsheet_id', 'optional' => true],
            ],
            'google_docs' => [
                ['param' => 'document_id', 'label' => 'Документ', 'source' => 'docs_documents'],
            ],
            'google_calendar' => [
                ['param' => 'calendar_id', 'label' => 'Календар', 'source' => 'calendar_calendars'],
            ],
            'gmail' => [
                ['param' => 'message_id', 'label' => 'ID на имейл', 'input' => 'text'],
            ],
            default => [],
        };
    }

    /** Live опции за picker-select (Drive папки/файлове, Sheets листове, Docs, календари). */
    public function connectorOptions(Request $request, AssistantTask $task, McpClientService $mcp): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $connector = $company->connectors()->whereKey((int) $request->query('connector_id'))->first();
        abort_unless((bool) $connector, 404);

        $source = (string) $request->query('source', '');
        $context = (array) $request->query('context', []);
        $options = $source !== '' ? $mcp->listOptions($connector, $source, $context) : [];

        return response()->json(['options' => $options]);
    }

    /** Импорт на избран файл от интеграция (пълно сваляне през queue job). */
    public function ingestConnectorFile(Request $request, AssistantTask $task, KnowledgeIntakeService $intake, KnowledgeRequirementService $kr): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $data = $request->validate([
            'connector_id' => 'required|integer',
            'file_ref' => 'required|array',
            'requirement_key' => 'nullable|string|max:200',
        ]);

        $connector = $company->connectors()->whereKey($data['connector_id'])->first();
        abort_unless((bool) $connector, 404);

        $requirementKey = $request->input('requirement_key');

        $resource = $intake->fromConnectorFile($company, $connector, $data['file_ref'], [
            'assistant_task_id' => $task->id,
            'requirement_key' => $requirementKey,
            // Файл от свързан акаунт е СОБСТВЕНА фирмена информация (не конкурентни данни).
            'audience' => 'own',
        ]);

        // Файл за публично изискване = потребителят го задоволява ръчно → отбележи одобрено (отпушва гейта).
        if (is_string($requirementKey) && $this->requirementIsPublic($task, $requirementKey)) {
            $this->acknowledgePublic($task, [$requirementKey]);
            $kr->evaluate($task);
        }

        return response()->json(['resource_id' => $resource?->id]);
    }

    /** Уеб-търсене за публично изискване → кандидати за преглед (без ingest още). */
    public function webResearchStart(Request $request, AssistantTask $task): JsonResponse
    {
        $this->authorizeTask($task);

        if (! $this->webSearchEnabled()) {
            return response()->json(['error' => 'search_unconfigured', 'message' => 'Търсенето в интернет не е конфигурирано.'], 422);
        }

        // Уеб-търсене е само за публични изисквания — не разрешавай billable търсене иначе.
        $requirementKey = (string) $request->input('requirement_key', '');
        if ($requirementKey !== '' && ! $this->requirementIsPublic($task, $requirementKey)) {
            return response()->json(['error' => 'not_public', 'message' => 'Уеб търсене е позволено само за публични изисквания.'], 422);
        }
        if (! $task->knowledgeRequirements()->where('sourceability', 'public')->exists()) {
            return response()->json(['error' => 'not_public', 'message' => 'Тази задача няма публични изисквания за търсене.'], 422);
        }

        $query = trim((string) $request->input('query', ''));
        if ($query === '') {
            $key = (string) $request->input('requirement_key', '');
            $req = $key !== '' ? $task->knowledgeRequirements()->where('key', $key)->first() : null;
            $query = (string) ($req?->query ?? '');
        }
        if ($query === '') {
            $query = $task->knowledgeRequirements()->where('sourceability', 'public')->pluck('query')->implode(' ');
        }
        if (trim($query) === '') {
            return response()->json(['error' => 'no_query', 'message' => 'Няма заявка за търсене.'], 422);
        }

        try {
            $candidates = $this->runWebSearch($query);
        } catch (\Throwable) {
            return response()->json(['error' => 'search_failed', 'message' => 'Търсенето се провали. Опитай пак.'], 502);
        }

        return response()->json(['candidates' => $candidates, 'query' => $query]);
    }

    /** Ingest на избраните уеб-източници (single-page) + acknowledge на публичното изискване. */
    public function webResearchIngest(Request $request, AssistantTask $task, KnowledgeIntakeService $intake, KnowledgeRequirementService $kr): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        $data = $request->validate([
            'urls' => 'required|array|min:1',
            'urls.*' => 'url:http,https',
            'requirement_key' => 'nullable|string|max:200',
            'query' => 'nullable|string|max:500',
        ]);

        $requirementKey = $request->input('requirement_key');
        if (is_string($requirementKey) && ! $this->requirementIsPublic($task, $requirementKey)) {
            return response()->json(['error' => 'not_public', 'message' => 'Уеб търсене е позволено само за публични изисквания.'], 422);
        }

        $created = $intake->fromWebResearch($company, $data['urls'], [
            'assistant_task_id' => $task->id,
            'requirement_key' => $requirementKey,
            'search_query' => $request->input('query'),
        ]);

        // Одобри САМО таргетираното публично изискване (не всички публични на задачата).
        if (is_string($requirementKey) && $requirementKey !== '') {
            $this->acknowledgePublic($task, [$requirementKey]);
        }
        $kr->evaluate($task);

        return response()->json(['created' => array_map(fn ($r) => $r->id, $created)]);
    }

    /**
     * Поллинг (без оценка и без придвижване): следи само прогреса на ingest на ТАЗИ задача,
     * за да превключи секцията от „обработва се" към „готова за проверка". Оценката става само
     * при явен „Провери" (knowledgeCheck), придвижването — само при „Продължи" (knowledgeProceed).
     */
    public function knowledgeStatus(AssistantTask $task): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        if ($this->taskResources($task, $company)->whereIn('status', ['pending', 'processing'])->exists()) {
            return response()->json(['ingesting' => true] + $this->statusPayload($task, $company));
        }

        $failed = $this->taskResources($task, $company)->where('status', 'failed')->latest('id')->first();
        if ($failed) {
            return response()->json(['ingest_failed' => true, 'message' => 'Обработката на знанието се провали. Опитай отново.'] + $this->statusPayload($task, $company));
        }

        return response()->json($this->statusPayload($task, $company));
    }

    /** „Провери" за секция — форсирана пре-оценка на изискванията (без придвижване напред). */
    public function knowledgeCheck(AssistantTask $task, KnowledgeRequirementService $kr): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        // Ако още тече ingest — chunk-овете не са готови; клиентът ще изчака и ще провери пак.
        if ($this->taskResources($task, $company)->whereIn('status', ['pending', 'processing'])->exists()) {
            return response()->json(['ingesting' => true] + $this->statusPayload($task, $company));
        }

        $kr->evaluate($task, force: true);
        $task->refresh();

        return response()->json($this->statusPayload($task, $company));
    }

    /**
     * „Продължи" — придвижва задачата само когато знанието е достатъчно: готова задача (с flow)
     * с durable намерение → пускане; preflight-паркирана (без flow) → генерация. Иначе 422.
     */
    public function knowledgeProceed(AssistantTask $task, KnowledgeRequirementService $kr, TaskRunService $runner): JsonResponse
    {
        $this->authorizeTask($task);
        $company = $this->company($task);

        // Финална пре-оценка преди придвижване (сигурност срещу stale статус).
        $kr->evaluate($task, force: true);
        $task->refresh();

        if ($task->knowledge_status !== 'ready') {
            return response()->json($this->statusPayload($task, $company), 422);
        }

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
                return response()->json(['message' => 'Недостатъчно кредити за пускане.'] + $this->statusPayload($task, $company), 402);
            } catch (KnowledgeRequiredException) {
                $task->refresh();

                return response()->json($this->statusPayload($task, $company), 422);
            }
        }

        if (in_array($task->status, ['proposed', 'failed'], true) && ! $task->flow_id) {
            $result = $runner->generate($task, runAfterGenerate: $task->run_after_generate, firstReviewDone: true);

            return response()->json($result);
        }

        // Готова, но без run-intent (напр. вече има flow) — върни статуса; UI-ът ще презареди.
        return response()->json($this->statusPayload($task, $company));
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Ресурси, добавени за тази задача (по provenance) или линкнати към нея. */
    private function taskResources(AssistantTask $task, Company $company)
    {
        return $company->knowledgeResources()
            ->where('meta->provenance->assistant_task_id', $task->id);
    }

    /** @return array<string, mixed> Пълен статус-payload за попъпа. */
    private function statusPayload(AssistantTask $task, Company $company): array
    {
        return [
            'knowledge_status' => $task->knowledge_status,
            'requirements' => $this->knowledgeRequirements($task),
            'sources' => $this->taskSources($task, $company),
            'blocking_count' => $this->blockingCount($task),
        ];
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
            'query' => $r->query,
            'best_score' => $r->best_score,
            'evidence_sources' => $r->evidence_sources,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> Добавени/линкнати източници за задачата. */
    private function taskSources(AssistantTask $task, Company $company): array
    {
        return $company->knowledgeResources()
            ->where(function ($q) use ($task) {
                $q->where('meta->provenance->assistant_task_id', $task->id)
                    ->orWhereJsonContains('meta->task_links', $task->id);
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'type' => $r->type,
                'status' => $r->status,
                'source' => $r->meta['provenance']['source'] ?? 'kb_manual',
                'requirement_key' => $r->meta['provenance']['requirement_key'] ?? null,
            ])
            ->all();
    }

    /** Груб брой блокиращи изисквания (за UI бадж) — покрито/ack-нато публично не блокира. */
    private function blockingCount(AssistantTask $task): int
    {
        return $task->knowledgeRequirements()->get()->filter(function ($r) {
            if ($r->status === 'covered') {
                return false;
            }
            if ($r->sourceability === 'public' && $r->acknowledged) {
                return false;
            }

            return true;
        })->count();
    }

    private function requirementIsPublic(AssistantTask $task, ?string $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        return $task->knowledgeRequirements()
            ->where('key', $key)
            ->where('sourceability', 'public')
            ->exists();
    }

    /** @param array<int, mixed> $keys */
    private function acknowledgePublic(AssistantTask $task, array $keys): void
    {
        $keys = array_values(array_filter($keys, 'is_string'));
        if ($keys === []) {
            return;
        }

        $task->knowledgeRequirements()
            ->where('sourceability', 'public')
            ->whereIn('key', $keys)
            ->update(['acknowledged' => true]);
    }

    private function webSearchEnabled(): bool
    {
        $provider = (string) config('services.web_search.provider', 'brave');

        return ! empty(config("services.{$provider}.api_key"));
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function runWebSearch(string $query): array
    {
        $provider = (string) config('services.web_search.provider', 'brave');

        $results = $provider === 'perplexity'
            ? app(PerplexitySearchService::class)->search($query)
            : app(BraveSearchService::class)->search($query);

        return collect($results)
            ->map(fn ($r) => [
                'title' => (string) ($r['title'] ?? ($r['url'] ?? '')),
                'url' => (string) ($r['url'] ?? ''),
                'snippet' => (string) ($r['description'] ?? ($r['snippet'] ?? '')),
            ])
            ->filter(fn ($r) => $r['url'] !== '')
            ->values()
            ->take(10)
            ->all();
    }

    private function company(AssistantTask $task): Company
    {
        $company = $task->orgMember?->company;
        abort_unless((bool) $company, 404);

        return $company;
    }

    private function authorizeTask(AssistantTask $task): void
    {
        abort_unless($task->orgMember?->company_id === (int) session('client_company_id'), 403);
    }
}
