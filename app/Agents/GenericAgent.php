<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Support\UrlExtractor;

/**
 * The "on the fly" agent behind type=custom. It has NO hardcoded behaviour —
 * the FlowPlannerService composes one per task by declaring which tools to run
 * in config:
 *
 *   config:
 *     tools: ["web_search", "scrape_page"]            # ordered whitelist
 *     tool_params:
 *       search_query: "{{topic}} новини"              # optional, {{}} substituted
 *       max_results: 10
 *
 * Execution: every whitelisted tool runs deterministically (search → discover →
 * scrape/crawl → reviews), the gathered material is appended to the rendered
 * prompt as named blocks, then a single LLM call produces the output. This is
 * what lets the planner invent single-responsibility agents that don't exist
 * as PHP classes ("Откривател на конкуренти", "Одитор на сайта", ...).
 */
class GenericAgent extends BaseAgent
{
    private const MATERIAL_HEADER = "\n\n--- СЪБРАНИ ДАННИ (използвай ги като основен източник; цитирай URL където е уместно) ---\n";

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $enabled = $this->enabledTools($agent);
        $params = (array) ($agent->config['tool_params'] ?? []);

        $url = $this->resolveUrl($agent, $agentRun, $context);
        $query = $this->resolveQuery($params, $agent, $agentRun, $context);

        $material = [];

        if (in_array('web_search', $enabled, true) && $query !== '') {
            $result = $this->useTool('web_search', ['query' => $query]);
            if ($this->usable($result)) {
                $material['Резултати от търсене ('.$query.')'] = $result;
            }
        }

        if (in_array('google_reviews', $enabled, true) && $query !== '') {
            $result = $this->useTool('google_reviews', [
                'query' => $query,
                'region' => $params['region'] ?? null,
            ]);
            if ($this->usable($result)) {
                $material['Google ревюта'] = $result;
            }
        }

        if (in_array('discover_urls', $enabled, true) && $url !== null) {
            $result = $this->useTool('discover_urls', [
                'url' => $url,
                'max' => $params['max_pages'] ?? null,
            ]);
            if ($this->usable($result)) {
                $material['Открити страници на '.$url] = $result;
            }
        }

        if (in_array('crawl_site', $enabled, true) && $url !== null) {
            $result = $this->useTool('crawl_site', [
                'url' => $url,
                'max' => $params['max_pages'] ?? null,
            ]);
            if ($this->usable($result)) {
                $material['Съдържание на сайта '.$url] = $result;
            }
        } elseif (in_array('scrape_page', $enabled, true)) {
            // Scrape the target URL plus any URLs an upstream agent produced
            // (e.g. a competitor-finder feeding a competitor-scraper).
            foreach ($this->scrapeTargets($url, $agentRun, $params) as $target) {
                $result = $this->useTool('scrape_page', ['url' => $target]);
                if ($this->usable($result)) {
                    $material['Страница: '.$target] = $result;
                }
            }
        }

        $extraContext = '';
        if ($material !== []) {
            $blocks = [];
            foreach ($material as $label => $content) {
                $blocks[] = "[{$label}]:\n".$content;
            }
            $extraContext = self::MATERIAL_HEADER.implode("\n\n", $blocks);
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    /** @return array<int, string> */
    private function enabledTools(Agent $agent): array
    {
        $tools = array_map('strval', (array) ($agent->config['tools'] ?? []));

        // Only tools that are both whitelisted in config AND actually registered.
        return array_values(array_filter($tools, fn ($t) => $this->hasTool($t)));
    }

    private function resolveUrl(Agent $agent, AgentRun $agentRun, array $context): ?string
    {
        foreach (['target_url', 'url'] as $key) {
            if (! empty($context[$key]) && is_string($context[$key])) {
                return $context[$key];
            }
        }

        return UrlExtractor::first($agent->prompt_template ?? '')
            ?: UrlExtractor::first($agentRun->input ?? '');
    }

    private function resolveQuery(array $params, Agent $agent, AgentRun $agentRun, array $context): string
    {
        $template = trim((string) ($params['search_query'] ?? ''));

        if ($template !== '') {
            foreach ($context as $key => $value) {
                if (is_string($value)) {
                    $template = str_replace('{{'.$key.'}}', $value, $template);
                }
            }

            // Unresolved placeholders left in the template → fall through.
            if (! str_contains($template, '{{')) {
                return mb_substr($template, 0, 300);
            }
        }

        $fallback = $context['flow_topic'] ?? $context['topic'] ?? mb_substr((string) $agentRun->input, 0, 200);

        return trim((string) $fallback);
    }

    /**
     * URLs for scrape_page: the resolved target + URLs found in the upstream input
     * (capped — a competitor list can easily contain 30+ links).
     *
     * @return array<int, string>
     */
    private function scrapeTargets(?string $url, AgentRun $agentRun, array $params): array
    {
        $targets = $url !== null ? [$url] : [];

        foreach (UrlExtractor::all((string) $agentRun->input) as $found) {
            $targets[] = $found;
        }

        $max = max(1, min(25, (int) ($params['max_pages'] ?? 10)));

        return array_slice(array_values(array_unique($targets)), 0, $max);
    }

    private function usable(?string $result): bool
    {
        return $result !== null && trim($result) !== ''
            && ! str_starts_with(trim($result), 'No web search results');
    }
}
