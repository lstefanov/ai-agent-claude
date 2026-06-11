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
 * Execution: every whitelisted tool runs deterministically (search → people →
 * discover → scrape/crawl → document OCR → reviews), the gathered material is appended to the rendered
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

        if (in_array('pro_search', $enabled, true) && $query !== '') {
            $result = $this->useTool('pro_search', [
                'query' => $query,
                'domains' => $params['domains'] ?? null,
                'max_results' => $params['max_results'] ?? null,
            ]);
            if ($this->usable($result)) {
                $material['Премиум резултати от търсене ('.$query.')'] = $result;
            }
        }

        if (in_array('people_search', $enabled, true)) {
            $peopleQuery = $this->resolvePeopleQuery($params, $agent, $agentRun, $context, $query);
            if ($peopleQuery !== '') {
                $result = $this->useTool('people_search', ['query' => $peopleQuery]);
                if ($this->usable($result)) {
                    $material['Намерени хора и професионални профили ('.$peopleQuery.')'] = $result;
                }
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

        if (in_array('extract_document', $enabled, true)) {
            foreach ($this->documentTargets($url, $agent, $agentRun, $params, $context, $material) as $target) {
                $result = $this->useTool('extract_document', ['url' => $target]);
                if ($this->usable($result)) {
                    $material['OCR документ: '.$target] = $result;
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
        $template = $this->renderParamTemplate($params['search_query'] ?? '', $context);

        if ($template !== '') {
            // Unresolved placeholders left in the template → fall through.
            if (! str_contains($template, '{{')) {
                return mb_substr($template, 0, 300);
            }
        }

        $fallback = $context['flow_topic'] ?? $context['topic'] ?? mb_substr((string) $agentRun->input, 0, 200);

        return trim((string) $fallback);
    }

    private function resolvePeopleQuery(array $params, Agent $agent, AgentRun $agentRun, array $context, string $fallback): string
    {
        $template = $this->renderParamTemplate($params['people_query'] ?? '', $context);
        if ($template !== '' && ! str_contains($template, '{{')) {
            return mb_substr($template, 0, 300);
        }

        return $fallback !== '' ? $fallback : $this->resolveQuery($params, $agent, $agentRun, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderParamTemplate(mixed $value, array $context): string
    {
        $template = trim((string) $value);
        if ($template === '') {
            return '';
        }

        foreach ($context as $key => $contextValue) {
            if (is_string($contextValue)) {
                $template = str_replace('{{'.$key.'}}', $contextValue, $template);
            }
        }

        return trim($template);
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

    /**
     * @param  array<string, string>  $material
     * @return array<int, string>
     */
    private function documentTargets(?string $url, Agent $agent, AgentRun $agentRun, array $params, array $context, array $material): array
    {
        $targets = [];
        $explicit = $this->renderParamTemplate($params['document_url'] ?? '', $context);
        if ($explicit !== '' && ! str_contains($explicit, '{{')) {
            $targets[] = $explicit;
        }

        foreach ([$url, $agent->prompt_template ?? '', $agentRun->input ?? '', implode("\n", $material)] as $source) {
            if (! is_string($source) || trim($source) === '') {
                continue;
            }

            foreach (UrlExtractor::all($source) as $found) {
                if ($this->isDocumentUrl($found)) {
                    $targets[] = $found;
                }
            }
        }

        $max = max(1, min(10, (int) ($params['max_documents'] ?? $params['max_pages'] ?? 5)));

        return array_slice(array_values(array_unique($targets)), 0, $max);
    }

    private function isDocumentUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        return (bool) preg_match('/\.(pdf|png|jpe?g|webp|gif|tiff?|bmp)$/i', $path);
    }

    private function usable(?string $result): bool
    {
        return $result !== null && trim($result) !== ''
            && ! str_starts_with(trim($result), 'No web search results');
    }
}
