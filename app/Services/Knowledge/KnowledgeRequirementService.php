<?php

namespace App\Services\Knowledge;

use App\Models\AssistantTask;
use App\Models\AssistantTaskKnowledgeRequirement;
use App\Models\Company;
use App\Models\Flow;
use App\Services\GeneratorService;
use App\Services\KnowledgeService;
use App\Services\Org\KnowledgeRequiredException;
use App\Support\GraphTopology;
use App\Support\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Гейт по знание (§2-етапни задачи) — гарантира, че задача не стига до FlowRun, ако
 * изисква фирмено знание, което липсва и не може да се добие (частно) или не е разрешено
 * за уеб-търсене (публично). Планерът ПРЕДЛАГА (sourceability, how_to_provide), КОДЪТ
 * ГАРАНТИРА (status/best_score/evidence_sources). Реалната защита е gate(); preflight()
 * е оптимизация (паркира очевидно недоставими задачи преди скъпата генерация).
 *
 * Двете нива:
 *  1. preflight()  — преди генерация: keyword guard + евтин LLM по title/description.
 *  2. analyze()    — след генерация: пълно извличане от входните нодове + tools на flow-а.
 *  + evaluate()    — детерминистична достатъчност: retrieval → coverage judge (covered|partial|missing).
 *  + gate()        — хвърля KnowledgeRequiredException при непокрито блокиращо изискване.
 */
class KnowledgeRequirementService
{
    /** Cheap cloud фаза (gemini по подразбиране) — НЕ пада към локалния Ollama JSON. */
    private const PHASE = 'eval_judge';

    /** Частни сигнали за детерминистичния preflight guard (само при ПРАЗНА база). */
    private const PRIVATE_SIGNALS = [
        'треньор', 'клиент', 'служител', 'персонал', 'процедур', 'график',
        'екип', 'култур', 'вътреш', 'обучен', 'член', 'политик',
    ];

    /** Инструменти, които реално добиват ПУБЛИЧНА информация по време на run. */
    private const WEB_TOOLS = ['web_search', 'pro_search', 'scrape_page', 'crawl_site', 'discover_urls', 'google_reviews'];

    public function __construct(
        private GeneratorService $generator,
        private KnowledgeService $knowledge,
    ) {}

    /**
     * Ниво 1: евтин анализ ПРЕДИ генерация. Връща true, ако задачата е паркирана като
     * needs_knowledge (очевидна частна зависимост при празна база). Само оптимизация —
     * провал на LLM → false (gate-ът остава защитата).
     */
    public function preflight(AssistantTask $task): bool
    {
        $company = $task->orgMember?->company;
        if (! $company || ! $this->active($company)) {
            return false;
        }

        // Има ли база — post-gen audit-ът ще прецени по-точно (с реалния граф).
        if (! $this->knowledge->isEmpty($company)) {
            return false;
        }

        // (1) Детерминистичен keyword guard (без LLM).
        $text = mb_strtolower($task->title.' '.$task->description);
        foreach (self::PRIVATE_SIGNALS as $signal) {
            if (str_contains($text, $signal)) {
                $this->parkPreliminary($task);

                return true;
            }
        }

        // (2) LLM preflight само при неяснота.
        try {
            $raw = $this->generator->chatJson(
                'Ти преценяваш дали една бизнес задача изисква ВЪТРЕШНА ЧАСТНА фирмена информация '
                .'(имена/роли на служители, вътрешни процедури, графици, клиентски списъци, вътрешна '
                .'култура) — такава, която НЕ може да се намери в интернет. Базата знания на фирмата е '
                .'ПРАЗНА. Ако задачата зависи от такива частни данни → needs_private_knowledge=true и '
                .'опиши кратко какво трябва да въведе собственикът. Връщай само валиден JSON, на български.',
                "Заглавие: {$task->title}\nОписание: {$task->description}",
                self::PHASE,
                JsonSchema::strict([
                    'type' => 'object',
                    'properties' => [
                        'needs_private_knowledge' => ['type' => 'boolean'],
                        'requirements' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => ['type' => 'string'],
                                    'query' => ['type' => 'string'],
                                    'how_to_provide' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ]),
                ['temperature' => 0.1, 'num_predict' => 400],
            );

            if (! empty($raw['needs_private_knowledge'])) {
                $this->parkPreliminary($task, (array) ($raw['requirements'] ?? []));

                return true;
            }
        } catch (\Throwable $e) {
            Log::info('[KnowledgeGate] preflight LLM failed: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Ниво 2: пълно извличане на изискванията от title/description + входните нодове и
     * tools на генерирания flow. Strict схема. Fail-OPEN при ПЪЛНА база (има грунд →
     * не блокирай), fail към едно частно изискване при ПРАЗНА база (опасната комбинация).
     *
     * @return array<int, array<string, mixed>>
     */
    public function analyze(AssistantTask $task): array
    {
        $company = $task->orgMember?->company;
        if (! $company || ! $this->active($company)) {
            return [];
        }

        [$inputNodes, $tools] = $this->flowInputs($task->flow);

        try {
            $raw = $this->generator->chatJson(
                'Ти проектираш кои ДАННИ са нужни, за да се изпълни тази задача БЕЗ халюцинации. '
                ."Контекст за базата знания: {$this->kbSummaryLine($company)}\n"
                .'Извлечи списък от КОНКРЕТНИ изисквания за знание (фокус върху ВХОДНИТЕ данни на процеса). '
                .'За всяко класифицирай sourceability: "private" = вътрешно-фирмено, НЕ може да се намери в '
                .'интернет (служители, вътрешни процедури, графици, клиенти); "public" = може да се намери чрез '
                .'уеб-търсене (цени на конкуренти, публични регулации, пазарни данни); "existing" = собствени '
                .'данни на фирмата, които ТРЯБВА вече да са въведени (нашите цени/услуги/контакти). За "private" '
                .'и "existing" дай how_to_provide — кратки, ясни инструкции на български какво да въведе собственикът '
                .'(за "public" остави празно). query = кратка заявка за търсене в базата. Връщай само валиден JSON.',
                $this->analyzeUser($task, $inputNodes, $tools),
                self::PHASE,
                $this->analyzeSchema(),
                ['temperature' => 0.1, 'num_predict' => 800],
            );
        } catch (\Throwable $e) {
            Log::info('[KnowledgeGate] analyze LLM failed: '.$e->getMessage());

            if ($this->knowledge->isEmpty($company)) {
                $this->upsertRequirements($task, [[
                    'label' => 'Вътрешна информация за фирмата',
                    'query' => $task->title.' '.$task->description,
                    'sourceability' => 'private',
                    'how_to_provide' => 'Опиши вътрешната информация, нужна за тази задача (напр. имена/роли на '
                        .'служители, вътрешни процедури, графици), като добавиш бележка в базата знания.',
                ]], prune: true);

                return $task->knowledgeRequirements()->get()->toArray();
            }

            // Пълна база + провал → не блокирай (има грунд за агентите).
            $task->knowledgeRequirements()->delete();
            $task->update(['knowledge_status' => 'ready', 'knowledge_evaluated_at' => now()]);

            return [];
        }

        $this->upsertRequirements($task, (array) ($raw['requirements'] ?? []), prune: true);

        return $task->knowledgeRequirements()->get()->toArray();
    }

    /**
     * Детерминистична достатъчност: retrieval per изискване + батчван coverage judge
     * (covered|partial|missing). TTL гард пести judge calls. Fail-CLOSED: при провал на
     * judge изискването запазва текущия си статус (нови = missing → блокирани).
     */
    public function evaluate(AssistantTask $task, bool $force = false): void
    {
        $company = $task->orgMember?->company;
        if (! $company || ! $this->active($company)) {
            $task->update(['knowledge_status' => 'ready']);

            return;
        }

        $reqs = $task->knowledgeRequirements()->get();
        if ($reqs->isEmpty()) {
            $task->update(['knowledge_status' => 'ready', 'knowledge_evaluated_at' => now()]);

            return;
        }

        // TTL гард: реална оценка само при нужда — иначе само пресметни статуса (без LLM).
        if (! $force && ! $this->needsReeval($task)) {
            $this->recomputeStatus($task);

            return;
        }

        $evidence = [];
        foreach ($reqs as $req) {
            $evidence[$req->key] = $this->knowledge->search($company, $req->query, topK: 5, logGaps: false);
        }

        $verdicts = $this->judge($reqs, $evidence);

        foreach ($reqs as $req) {
            $hits = $evidence[$req->key] ?? [];
            // Fail-closed: липсва verdict (judge падна или не върна този key) → запази текущия статус.
            $status = $verdicts[$req->key] ?? $req->status;
            $req->update([
                'status' => $status,
                'best_score' => $this->bestScore($hits),
                'evidence_sources' => $this->evidenceSources($hits),
                'resolved_at' => $status === 'covered' ? now() : null,
            ]);
        }

        $task->update(['knowledge_evaluated_at' => now()]);
        $this->recomputeStatus($task);
    }

    /**
     * Финалният hard gate (run path). Lazy extraction при backfill (unknown + няма редове),
     * после оценка, после блокаж при непокрито изискване.
     */
    public function gate(AssistantTask $task): void
    {
        $company = $task->orgMember?->company;
        if (! $company || ! $this->active($company)) {
            return; // fail open
        }

        if ($task->knowledge_status === 'unknown' && $task->knowledgeRequirements()->doesntExist()) {
            $this->analyze($task);
        }

        $this->evaluate($task);

        $blocking = $this->blockingRequirements($task);
        if ($blocking->isNotEmpty()) {
            throw new KnowledgeRequiredException($task->id, $blocking->map(fn (AssistantTaskKnowledgeRequirement $r) => [
                'key' => $r->key,
                'label' => $r->label,
                'sourceability' => $r->sourceability,
                'status' => $r->status,
                'how_to_provide' => $r->how_to_provide,
                'acknowledged' => $r->acknowledged,
            ])->values()->all());
        }
    }

    /** Има ли flow-ът реален уеб-инструмент (за да добие публично знание по време на run). */
    public function flowHasWebTools(?Flow $flow): bool
    {
        $version = $flow?->activeVersion;
        if (! $version) {
            return false;
        }

        foreach ($version->nodes()->where('is_active', true)->get() as $node) {
            $tools = array_map('strval', (array) ($node->config['tools'] ?? []));
            if (array_intersect($tools, self::WEB_TOOLS) !== []) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Блокиращите изисквания при текущото състояние (виж гейт логиката в плана). */
    private function blockingRequirements(AssistantTask $task): Collection
    {
        $task->loadMissing('flow');
        $hasFlow = $task->flow_id !== null && $task->flow !== null;
        $hasWeb = $hasFlow && $this->flowHasWebTools($task->flow);

        return $task->knowledgeRequirements()->get()->filter(function (AssistantTaskKnowledgeRequirement $req) use ($hasFlow, $hasWeb) {
            if ($req->status === 'covered') {
                return false;
            }

            return match ($req->sourceability) {
                // публично: acknowledged отпушва — преди flow (генерация) или с реални web tools (run)
                'public' => ! ($req->acknowledged && ($hasWeb || ! $hasFlow)),
                // private / existing → винаги блокира до покритие
                default => true,
            };
        })->values();
    }

    private function recomputeStatus(AssistantTask $task): void
    {
        $task->update([
            'knowledge_status' => $this->blockingRequirements($task)->isNotEmpty() ? 'needs_knowledge' : 'ready',
        ]);
    }

    /** Гейтът е активен само ако е включен глобално И знанието е включено за фирмата. */
    private function active(Company $company): bool
    {
        return (bool) config('organization.knowledge_gate.enabled', true)
            && KnowledgeService::enabled($company);
    }

    /** Паркира задача като needs_knowledge с предварителни (частни) изисквания. */
    private function parkPreliminary(AssistantTask $task, array $llmReqs = []): void
    {
        $reqs = $llmReqs !== [] ? $llmReqs : [[
            'label' => 'Вътрешна информация за фирмата',
            'query' => $task->title.' '.$task->description,
            'sourceability' => 'private',
            'how_to_provide' => 'Опиши вътрешната информация, нужна за тази задача (напр. имена/роли на '
                .'служители, вътрешни процедури, графици), като добавиш бележка в базата знания.',
        ]];

        $this->upsertRequirements($task, $reqs, defaultSourceability: 'private');
        $task->update(['knowledge_status' => 'needs_knowledge']);
    }

    /**
     * Upsert по (assistant_task_id, key). key се derive-ва от КОДА. Запазва status/acknowledged
     * на съществуващите редове (оценката ги поставя). prune → трие изчезналите изисквания.
     *
     * @param  array<int, array<string, mixed>>  $reqs
     */
    private function upsertRequirements(AssistantTask $task, array $reqs, bool $prune = false, string $defaultSourceability = 'private'): void
    {
        $keys = [];
        foreach ($reqs as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $query = trim((string) ($r['query'] ?? '')) ?: $label;
            $source = in_array($r['sourceability'] ?? null, ['private', 'public', 'existing'], true)
                ? (string) $r['sourceability'] : $defaultSourceability;
            $how = trim((string) ($r['how_to_provide'] ?? ''));

            $slug = Str::slug($label);
            $key = ($slug !== '' ? $slug.'-' : 'req-').substr(sha1($query), 0, 12);
            $keys[] = $key;

            $req = AssistantTaskKnowledgeRequirement::firstOrNew([
                'assistant_task_id' => $task->id,
                'key' => $key,
            ]);
            $req->fill([
                'label' => $label,
                'query' => $query,
                'sourceability' => $source,
                'how_to_provide' => $how ?: null,
            ]);
            if (! $req->exists) {
                $req->status = 'missing';
                $req->acknowledged = false;
            }
            $req->save();
        }

        if ($prune) {
            $task->knowledgeRequirements()->whereNotIn('key', $keys ?: ['__none__'])->delete();
        }
    }

    /** Батчван coverage judge: за всяко изискване covered|partial|missing върху НАМЕРЕНИТЕ данни. */
    private function judge(Collection $reqs, array $evidence): array
    {
        $blocks = [];
        foreach ($reqs as $req) {
            $snips = collect($evidence[$req->key] ?? [])->take(5)
                ->map(fn ($h) => '   - '.mb_substr(trim((string) ($h['content'] ?? '')), 0, 300))
                ->implode("\n");
            $blocks[] = "key: {$req->key}\nИзискване: {$req->label}\nЗаявка: {$req->query}\n"
                .'Намерени данни:'."\n".($snips !== '' ? $snips : '   (няма)');
        }

        try {
            $raw = $this->generator->chatJson(
                'Ти си СТРОГ оценител на достатъчност. За всяко изискване реши дали НАМЕРЕНИТЕ данни го '
                .'покриват: "covered" = напълно достатъчни за изпълнение без догадки; "partial" = има нещо, '
                .'но недостатъчно; "missing" = нищо релевантно. Бъди консервативен — висока прилика ≠ покритие. '
                .'Връщай само валиден JSON.',
                implode("\n\n", $blocks),
                self::PHASE,
                JsonSchema::strict([
                    'type' => 'object',
                    'properties' => [
                        'verdicts' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'key' => ['type' => 'string'],
                                    'verdict' => ['type' => 'string', 'enum' => ['covered', 'partial', 'missing']],
                                ],
                            ],
                        ],
                    ],
                ]),
                ['temperature' => 0.0, 'num_predict' => 500],
            );
        } catch (\Throwable $e) {
            Log::info('[KnowledgeGate] judge LLM failed (fail-closed): '.$e->getMessage());

            return [];
        }

        $out = [];
        foreach ((array) ($raw['verdicts'] ?? []) as $v) {
            $key = (string) ($v['key'] ?? '');
            $verdict = (string) ($v['verdict'] ?? '');
            if ($key !== '' && in_array($verdict, ['covered', 'partial', 'missing'], true)) {
                $out[$key] = $verdict;
            }
        }

        return $out;
    }

    private function needsReeval(AssistantTask $task): bool
    {
        if ($task->knowledge_status === 'unknown' || $task->knowledge_evaluated_at === null) {
            return true;
        }

        return $task->orgMember?->company?->knowledgeResources()
            ->where('ingested_at', '>', $task->knowledge_evaluated_at)
            ->exists() ?? false;
    }

    /** @return array{0: array<int, string>, 1: array<int, string>} [input node descriptors, all tools] */
    private function flowInputs(?Flow $flow): array
    {
        $version = $flow?->activeVersion;
        if (! $version) {
            return [[], []];
        }

        $nodes = $version->nodes()->where('is_active', true)->where('type', '!=', 'qa_verifier')->get();
        if ($nodes->isEmpty()) {
            return [[], []];
        }

        $nodeKeys = $nodes->pluck('node_key')->map('strval')->all();
        $edges = $version->edges()->get(['from_node_key', 'to_node_key'])
            ->map(fn ($e) => ['from' => (string) $e->from_node_key, 'to' => (string) $e->to_node_key])->all();

        $analysis = GraphTopology::analyze($nodeKeys, $edges);
        $firstWave = $analysis['waves'][0] ?? $nodeKeys;
        $byKey = $nodes->keyBy('node_key');

        $allTools = [];
        foreach ($nodes as $node) {
            $allTools = array_merge($allTools, array_map('strval', (array) ($node->config['tools'] ?? [])));
        }

        $descriptors = [];
        foreach ($firstWave as $key) {
            $node = $byKey->get((string) $key);
            if (! $node) {
                continue;
            }
            $prompt = mb_substr((string) ($node->prompt_template ?: $node->system_prompt ?: ''), 0, 200);
            $descriptors[] = trim(($node->name ?: $node->role ?: $node->type).($prompt !== '' ? ' — '.$prompt : ''));
        }

        return [$descriptors, array_values(array_unique($allTools))];
    }

    private function analyzeUser(AssistantTask $task, array $inputNodes, array $tools): string
    {
        return "Задача: {$task->title}\nОписание: {$task->description}\n"
            .'Входни стъпки на процеса: '.(implode(' | ', $inputNodes) ?: '—')."\n"
            .'Налични инструменти: '.(implode(', ', $tools) ?: '—');
    }

    private function kbSummaryLine(Company $company): string
    {
        if ($this->knowledge->isEmpty($company)) {
            return 'базата знания е ПРАЗНА (няма факти/документи).';
        }
        $s = $this->knowledge->summary($company);
        $titles = implode(', ', array_slice($s['titles'] ?? [], 0, 8));

        return "съдържа {$s['documents']} документа и {$s['facts']} факта".($titles !== '' ? " (напр.: {$titles})" : '').'.';
    }

    private function analyzeSchema(): array
    {
        return JsonSchema::strict([
            'type' => 'object',
            'properties' => [
                'requirements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'query' => ['type' => 'string'],
                            'sourceability' => ['type' => 'string', 'enum' => ['private', 'public', 'existing']],
                            'how_to_provide' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @param array<int, array<string, mixed>> $hits */
    private function bestScore(array $hits): ?float
    {
        $scores = array_filter(array_map(fn ($h) => $h['score'] ?? null, $hits), fn ($v) => $v !== null);

        return $scores !== [] ? round((float) max($scores), 3) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array<string, mixed>>
     */
    private function evidenceSources(array $hits): array
    {
        return collect($hits)->take(8)->map(fn ($h) => array_filter([
            'kind' => $h['kind'] ?? null,
            'resource_id' => $h['resource_id'] ?? null,
            'page_id' => $h['page_id'] ?? null,
            'fact_id' => $h['fact_id'] ?? null,
            'score' => $h['score'] ?? null,
        ], fn ($v) => $v !== null))->values()->all();
    }
}
