<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\AgentLoop;
use App\Services\OllamaService;
use App\Support\PaidModel;
use App\Support\RunLog;
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
 * Execution has two modes:
 *  - LOCAL Ollama model → deterministic: every whitelisted tool runs in a fixed
 *    order (search → people → discover → scrape/crawl → document OCR → reviews),
 *    the gathered material is appended to the rendered prompt as named blocks,
 *    then a single LLM call produces the output. Local models don't have
 *    dependable function calling, so the code drives the tools.
 *  - PAID model (openai/…, anthropic/…, …) → agentic: the model itself decides
 *    which whitelisted tools to call, with what arguments and in what order,
 *    via the shared AgentLoop (config.max_steps caps the rounds, default 6).
 *
 * This is what lets the planner invent single-responsibility agents that don't
 * exist as PHP classes ("Откривател на конкуренти", "Одитор на сайта", ...).
 */
class GenericAgent extends BaseAgent
{
    private const MATERIAL_HEADER = "\n\n--- СЪБРАНИ ДАННИ (използвай ги като основен източник; цитирай URL където е уместно) ---\n";

    public function __construct(
        OllamaService $ollama,
        array $tools = [],
        private ?AgentLoop $loop = null,
    ) {
        parent::__construct($ollama, $tools);
    }

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $enabled = $this->enabledTools($agent);

        if ($this->loop && $enabled !== [] && PaidModel::isPaid($agent->model)) {
            return $this->runAgentic($agent, $agentRun, $enabled);
        }
        $params = (array) ($agent->config['tool_params'] ?? []);

        $url = $this->resolveUrl($agent, $agentRun, $context);
        $query = $this->resolveQuery($params, $agent, $agentRun, $context);
        $reviewsQuery = $this->resolveGoogleReviewsQuery($params, $context, $query);

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

        if (in_array('google_reviews', $enabled, true) && $reviewsQuery !== '') {
            $result = $this->useTool('google_reviews', [
                'query' => $reviewsQuery,
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

    /**
     * Agentic mode: the paid model drives the whitelisted tools itself through
     * the shared AgentLoop instead of the deterministic order above.
     *
     * @param  array<int, string>  $enabled
     */
    private function runAgentic(Agent $agent, AgentRun $agentRun, array $enabled): string
    {
        $provider = (string) PaidModel::provider($agent->model);
        $model = PaidModel::strip((string) $agent->model);

        $tools = array_map(fn (string $name) => [
            'name' => $this->tools[$name]->name(),
            'description' => $this->tools[$name]->description(),
            'parameters' => $this->tools[$name]->parameters(),
        ], $enabled);

        $base = ! empty($agent->system_prompt) ? $agent->system_prompt : ($agent->role ?? '');
        $systemPrompt = $base.$this->buildOutputInstructions($agent);
        $options = $this->buildOptions($agent);
        $maxSteps = max(1, min(12, (int) ($agent->config['max_steps'] ?? 0) ?: 6));
        $userMessage = (string) $agentRun->input;

        $this->lastChatParams = [
            'model' => $agent->model,
            'system_prompt' => $systemPrompt,
            'user_message' => $userMessage,
            'options' => [...$options, 'agentic' => true, 'max_steps' => $maxSteps, 'tools' => $enabled],
            'output_language' => $agent->output_language,
            'output_tone' => $agent->output_tone,
            'output_style' => $agent->output_style,
            'output_format' => $agent->output_format,
        ];

        $result = $this->loop->run(
            provider: $provider,
            model: $model,
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            tools: $tools,
            // Tool failures feed back as text — the loop continues and the
            // model can try another tool or different arguments.
            executor: function (string $name, array $args): string {
                try {
                    $output = $this->useTool($name, $args);

                    return ($output === null || trim($output) === '')
                        ? "(инструментът {$name} не върна данни)"
                        : $output;
                } catch (\Throwable $e) {
                    return "Грешка от {$name}: {$e->getMessage()}";
                }
            },
            maxSteps: $maxSteps,
            options: $options,
            wrapUpPrompt: '(Системно: лимитът на стъпки/време е изчерпан. Дай финалния резултат на базата на събраното дотук — без повече инструменти.)',
            // Котва от NodeExecutorService (job timeout минус headroom) — при
            // наближаване loop-ът приключва с частичен резултат вместо job-ът
            // да умре с TimeoutExceededException.
            deadlineTs: isset($agent->config['deadline_ts']) ? (float) $agent->config['deadline_ts'] : null,
        );

        if (($result['deadline_hit'] ?? false) && $agentRun->flow_run_id) {
            RunLog::append((int) $agentRun->flow_run_id, "[TIME] {$agent->name}: времевият бюджет изтече — приключване с наличните данни");
        }

        $this->lastRawOutput = $result['content'];

        return $this->sanitizeModelOutput($result['content'], $systemPrompt, $userMessage, $options);
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

        foreach (['flow_topic', 'topic', 'company_name'] as $key) {
            $fallback = trim((string) ($context[$key] ?? ''));
            if ($fallback !== '') {
                return mb_substr($fallback, 0, 300);
            }
        }

        return trim(mb_substr((string) $agentRun->input, 0, 200));
    }

    private function resolveGoogleReviewsQuery(array $params, array $context, string $fallback): string
    {
        $template = $this->renderParamTemplate($params['search_query'] ?? '', $context);
        if ($template !== '' && ! str_contains($template, '{{')) {
            return mb_substr($template, 0, 300);
        }

        $companyName = trim((string) ($context['company_name'] ?? ''));
        if ($companyName !== '') {
            return mb_substr($companyName, 0, 300);
        }

        return mb_substr(trim($fallback), 0, 300);
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
