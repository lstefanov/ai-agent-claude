<?php

namespace App\Agents\Tools;

/**
 * Единен `web_search` инструмент: рутира към конфигурирания провайдър
 * (services.web_search.provider — 'brave' по подразбиране, или 'perplexity').
 *
 * Не дублира форматирането — делегира execute() към съответния вече
 * съществуващ инструмент (BraveSearchTool / PerplexitySearchTool). Така
 * провайдърът за търсене е ЯВЕН env (WEB_SEARCH_PROVIDER), не скрит хардкод.
 */
class WebSearchTool implements AgentTool
{
    public function __construct(
        private BraveSearchTool $brave,
        private PerplexitySearchTool $perplexity,
    ) {}

    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Търсене в интернет — рутира към конфигурирания провайдър (Brave по подразбиране, или Perplexity чрез WEB_SEARCH_PROVIDER). Връща топ резултати със заглавие, URL и описание.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Заявка за търсене.'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): string
    {
        return config('services.web_search.provider') === 'perplexity'
            ? $this->perplexity->execute($params)
            : $this->brave->execute($params);
    }
}
