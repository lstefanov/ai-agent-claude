<?php

namespace App\Agents\Tools;

use App\Models\Company;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeService;

/**
 * RAG retrieval over the company's knowledge base. The company id is baked in
 * at construction (threaded from the FlowRun through the Agent DTO config) —
 * it is never an LLM-controlled argument. Always returns text, never throws:
 * the agentic loop feeds the message back to the model as a tool result.
 */
class KnowledgeSearchTool implements AgentTool
{
    private const MAX_RESULT_CHARS = 4000;

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
        return 'Търси в базата знания на фирмата (качени документи и съдържание от сайта ѝ) и връща '
            .'най-релевантните откъси с източник — цени, продукти, услуги, условия, фирмени факти.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Какво търсиш във фирмената база знания.'],
                'collection' => [
                    'type' => 'string',
                    'enum' => ['documents', 'site', 'history', 'all'],
                    'description' => 'documents=качени документи, site=сайтът на фирмата, '
                        .'history=резултати от предишни изпълнения, all=всичко. По подразбиране: documents+site.',
                ],
            ],
            'required' => ['query'],
        ];
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

        $types = $this->sourceTypes($params);

        if (! $company->knowledgeDocuments()->ready()->whereIn('source_type', $types)->exists()) {
            return 'Базата знания на фирмата е празна в тази колекция — няма какво да се търси.';
        }

        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            return 'Празна заявка — подай query с това, което търсиш.';
        }

        $hits = $this->service->search(
            $company,
            $query,
            $types,
            llmContext: ['company_id' => $company->id, 'flow_run_id' => $this->flowRunId],
            flowRunId: $this->flowRunId,
            nodeKey: $this->nodeKey,
        );

        if ($hits === []) {
            return 'Нищо релевантно не беше намерено в базата знания за: '.$query;
        }

        $out = '';
        foreach ($hits as $i => $hit) {
            $source = match ($hit['source_type']) {
                'site' => 'сайт',
                'run' => 'предишно изпълнение',
                default => 'документ',
            };
            $entry = '['.($i + 1).'] «'.$hit['title'].'» ('.$source.', score '.number_format($hit['score'], 2).")\n"
                .trim($hit['content'])."\n\n";
            if (mb_strlen($out) + mb_strlen($entry) > self::MAX_RESULT_CHARS) {
                break;
            }
            $out .= $entry;
        }

        return trim($out);
    }

    /**
     * Детерминистично мапване на LLM-подадената колекция към source_types —
     * невалидна/липсваща стойност пада на grounding по подразбиране.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, string>
     */
    private function sourceTypes(array $params): array
    {
        return match ($params['collection'] ?? null) {
            'documents' => ['upload', 'url'],
            'site' => ['site'],
            'history' => ['run'],
            'all' => ['upload', 'url', 'site', 'run'],
            default => KnowledgeDocument::GROUNDING_TYPES,
        };
    }
}
