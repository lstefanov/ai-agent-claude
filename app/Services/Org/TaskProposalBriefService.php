<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Services\GeneratorService;
use App\Services\KnowledgeService;
use App\Support\BillableUnit;
use App\Support\GraphTopology;
use Illuminate\Support\Facades\Log;

/**
 * Бриф на предложение от draft flow-а (§6.3): чете nodes/edges/tools на активната версия,
 * подрежда по DAG вълни и преразказва „защо + как" от 1-во лице с персоната на служителя →
 * пише в assistant_tasks.proposal. Best-effort: при провал на LLM-а → детерминистичен
 * fallback + warning. Никога не проваля генерацията (flow-ът вече е готов).
 */
class TaskProposalBriefService
{
    /** Максимален брой стъпки в брифа (за да не раздува UI/промпта). */
    private const MAX_STEPS = 8;

    /** Чисти трансформатори — не са „стъпка" за клиента. */
    private const TRANSFORMER_TYPES = ['bg_text_corrector', 'translator'];

    public function __construct(
        private PersonaService $personas,
        private MemberMemoryService $memory,
        private GeneratorService $generator,
        private KnowledgeService $knowledge,
    ) {}

    /** Построява и записва брифа на предложението в assistant_tasks.proposal. */
    public function build(AssistantTask $task): void
    {
        $flow = $task->flow;
        $member = $task->orgMember;
        $version = $flow?->activeVersion;

        if (! $flow || ! $member || ! $version) {
            $this->persist($task, $this->baseProposal($task, $version, [], [], ['needs_review']));

            return;
        }

        // Детерминистична основа: стъпки + инструменти от DAG-а.
        [$steps, $tools] = $this->stepsFromGraph($version);
        $warnings = [];
        if ($steps === [] || $tools === []) {
            $warnings[] = 'needs_review';   // твърде общ flow / без инструменти
        }

        // Персона-глас (best-effort — провал → детерминистичен fallback + warning).
        $voiced = null;
        try {
            $voiced = $this->voiceBrief($task, $member, $steps, $tools);
        } catch (\Throwable $e) {
            Log::info('[ProposalBrief] LLM failed, deterministic fallback: '.$e->getMessage());
            $warnings[] = 'brief_failed';
        }

        $proposal = $this->baseProposal(
            $task,
            $version,
            $this->mergeSteps($steps, $voiced['steps'] ?? null),
            $tools,
            array_values(array_unique($warnings)),
            $voiced['rationale'] ?? $this->fallbackRationale($task),
            $voiced['expected_impact'] ?? '',
        );

        $this->persist($task, $proposal);
    }

    /**
     * Стъпки от графа в DAG ред (вълни) + плосък списък инструменти.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function stepsFromGraph(FlowVersion $version): array
    {
        $nodes = $version->nodes()
            ->where('is_active', true)
            ->where('type', '!=', 'qa_verifier')
            ->get();

        if ($nodes->isEmpty()) {
            return [[], []];
        }

        $nodeKeys = $nodes->pluck('node_key')->map('strval')->all();
        $edges = $version->edges()->get(['from_node_key', 'to_node_key'])
            ->map(fn ($e) => ['from' => (string) $e->from_node_key, 'to' => (string) $e->to_node_key])
            ->all();

        $analysis = GraphTopology::analyze($nodeKeys, $edges);
        $order = $analysis['waves'] !== [] ? array_merge(...$analysis['waves']) : $nodeKeys;

        $byKey = $nodes->keyBy('node_key');
        $steps = [];
        $allTools = [];
        $i = 1;
        foreach ($order as $key) {
            /** @var FlowNode|null $node */
            $node = $byKey->get($key);
            if (! $node || in_array($node->type, self::TRANSFORMER_TYPES, true)) {
                continue;
            }

            $nodeTools = array_values(array_map('strval', (array) ($node->config['tools'] ?? [])));
            $allTools = array_merge($allTools, $nodeTools);
            $steps[] = [
                'order' => $i++,
                'node_key' => (string) $key,
                'node_name' => (string) ($node->name ?: $node->role ?: $key),
                'summary' => mb_substr((string) ($node->name ?: $node->role ?: $node->type), 0, 160),
                'tools' => $nodeTools,
            ];

            if (count($steps) >= self::MAX_STEPS) {
                break;
            }
        }

        return [$steps, array_values(array_unique($allTools))];
    }

    /**
     * Персона-глас на брифа (rationale + impact + преразказани стъпки).
     *
     * @return array{rationale: string, expected_impact: string, steps: list<array<string, mixed>>}
     */
    private function voiceBrief(AssistantTask $task, $member, array $steps, array $tools): array
    {
        $persona = $this->personas->compileSystemPrompt($member);
        $policy = $this->personas->runtimePolicy($member);
        $reflection = $this->memory->reflectionBlock($member);

        $kb = '';
        try {
            $kb = $this->knowledge->ownProfileBlock($member->company);
        } catch (\Throwable) {
        }

        $stepLines = collect($steps)
            ->map(fn ($s) => $s['order'].') '.$s['node_name'].($s['tools'] ? ' ['.implode(', ', $s['tools']).']' : ''))
            ->implode("\n");

        $system = trim($persona."\n\n"
            .'Ти си този служител. Обясни КРАТКО на собственика защо предлагаш тази задача (rationale) '
            .'и какво ще подобри (expected_impact), от 1-во лице, в своя тон, на български. После преразкажи '
            .'стъпките с твоите думи — по една кратка стъпка на ред, със същия order. Не измисляй стъпки '
            .'извън дадените. Върни САМО валиден JSON по схемата.'
            .($reflection !== '' ? "\n\n".$reflection : '')
            .($kb !== '' ? "\n\n".$kb : ''));

        $user = "Задача: {$task->title}\nОписание: {$task->description}\n"
            .'Инструменти: '.(implode(', ', $tools) ?: '—')."\nСтъпки (от flow-а):\n".$stepLines;

        $raw = $this->generator->chatJson($system, $user, 'task_proposal_brief', $this->schema(), [
            'temperature' => (float) ($policy['temperature'] ?? 0.5), 'num_predict' => 900,
        ]);

        return [
            'rationale' => trim((string) ($raw['rationale'] ?? '')),
            'expected_impact' => trim((string) ($raw['expected_impact'] ?? '')),
            'steps' => array_values((array) ($raw['steps'] ?? [])),
        ];
    }

    /** Слива персона-преразказа върху детерминистичните стъпки по order. */
    private function mergeSteps(array $steps, ?array $voiced): array
    {
        if (! $voiced) {
            return $steps;
        }

        $byOrder = collect($voiced)->keyBy(fn ($s) => (int) ($s['order'] ?? 0));

        return array_map(function ($s) use ($byOrder) {
            $v = $byOrder->get($s['order']);
            if ($v && ! empty($v['summary'])) {
                $s['summary'] = (string) $v['summary'];
            }

            return $s;
        }, $steps);
    }

    /** Общата форма на proposal json (§6.2). */
    private function baseProposal(
        AssistantTask $task,
        ?FlowVersion $version,
        array $steps,
        array $tools,
        array $warnings,
        ?string $rationale = null,
        string $impact = '',
    ): array {
        return [
            'rationale' => $rationale ?? $this->fallbackRationale($task),
            'expected_impact' => $impact,
            'steps' => $steps,
            'tools' => $tools,
            'estimated_cost' => [
                'credits' => BillableUnit::estimate($task),
                'usd' => null,   // реалната цена идва след run (Фаза 8)
            ],
            'approval_policy' => $task->approval_policy,
            'generated_from_flow_version_id' => $version?->id,
            'brief_generated_at' => now()->toIso8601String(),
            'warnings' => $warnings,
        ];
    }

    private function fallbackRationale(AssistantTask $task): string
    {
        return 'Предлагам „'.$task->title.'" — '.mb_substr((string) $task->description, 0, 200);
    }

    private function persist(AssistantTask $task, array $proposal): void
    {
        $task->update(['proposal' => $proposal]);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rationale' => ['type' => 'string'],
                'expected_impact' => ['type' => 'string'],
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'order' => ['type' => 'integer'],
                            'summary' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'required' => ['rationale', 'expected_impact', 'steps'],
        ];
    }
}
