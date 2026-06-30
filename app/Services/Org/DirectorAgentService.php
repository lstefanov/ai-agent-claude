<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\Director;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Services\EmbeddingService;
use App\Services\GeneratorService;
use App\Services\KnowledgeService;
use App\Support\PromptData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Директорът като реален разсъждаващ supervisor-агент (§8). Tick = МИСЛЕНЕ/ОТЧЕТ (Q2):
 * НЕ пуска задачи (реалното пускане е scheduled cron + event + ръчно — re-run бъгът премахнат),
 * а чете състоянието на отдела и ПРЕДЛАГА евтини идеи (OrgProposal) — задачи, мандати, повишения,
 * наемане. Флоуът се генерира чак при одобрение (DecisionController::materializeProposal). Бюджетът
 * и резервацията на тика са в DirectorTickJob. Персоната ОБАГРЯ тона, не компетентността.
 */
class DirectorAgentService
{
    public function __construct(
        private PersonaService $personas,
        private GeneratorService $generator,
        private MemberMemoryService $memory,
        private EmbeddingService $embeddings,
        private KnowledgeService $knowledge,
    ) {}

    /**
     * Цикълът от §8: чете състояние → разсъждава през персоната → предлага → отчита към
     * Управителя (org_event review). Без пускане на задачи.
     *
     * @return array{ran: array<int>, proposals: array<int>, report: string}
     */
    public function tick(Director $director, string $trigger = 'scheduled'): array
    {
        $version = $director->orgVersion;
        $company = $version->company;
        $directorMember = $director->orgMember;
        $directorMember?->loadMissing('persona');

        $proposals = $this->maybePropose($director, $company, $directorMember, $trigger);

        // Нищо предложено → без report LLM call и без събитие (no-op tick не харчи).
        if ($proposals === []) {
            return ['ran' => [], 'proposals' => [], 'report' => ''];
        }

        $report = $this->report($directorMember, $this->assistantMembers($director), $proposals, $trigger);

        // Dedup (§11.3): пиши събитие в хрониката само ако текстът се различава от последния отчет.
        $lastReview = $company->orgEvents()->where('type', 'review')
            ->where('org_member_id', $directorMember?->id)->latest('id')->value('summary');
        if (trim((string) $lastReview) !== trim($report)) {
            $company->orgEvents()->create([
                'type' => 'review',
                'org_version_id' => $version->id,
                'org_member_id' => $directorMember?->id,
                'summary' => $report,
                'actor' => 'director',
            ]);
        }

        return ['ran' => [], 'proposals' => $proposals, 'report' => $report];
    }

    /** Структурно/задачно предложение → durable org_proposal(pending) за Кутията (§A7). */
    public function proposeDecision(Company $company, string $type, array $payload, string $rationale): OrgProposal
    {
        return OrgProposal::create([
            'company_id' => $company->id,
            'type' => $type,
            'payload' => $payload + ['rationale' => $rationale],
            'base_org_version_id' => $company->active_org_version_id,
        ]);
    }

    /**
     * Директорът мисли: състояние на отдела + приоритети + болки + памет → евтини идеи
     * (OrgProposal). Throttle (cooldown) + pending-pressure guard + dedup. Стампва
     * last_proposed_at след реален опит за мислене (без повторен опит всеки час).
     *
     * @return array<int>
     */
    private function maybePropose(Director $director, Company $company, ?OrgMember $directorMember, string $trigger = 'scheduled'): array
    {
        if (! $directorMember) {
            return [];
        }

        // Ръчният (човек) и ignition (веднага след одобрение на екип) минават cooldown-а.
        $bypassThrottle = in_array($trigger, ['manual', 'ignition'], true);

        $cooldownH = (int) config('organization.autonomous.director.propose_cooldown_hours', 24);
        if (! $bypassThrottle && $director->last_proposed_at && $director->last_proposed_at->gt(now()->subHours($cooldownH))) {
            return [];
        }

        $assistants = $director->assistants()->with('orgMember.persona')->get();
        $assistantMembers = $assistants->pluck('orgMember')->filter()->values();
        if ($assistantMembers->isEmpty()) {
            return [];
        }

        // Pending-pressure: не отрупвай — спри ако вече има много отворени решения.
        $maxOpen = (int) config('organization.autonomous.director.max_open_proposals', 3);
        if ($this->openCount($company, $assistantMembers) >= $maxOpen) {
            return [];
        }

        $state = [];
        foreach ($assistants as $a) {
            $m = $a->orgMember;
            if (! $m) {
                continue;
            }
            $stats = $this->memory->runStats($m);
            // Tasks са истинският сигнал за „зает/не-зает", не runs (0 runs + 0 задачи =
            // idle → трябват му задачи, не е „слаб"). Показваме наличните (без отхвърлените).
            $activeTasks = $m->tasks()->whereNotIn('status', ['rejected'])->get(['title', 'status']);
            $state[] = [
                'member_id' => $m->id,
                'name' => $m->persona?->name ?? $m->display_name,
                'role' => $a->title,
                'mandate' => (string) ($a->mandate ?? ''),
                'tasks_count' => $activeTasks->count(),
                'task_titles' => $activeTasks->pluck('title')->take(5)->all(),
                'runs' => $stats['runs'],
                'avg_qa' => $stats['avg_qa'],
                'lessons' => $this->memory->lessons($m, 3)->map(fn ($l) => (string) ($l->title ?: $l->summary))->all(),
            ];
        }

        $pains = (array) ($company->businessProfile?->pain_points ?? []);
        $priorities = (array) ($director->priorities ?? []);

        $design = $this->proposeViaLlm($directorMember, $director, $state, $pains, $priorities);
        $limit = (int) config('organization.autonomous.director.propose_limit', 2);
        $ids = $this->createProposals($company, $directorMember, $assistantMembers, $state, $design, $limit);

        $director->update(['last_proposed_at' => now()]);

        // Гаранция при запалване (§ignition): нов екип никога не остава без предложения. Ако LLM-ът
        // върна 0 (срамежлив/грешка), синтезирай 1 детерминистична ПУБЛИЧНА задача за отдела —
        // оцелява гейта по знание (без частни сигнали) и отива в Кутията за решения.
        if ($ids === [] && $trigger === 'ignition') {
            $fallback = $this->ignitionFallbackProposal($company, $director, $directorMember, $assistantMembers, $state);
            if ($fallback !== null) {
                $ids[] = $fallback;
            }
        }

        return $ids;
    }

    /**
     * Детерминистично начално предложение за отдел при запалване, когато LLM-ът не върна нищо.
     * ПУБЛИЧНА (уеб-търсима) тема по приоритет/мандат — без частни сигнали, за да не я паркира
     * гейтът по знание. Собственик = най-подходящият асистент по мандат. Връща id или null.
     */
    private function ignitionFallbackProposal(Company $company, Director $director, OrgMember $directorMember, Collection $assistantMembers, array $state): ?int
    {
        if ($assistantMembers->isEmpty()) {
            return null;
        }

        $industry = trim((string) ($company->industry ?? '')) ?: $company->name;
        $priorities = (array) ($director->priorities ?? []);
        $focus = trim((string) ($priorities[0] ?? $director->title)) ?: $director->title;

        $title = 'Проучи онлайн конкурентите и пазара: '.$focus;
        $description = 'Направи публично онлайн проучване на конкурентите, цените и тенденциите, '
            .'свързани с „'.$focus.'" за '.$industry.'. Обобщи изводи и възможности за отдела. '
            .'Използвай само публично достъпна информация от интернет.';

        // Dedup срещу вече съществуващите (за всеки случай).
        if ($this->isDuplicate($title, $this->existingTitles($company, $assistantMembers))) {
            return null;
        }

        // Собственик: семантично най-близкият асистент по мандат (load-balance тайбрейк).
        $stateByMember = collect($state)->keyBy('member_id')->all();
        $mandateEmbeddings = [];
        foreach ($assistantMembers as $m) {
            $info = $stateByMember[$m->id] ?? [];
            $mandateEmbeddings[$m->id] = $this->embeddings->embed(
                ((string) ($info['role'] ?? '')).' '.((string) ($info['mandate'] ?? '')),
                ['purpose' => 'director_tick_mandate'],
            );
        }
        $owner = $this->selectOwnerForTask($assistantMembers, $stateByMember, $mandateEmbeddings, $title, $description, null);

        $payload = [
            'title' => $title,
            'description' => $description,
            'org_member_id' => $owner?->id,
            'target_member_id' => $owner?->id,
            'act_mode' => 'draft',
            'tier' => null,
            'proposed_by' => $directorMember->persona?->name ?? 'Директор',
            'proposed_by_member_id' => $directorMember->id,
        ];

        return $this->proposeDecision($company, 'task', $payload, 'Начална тема за отдела (генерирана при стартиране на екипа).')->id;
    }

    /** Отворени решения за отдела = pending_approval задачи на асистентите + pending org предложения. */
    private function openCount(Company $company, Collection $assistantMembers): int
    {
        $tasks = AssistantTask::whereIn('org_member_id', $assistantMembers->pluck('id'))
            ->where('status', 'pending_approval')->count();
        $proposals = OrgProposal::where('company_id', $company->id)->pending()->count();

        return $tasks + $proposals;
    }

    /** Управителят-стил предложение през персоната (LLM); празно при слаб модел/грешка. */
    private function proposeViaLlm(OrgMember $directorMember, Director $director, array $state, array $pains, array $priorities): array
    {
        $persona = $this->personas->compileSystemPrompt($directorMember);
        $policy = $this->personas->runtimePolicy($directorMember);
        $limit = (int) config('organization.autonomous.director.propose_limit', 2);
        $system = trim($persona."\n\n".'Ти си Директор на отдел „'.$director->title.'". Прегледай състоянието '
            .'на асистентите си (мандат, брой задачи, runs, qa), приоритетите и болките на бизнеса и предложи 0–'.$limit.' '
            .'КОНКРЕТНИ идеи. Решавай по тези правила: '
            .'(1) Асистент с 0 или малко задачи, чийто мандат покрива дадена болка/приоритет → предложи му '
            .'НОВА ЗАДАЧА (type=task) по мандата му. Това е най-честият случай — idle асистент значи '
            .'„дай му работа", НЕ „наеми друг". За задача избирай асистента от СВОЯ отдел, чийто мандат '
            .'най-добре пасва на темата на задачата (съвпадение на ключови думи между мандата и '
            .'заглавието/описанието), и посочи неговия org_member_id — системата валидира избора по мандат. '
            .'(2) Асистент с много runs и нисък qa → промяна на мандата (mandate) или ниво (tier_change). '
            .'(3) Асистент силен (много runs + висок qa) → повишение (tier_change нагоре). '
            .'(4) Наемане (hire) — САМО в два случая: (а) capability gap — има болка/приоритет, който НИТО '
            .'един от мандатите на текущите асистенти не покрива (напр. липсва „резервации"/„SEO"/„фактуриране") '
            .'→ предложи нов асистент с конкретен title и описание (= мандат) точно за тази празнина; или '
            .'(б) capacity gap — всички асистенти са заети с много задачи и не смогват. Никога не предлагай '
            .'hire за idle асистент без задачи, чийто мандат би покрил проблема — първо му дай задача. '
            .'Задължително посочи org_member_id на асистента, за когото е предложението (за task/mandate/tier; '
            .'за hire — null, но дай ясен title + описание за новия мандат). '
            .'Без вода, обосновано от данните и приоритетите.'
            .$this->kbAwareness($directorMember->company)
            .' Върни САМО валиден JSON по схемата, на български. '
            .PromptData::NO_TECH_TERMS);
        $user = 'Отдел: '.$director->title."\nПриоритети: ".implode('; ', $priorities)
            ."\nАсистенти: ".json_encode(PromptData::humanize($state), JSON_UNESCAPED_UNICODE)
            ."\nБолки на бизнеса: ".implode('; ', $pains);

        try {
            return $this->generator->chatJson($system, $user, 'director_tick', $this->proposalSchema(), [
                'temperature' => (float) ($policy['planner_temperature'] ?? 0.5), 'num_predict' => 1200,
            ]);
        } catch (\Throwable $e) {
            Log::info('[DirectorTick] propose LLM failed: '.$e->getMessage());

            return ['proposals' => []];
        }
    }

    /** Dedup + създаване на евтини OrgProposal (без флоу-генерация — Q1). @return array<int> */
    private function createProposals(Company $company, OrgMember $directorMember, Collection $assistantMembers, array $state, array $design, int $limit): array
    {
        $existing = $this->existingTitles($company, $assistantMembers);
        $proposedBy = $directorMember->persona?->name ?? 'Директор';
        $stateByMember = collect($state)->keyBy('member_id')->all();
        // Мандат-embedings — веднъж за целия tick (стабилни са в рамките на един преглед).
        $mandateEmbeddings = [];
        foreach ($assistantMembers as $m) {
            $info = $stateByMember[$m->id] ?? [];
            $mandateEmbeddings[$m->id] = $this->embeddings->embed(
                ((string) ($info['role'] ?? '')).' '.((string) ($info['mandate'] ?? '')),
                ['purpose' => 'director_tick_mandate'],
            );
        }
        $ids = [];

        foreach (array_slice((array) ($design['proposals'] ?? []), 0, 8) as $p) {
            if (count($ids) >= $limit) {
                break;
            }
            $title = trim((string) ($p['title'] ?? ''));
            if ($title === '' || $this->isDuplicate($title, $existing)) {
                continue;
            }
            $pType = $p['type'] ?? 'task';
            $type = in_array($pType, ['task', 'mandate', 'tier_change', 'hire'], true) ? $pType : 'task';

            $llmPick = isset($p['org_member_id']) ? (int) $p['org_member_id'] : null;
            $llmMember = $llmPick ? $assistantMembers->firstWhere('id', $llmPick) : null;

            // Собственик/цел според типа:
            //  - task: детерминистично по мандат — семантично съвпадение (embeddings) с темата +
            //    load-balance тайбрейк; LLM-подборът е последен тайбрейк при пълна равенство (кодът гарантира).
            //  - mandate/tier_change: целеви член — LLM-подборът ако е в отдела, иначе първия.
            //  - hire/fire: нов/пенсиониран член → без съществуващ собственик (null).
            $owner = match ($type) {
                'task' => $this->selectOwnerForTask($assistantMembers, $stateByMember, $mandateEmbeddings, $title, (string) ($p['description'] ?? $title), $llmPick),
                'mandate', 'tier_change' => $llmMember ?? $assistantMembers->first(),
                default => null,
            };

            $actMode = $p['act_mode'] ?? 'draft';
            $payload = [
                'title' => $title,
                'description' => (string) ($p['description'] ?? $title),
                'org_member_id' => $owner?->id,        // собственик (task) / цел (mandate/tier) / null (hire/fire)
                'target_member_id' => $owner?->id,
                'act_mode' => in_array($actMode, ['draft', 'act', 'mixed'], true) ? $actMode : 'draft',
                'tier' => is_string($p['tier'] ?? null) ? $p['tier'] : null,
                'proposed_by' => $proposedBy,
                'proposed_by_member_id' => $directorMember->id,
            ];

            $ids[] = $this->proposeDecision($company, $type, $payload, (string) ($p['rationale'] ?? ''))->id;
            $existing[] = $this->normalize($title);
        }

        return $ids;
    }

    /**
     * Детерминистичен избор на асистент-собственик за задача от отдела: семантично
     * съвпадение (cosine на embeddings — bge-m3) между темата на задачата и мандата
     * на асистента; при липса на embedding — лексикален fallback (Jaccard, -1..0, под
     * всяко реално cosine). Тайбрейк: по-малко активни задачи (load-balance), после
     * LLM-подборът. Кодът гарантира, че задачата отива към най-подходящия асистент в
     * същия отдел.
     */
    private function selectOwnerForTask(Collection $assistantMembers, array $stateByMember, array $mandateEmbeddings, string $title, string $description, ?int $llmPick): ?OrgMember
    {
        $proposalText = $title.' '.$description;
        $proposalEmb = $this->embeddings->embed($proposalText, ['purpose' => 'director_tick_task']);
        $proposalTokens = $this->tokenize($proposalText);

        $best = null;
        $bestScore = -2.0;
        $bestTasks = PHP_INT_MAX;
        foreach ($assistantMembers as $m) {
            $info = $stateByMember[$m->id] ?? [];
            $mandateEmb = $mandateEmbeddings[$m->id] ?? null;
            $mandateTokens = $this->tokenize(((string) ($info['role'] ?? '')).' '.((string) ($info['mandate'] ?? '')));

            if ($proposalEmb !== null && $mandateEmb !== null) {
                // Семантично: 0..1 — винаги над лексикалния fallback.
                $score = EmbeddingService::cosine($proposalEmb, $mandateEmb);
            } else {
                // Лексикален fallback: -1..0, за да не бие реално cosine.
                $score = -1.0 + $this->jaccardTokens($proposalTokens, $mandateTokens);
            }
            $tasks = (int) ($info['tasks_count'] ?? 0);

            $wins = $score > $bestScore
                || ($score === $bestScore && $tasks < $bestTasks)
                || ($score === $bestScore && $tasks === $bestTasks && $llmPick !== null && $m->id === $llmPick);
            if ($wins) {
                $best = $m;
                $bestScore = $score;
                $bestTasks = $tasks;
            }
        }

        return $best;
    }

    /** Токени за mandate-match: нормализирани, уникални, без BG стоп-думи и токени ≤2 букви. */
    private function tokenize(string $s): array
    {
        $n = $this->normalize($s);
        if ($n === '') {
            return [];
        }
        $stop = ['на', 'за', 'от', 'в', 'във', 'с', 'със', 'и', 'или', 'да', 'не', 'е', 'са', 'по', 'при', 'до', 'из', 'като', 'както', 'над', 'под', 'без', 'това', 'тази', 'този', 'който', 'която', 'какво', 'кой', 'как', 'защото', 'че', 'но', 'преди', 'след', 'обаче', 'също', 'се', 'си', 'му', 'я', 'го', 'те', 'ги', 'ни', 'ви', 'им', 'само', 'всички', 'у', 'о', 'асистент', 'директор', 'отдел'];

        return array_values(array_filter(
            array_unique(explode(' ', $n)),
            fn ($w) => $w !== '' && mb_strlen($w) > 2 && ! in_array($w, $stop, true),
        ));
    }

    private function jaccardTokens(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $inter / $union : 0.0;
    }

    /** Нормализирани заглавия на активните задачи в отдела + чакащите предложения (за dedup). */
    private function existingTitles(Company $company, Collection $assistantMembers): array
    {
        $taskTitles = AssistantTask::whereIn('org_member_id', $assistantMembers->pluck('id'))
            ->whereIn('status', ['proposed', 'generating', 'pending_approval', 'ready'])
            ->pluck('title');
        $proposalTitles = OrgProposal::where('company_id', $company->id)->pending()->get()
            ->map(fn (OrgProposal $p) => (string) ($p->payload['title'] ?? ''));

        return $taskTitles->concat($proposalTitles)
            ->map(fn ($t) => $this->normalize((string) $t))->filter()->values()->all();
    }

    private function isDuplicate(string $title, array $existingNormalized): bool
    {
        $n = $this->normalize($title);
        if ($n === '') {
            return false;
        }
        foreach ($existingNormalized as $e) {
            if ($e === $n || $this->jaccard($n, $e) > 0.6) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private function jaccard(string $a, string $b): float
    {
        $aw = array_filter(array_unique(explode(' ', $a)));
        $bw = array_filter(array_unique(explode(' ', $b)));
        if ($aw === [] || $bw === []) {
            return 0.0;
        }
        $inter = count(array_intersect($aw, $bw));
        $union = count(array_unique(array_merge($aw, $bw)));

        return $union > 0 ? $inter / $union : 0.0;
    }

    private function proposalSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'proposals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['task', 'mandate', 'tier_change', 'hire']],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'rationale' => ['type' => 'string'],
                            'org_member_id' => ['type' => ['integer', 'null']],
                            'act_mode' => ['type' => 'string', 'enum' => ['draft', 'act', 'mixed']],
                            'tier' => ['type' => ['string', 'null'], 'enum' => ['low', 'medium', 'high', null]],
                            // Задачата изисква вътрешни частни данни, които първо трябва да въведе Управителят.
                            'needs_private_knowledge' => ['type' => 'boolean'],
                        ],
                        'required' => ['type', 'title', 'description', 'rationale'],
                    ],
                ],
            ],
            'required' => ['proposals'],
        ];
    }

    /**
     * KB-осъзнатост за директора: при ПРАЗНА база — твърдо правило да НЕ предлага задачи,
     * изискващи частни данни (gate-ът остава реалната защита, това пести провалени предложения).
     */
    private function kbAwareness(?Company $company): string
    {
        if (! $company || ! KnowledgeService::enabled($company)) {
            return '';
        }

        if ($this->knowledge->isEmpty($company)) {
            return ' ВАЖНО: базата знания на фирмата е ПРАЗНА — системата НЕ знае нищо вътрешно '
                .'(няма имена/роли на служители, вътрешни процедури, графици, клиенти). НЕ предлагай '
                .'задачи, които изискват такива ЧАСТНИ данни (напр. „обучение на треньорите", „график на '
                .'екипа") — те ще се провалят. Предлагай задачи с ПУБЛИЧНА информация (проучване на пазар/'
                .'конкуренти онлайн); ако частна задача е наистина нужна — сложи needs_private_knowledge=true '
                .'(Управителят първо ще въведе данните).';
        }

        $s = $this->knowledge->summary($company);
        $titles = implode(', ', array_slice($s['titles'] ?? [], 0, 6));

        return ' Базата знания съдържа '.$s['documents'].' документа и '.$s['facts'].' факта'
            .($titles !== '' ? ' (напр.: '.$titles.')' : '').'. Ако задача изисква вътрешни частни данни, '
            .'които ги НЯМА тук и не са уеб-търсими, сложи needs_private_knowledge=true.';
    }

    /** @return Collection<int, OrgMember> */
    private function assistantMembers(Director $director): Collection
    {
        return $director->assistants()->with('orgMember')->get()
            ->pluck('orgMember')->filter()->values();
    }

    /** Кратък отчет през персоната на директора (тон), фактологичен. */
    private function report(?OrgMember $directorMember, Collection $assistantMembers, array $proposals, string $trigger): string
    {
        if (! $directorMember) {
            return 'Отдел прегледан. Нови предложения: '.count($proposals).'.';
        }
        $persona = $this->personas->compileSystemPrompt($directorMember);
        $policy = $this->personas->runtimePolicy($directorMember);
        $system = trim($persona."\n\n".'Ти си Директор. Напиши КРАТЪК отчет (2–3 изречения) към Управителя '
            .'на български, в своя тон: как върви отделът и какво предложи. Без вода.');
        $user = "Тригер: {$trigger}. Асистенти: ".$assistantMembers->count()
            .'. Нови предложения този тик: '.count($proposals).'.';

        try {
            return trim($this->generator->chat($system, $user, ['temperature' => (float) ($policy['temperature'] ?? 0.5), 'num_predict' => 400], null, 'director_tick'));
        } catch (\Throwable $e) {
            Log::info('[DirectorTick] report LLM failed: '.$e->getMessage());

            return 'Отдел прегледан. Нови предложения: '.count($proposals).'.';
        }
    }
}
