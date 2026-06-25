<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Models\OrgProposal;
use App\Services\GeneratorService;
use Illuminate\Support\Facades\Log;

/**
 * Периодично ревю на Управителя (§7.1): KPI/отчети/празноти/qa_score тренд + памет per член
 * → конкретни ПРЕДЛОЖЕНИЯ (нов член/задача, пенсиониране на слаб асистент, нов отдел) през
 * персоната му. Всичко → Кутията (човешко одобрение). Не мутира нищо само.
 */
class OrgReviewService
{
    public function __construct(
        private PersonaService $personas,
        private MemberMemoryService $memory,
        private GeneratorService $generator,
    ) {}

    /**
     * @return array{proposals: array<int>, summary: string}
     */
    public function review(Company $company): array
    {
        $manager = $company->manager;
        $version = $company->activeOrgVersion;
        if (! $manager || ! $version) {
            return ['proposals' => [], 'summary' => 'Няма активна организация за ревю.'];
        }

        // Състояние на отдела: асистенти + KPI + поуки.
        $assistants = $version->assistants()->with('orgMember.persona')->get();
        $state = [];
        foreach ($assistants as $a) {
            $member = $a->orgMember;
            if (! $member) {
                continue;
            }
            $stats = $this->memory->runStats($member);
            $state[] = [
                'member_id' => $member->id,
                'name' => $member->persona?->name ?? $member->display_name,
                'role' => $a->title,
                'runs' => $stats['runs'],
                'avg_qa' => $stats['avg_qa'],
            ];
        }

        $pains = (array) ($company->businessProfile?->pain_points ?? []);
        $design = $this->propose($manager, $state, $pains);

        // Материализирай предложенията в Кутията (durable, base = активната версия).
        $ids = [];
        foreach (array_slice((array) ($design['proposals'] ?? []), 0, 4) as $p) {
            if (empty($p['title'])) {
                continue;
            }
            $ids[] = $this->createProposal($company, $manager, $p)->id;
        }

        // Детерминистичен под: ако нищо валидно не е създадено, но има болки → поне една
        // конкретна задача по водещата болка (ревюто винаги дава нещо действено).
        if ($ids === [] && ($pains[0] ?? null)) {
            $ids[] = $this->createProposal($company, $manager, [
                'type' => 'task',
                'title' => 'Адресирай: '.$pains[0],
                'description' => 'Задача по водещата болка на бизнеса.',
            ])->id;
        }

        $summary = (string) ($design['summary'] ?? 'Ревю завършено.');
        $company->orgEvents()->create([
            'type' => 'review',
            'org_version_id' => $version->id,
            'org_member_id' => $manager->id,
            'summary' => $summary,
            'actor' => 'manager',
        ]);

        return ['proposals' => $ids, 'summary' => $summary];
    }

    /** Създава durable org_proposal(pending) за Кутията. */
    private function createProposal(Company $company, $manager, array $p): OrgProposal
    {
        return OrgProposal::create([
            'company_id' => $company->id,
            'type' => in_array($p['type'] ?? 'task', ['hire', 'fire', 'task', 'mandate'], true) ? $p['type'] : 'task',
            'payload' => [
                'title' => (string) $p['title'],
                'description' => (string) ($p['description'] ?? $p['title']),
                'target_member_id' => $p['target_member_id'] ?? null,
                'proposed_by' => $manager->persona?->name ?? 'Управителя',
            ],
            'base_org_version_id' => $company->active_org_version_id,
        ]);
    }

    /** Управителят предлага през персоната си (LLM); fallback при слаб модел. */
    private function propose($manager, array $state, array $pains): array
    {
        $persona = $this->personas->compileSystemPrompt($manager);
        $system = trim($persona."\n\n".'Ти си Управителят. Прегледай състоянието на екипа и болките на '
            .'бизнеса и предложи 1–3 КОНКРЕТНИ промени (нова задача/наемане/пенсиониране на слаб '
            .'асистент/нов мандат), обосновани от данните. Слаб = малко runs или нисък qa. Върни САМО '
            .'валиден JSON по схемата, на български.');
        $user = 'Екип: '.json_encode($state, JSON_UNESCAPED_UNICODE)."\nБолки: ".implode('; ', $pains);

        try {
            return $this->generator->chatJson($system, $user, 'org_review', $this->schema(), [
                'temperature' => 0.5, 'num_predict' => 1500,
            ]);
        } catch (\Throwable $e) {
            Log::info('[OrgReview] LLM failed, deterministic fallback: '.$e->getMessage());
            // Fallback: предложи задача по първата болка.
            $first = $pains[0] ?? null;

            return [
                'summary' => 'Ревю (опростено): екипът работи; предлагам приоритет по болките.',
                'proposals' => $first ? [['type' => 'task', 'title' => 'Адресирай: '.$first, 'description' => 'Задача по болка от бизнеса.']] : [],
            ];
        }
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'proposals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['hire', 'fire', 'task', 'mandate']],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'target_member_id' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
            'required' => ['summary', 'proposals'],
        ];
    }
}
