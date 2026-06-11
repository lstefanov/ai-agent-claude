<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class PeopleResearcherAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $query = $this->resolveQuery($agent, $agentRun, $context);
        if ($query === '') {
            return 'Няма достатъчно данни за търсене на хора. Подай име, позиция, компания, ниша или локация.';
        }

        if (! filled(config('services.perplexity.api_key'))) {
            return 'Perplexity People Search не е конфигуриран. Добави PERPLEXITY_API_KEY в .env и рестартирай конфигурацията.';
        }

        $peopleResults = $this->useTool('people_search', ['query' => $query]);
        $webResults = $this->useTool('pro_search', ['query' => $query.' LinkedIn contact company role']);

        if (! $this->usable($peopleResults) && ! $this->usable($webResults)) {
            return "Няма намерени публични професионални профили за заявката: {$query}.";
        }

        $extraContext = "\n\n--- PEOPLE SEARCH RESULTS (основен източник; не измисляй контакти, имейли или LinkedIn URL-и) ---\n";
        if ($this->usable($peopleResults)) {
            $extraContext .= $peopleResults."\n\n";
        }
        if ($this->usable($webResults)) {
            $extraContext .= "--- SUPPORTING WEB RESULTS ---\n{$webResults}\n";
        }

        $extraContext .= "\nИзгради структуриран профил само от наличните публични данни: име, позиция, компания/организация, локация, публичен профил/URL, доказателство и увереност.";

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    private function resolveQuery(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $params = (array) ($agent->config['tool_params'] ?? []);
        $template = trim((string) ($params['people_query'] ?? $params['search_query'] ?? ''));

        if ($template !== '') {
            foreach ($context as $key => $value) {
                if (is_string($value)) {
                    $template = str_replace('{{'.$key.'}}', $value, $template);
                }
            }

            if (! str_contains($template, '{{')) {
                return mb_substr($template, 0, 300);
            }
        }

        return trim((string) ($context['flow_topic'] ?? $context['topic'] ?? mb_substr($agentRun->input, 0, 240)));
    }

    private function usable(?string $result): bool
    {
        return $result !== null && trim($result) !== '';
    }
}
