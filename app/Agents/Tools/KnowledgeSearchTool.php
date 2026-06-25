<?php

namespace App\Agents\Tools;

use App\Models\Company;
use App\Services\KnowledgeService;

/**
 * RAG retrieval over the company's knowledge base (hybrid: vector + keyword).
 * The company id is baked in at construction (threaded from the FlowRun
 * through the Agent DTO config) — it is never an LLM-controlled argument.
 * Always returns text, never throws: the agentic loop feeds the message back
 * to the model as a tool result.
 */
class KnowledgeSearchTool implements AgentTool
{
    private const MAX_RESULT_CHARS = 4000;

    /** По-щедър от глобалния top_k(5): резултатът и така е capped на chars. */
    private const TOP_K = 8;

    public function __construct(
        private KnowledgeService $service,
        private ?int $companyId,
        private ?int $flowRunId = null,
        private ?string $nodeKey = null,
    ) {}

    public function name(): string
    {
        return 'knowledge_search';
    }

    public function description(): string
    {
        return 'Търси в базата знания на фирмата (качени документи/бележки, обходени страници от сайта ѝ '
            .'и натрупаните факти) и връща най-релевантните откъси с източник — цени, продукти, услуги, '
            .'условия, контакти. ДОПЪЛНИТЕЛЕН източник за фирмени факти — не замества търсенето в интернет.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Какво търсиш във фирмената база знания.'],
                'collection' => [
                    'type' => 'string',
                    'enum' => ['documents', 'site', 'facts', 'all'],
                    'description' => 'documents=качени файлове/бележки/снимки, site=обходени страници от URL ресурси, '
                        .'facts=само натрупаните факти, all=всичко (по подразбиране).',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * Детерминистичният KB pre-pass на GenericAgent: multi-query извадка по
     * чеклистата от промпта, с baked-in фирмен/run контекст. Never throws.
     *
     * @return array{text: string, facts: int, chunks: int, queries: int}
     */
    public function checklist(string $input): array
    {
        $empty = ['text' => '', 'facts' => 0, 'chunks' => 0, 'queries' => 0];

        $company = $this->companyId ? Company::find($this->companyId) : null;
        if (! $company || ! KnowledgeService::enabled($company)) {
            return $empty;
        }

        try {
            return $this->service->searchChecklist(
                $company,
                $input,
                llmContext: ['company_id' => $company->id, 'flow_run_id' => $this->flowRunId],
                flowRunId: $this->flowRunId,
                nodeKey: $this->nodeKey,
            );
        } catch (\Throwable) {
            return $empty;
        }
    }

    public function execute(array $params): string
    {
        $company = $this->companyId ? Company::find($this->companyId) : null;

        if (! $company) {
            return 'Базата знания не е достъпна (липсва фирмен контекст).';
        }

        if (! KnowledgeService::enabled($company)) {
            return 'Базата знания на фирмата е изключена.';
        }

        if ($this->service->isEmpty($company)) {
            return 'Базата знания на фирмата е празна — няма какво да се търси.';
        }

        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            return 'Празна заявка — подай query с това, което търсиш.';
        }

        [$types, $includeFacts, $factsOnly] = $this->collection($params);

        $hits = $this->service->search(
            $company,
            $query,
            $types,
            topK: self::TOP_K,
            llmContext: ['company_id' => $company->id, 'flow_run_id' => $this->flowRunId],
            flowRunId: $this->flowRunId,
            nodeKey: $this->nodeKey,
            includeFacts: $includeFacts,
        );

        if ($factsOnly) {
            $hits = array_values(array_filter($hits, fn ($hit) => $hit['kind'] === 'fact'));
        }

        if ($hits === []) {
            return 'Нищо релевантно не беше намерено в базата знания за: '.$query;
        }

        $out = '';
        foreach ($hits as $i => $hit) {
            if ($hit['kind'] === 'fact') {
                $location = $hit['location'] ? ', '.$hit['location'] : '';
                $entry = '['.($i + 1).'] ФАКТ «'.$hit['title'].'» ('.$hit['category'].$location.")\n"
                    .trim($hit['content'])."\n\n";
            } else {
                $source = match ($hit['source_type']) {
                    'url' => $hit['url'] ?: 'сайт',
                    'note' => 'бележка',
                    'image' => 'снимка',
                    default => 'документ',
                };
                $entry = '['.($i + 1).'] «'.$hit['title'].'» ('.$source
                    .($hit['score'] > 0 ? ', score '.number_format($hit['score'], 2) : '').")\n"
                    .trim($hit['content'])."\n\n";
            }

            if (mb_strlen($out) + mb_strlen($entry) > self::MAX_RESULT_CHARS) {
                break;
            }
            $out .= $entry;
        }

        return trim($out);
    }

    /**
     * Детерминистично мапване на LLM-подадената колекция — невалидна/липсваща
     * стойност пада на "all".
     *
     * @param  array<string, mixed>  $params
     * @return array{0: array<int, string>|null, 1: bool, 2: bool} [types, includeFacts, factsOnly]
     */
    private function collection(array $params): array
    {
        return match ($params['collection'] ?? null) {
            'documents' => [['upload', 'image', 'note'], false, false],
            'site' => [['url'], false, false],
            'facts' => [null, true, true],
            default => [null, true, false],
        };
    }
}
