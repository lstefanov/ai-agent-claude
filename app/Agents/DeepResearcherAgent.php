<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\CrawlService;
use App\Services\OllamaService;
use App\Support\PageContent;
use App\Support\PricingSourceQuality;
use Illuminate\Support\Facades\Http;

class DeepResearcherAgent extends BaseAgent
{
    private CrawlService $crawl;

    /**
     * @param  array<int, \App\Agents\Tools\AgentTool>  $tools
     */
    public function __construct(OllamaService $ollama, array $tools = [], ?CrawlService $crawl = null)
    {
        parent::__construct($ollama, $tools);
        $this->crawl = $crawl ?? new CrawlService;
    }

    /**
     * Maximum total chars of scraped site content passed to the synthesis LLM.
     * Keeps the system prompt within the effective context window of mistral-nemo
     * (~40K chars). Homepage is always first, so the most important page is never cut.
     */
    private const MAX_SITE_CONTEXT_CHARS = 50000;

    private const PRICING_KEYWORDS = [
        'цен', 'абон', 'карт',
        'tseni', 'tsena', 'ceni', 'cena', 'abon',
        'price', 'pricing', 'member', 'plan', 'tarif', 'subscribe', 'kart',
    ];

    private const PRICING_PATHS = [
        '/цени', '/цена', '/абонамент', '/абонаменти', '/карти',
        '/ceni', '/tseni', '/tseni.html', '/cenorazpis', '/cennik',
        '/abonamant', '/abonament', '/abonamenti', '/karti',
        '/prices', '/price', '/pricing', '/price-list', '/price-list.html',
        '/membership', '/memberships',
        '/plans', '/plan', '/tarifi', '/tariffs', '/subscribe', '/karti',
    ];

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config    = $agent->config ?? [];
        $targetUrl = $context['target_url'] ?? $context['url'] ?? null;

        // ── Phase 0: Site crawl ──────────────────────────────────────────────
        // When the flow targets a specific site, crawl ALL its pages first.
        // This is the PRIMARY data source; general web searches are skipped to
        // avoid flooding the context with irrelevant results.
        $siteContent   = '';
        $mapReduceUsed = false;
        if (! empty($targetUrl)) {
            $systemPageCap = (int) config('services.crawl.max_pages', 20);
            // Map-reduce: summarize each page separately so a single LLM call never
            // exceeds the (small) context window of models like mistral-nemo. The
            // merged output is compact per-page summaries, not raw concatenated HTML.
            if (($config['map_reduce'] ?? true) && $this->hasTool('discover_urls')) {
                // When map_reduce is active, token limits are no longer the bottleneck,
                // so default to a much higher cap. The agent config can still override.
                $siteMax       = (int) ($config['max_pages_to_scrape'] ?? 200);
                $siteContent   = $this->mapReduceCrawl($agent, $agentRun, $targetUrl, $siteMax);
                $mapReduceUsed = $siteContent !== '';
            }

            // Fallback: legacy concatenate-and-truncate path (map_reduce disabled,
            // discovery unavailable, or it returned nothing).
            if (! $mapReduceUsed) {
                $siteMax = (int) ($config['max_pages_to_scrape'] ?? $systemPageCap);
                // crawl_site discovers pages via sitemap + internal links (no hardcoded
                // path guessing) and scrapes them.
                if ($this->hasTool('crawl_site')) {
                    $siteContent = (string) $this->useTool('crawl_site', ['url' => $targetUrl, 'max' => $siteMax]);
                }

                // Last resort: read the given homepage URL itself. This is the URL we
                // were handed — NOT a guessed path — so it keeps the "no hardcoded URLs"
                // rule while still degrading gracefully when discovery is unavailable.
                if ($siteContent === '' && $this->hasTool('scrape_page')) {
                    $homepage = $this->useTool('scrape_page', ['url' => $targetUrl]);
                    if ($homepage && $homepage !== 'Scraping not available for this page.') {
                        $siteContent = "=== SCRAPED PAGE: {$targetUrl} ===\n{$homepage}";
                    }
                }

                // Cap total site content so we stay inside the synthesis LLM's context
                // window. Homepage is always first (added by CrawlService), so the most
                // important page is never truncated.
                if (mb_strlen($siteContent) > self::MAX_SITE_CONTEXT_CHARS) {
                    $siteContent = mb_substr($siteContent, 0, self::MAX_SITE_CONTEXT_CHARS)
                        ."\n\n[Останалото съдържание е пропуснато поради размера на контекста.]";
                }
            }
        }

        // ── Phase 1: Web search ──────────────────────────────────────────────
        $allResults = '';
        if (! empty($targetUrl)) {
            // For site-analysis flows the site itself IS the data source, so generic
            // queries ("primelaser.bg услуги") add nothing. Run ONE review search only.
            $domain      = strtolower(preg_replace('/^www\./i', '', parse_url($targetUrl, PHP_URL_HOST) ?? ''));
            $reviewQuery = "\"{$domain}\" reviews OR отзиви OR мнения OR ревюта";
            $reviewRes   = $this->useTool('web_search', ['query' => $reviewQuery]);
            if ($reviewRes !== null) {
                $allResults = "\n\n=== REVIEWS SEARCH: \"{$reviewQuery}\" ===\n"
                    .PricingSourceQuality::filterSearchResults($reviewRes);
            }
        } else {
            // Generic flow: generate and run multi-angle search queries.
            $queryTemplates = $config['search_queries'] ?? [];
            if (empty($queryTemplates)) {
                $queryTemplates = $this->generateSearchQueries($agent, $agentRun->input, $context);
            } else {
                foreach ($queryTemplates as &$tpl) {
                    foreach ($context as $key => $value) {
                        if (is_string($value)) {
                            $tpl = str_replace('{{'.$key.'}}', $value, $tpl);
                        }
                    }
                }
                unset($tpl);
            }

            foreach ($queryTemplates as $i => $query) {
                $results = $this->useTool('web_search', ['query' => $query]);
                if ($results !== null) {
                    $num         = $i + 1;
                    $allResults .= "\n\n=== SEARCH {$num}: \"{$query}\" ===\n"
                        .PricingSourceQuality::filterSearchResults($results);
                }
            }
        }

        // ── Phase 2: Scrape competitor pricing pages (non-target flows only) ─
        $scrapedContent = '';
        if (empty($targetUrl) && $allResults && ($config['scrape_pricing_pages'] ?? true) && $this->hasTool('scrape_page')) {
            $maxPages       = (int) ($config['max_pages_to_scrape'] ?? config('services.crawl.max_pages', 3));
            $scrapedContent = $this->scrapeTopPricingPages($allResults, $maxPages);
        }

        // ── Anti-hallucination guard ─────────────────────────────────────────
        // A site flow that scraped ZERO content must NOT be synthesized into a
        // report — the LLM would invent prices/contacts (exactly what happened in
        // run 65 when Crawl4AI was down). Return an explicit notice instead so the
        // downstream report reflects reality rather than fabricated data.
        if (! empty($targetUrl) && $siteContent === '') {
            $this->runLog($agentRun, '[CRAWL] ⚠️ 0 страници свалени — скрейп услугата (Crawl4AI :8189) е недостъпна или сайтът блокира заявките');

            return "⚠️ Сайтът {$targetUrl} НЕ можа да бъде обходен — скрейп услугата (Crawl4AI) е недостъпна или сайтът блокира заявките.\n"
                ."НЯМА реални данни за услуги, цени, контакти или ревюта. НЕ предлагай никакви конкретни цени или телефони — те не са налични.\n"
                .'Препоръка: увери се, че скрейп услугата работи (./scripts/start-services.sh status) и пусни flow-а отново.'
                .($allResults !== '' ? "\n\n[Само непотвърдени резултати от уеб търсене, БЕЗ официални цени/контакти]:\n{$allResults}" : '');
        }

        // ── Phase 3: Output ──────────────────────────────────────────────────
        // When map-reduce produced a structured knowledge base, RETURN IT DIRECTLY.
        // Re-synthesizing it through another LLM call truncated the input (the chat
        // ran with Ollama's small default num_ctx) and HALLUCINATED prices/contacts
        // (run 67: fake phone, "no location" although /контакти was scraped). The
        // per-page summaries are already real, structured data; the downstream
        // analyzer consolidates them with an adequate context window.
        if ($mapReduceUsed && $siteContent !== '') {
            $out = "БАЗА ЗНАНИЯ ОТ САЙТА {$targetUrl}"
                ." (реални данни, извлечени страница по страница — използвай ги ДОСЛОВНО"
                ." за услуги, цени, контакти и адрес; не обобщавай и не измисляй):\n\n{$siteContent}";
            if ($allResults !== '') {
                $out .= "\n\n--- ДОПЪЛНИТЕЛНО: уеб търсене (вторичен източник, без официални цени) ---\n{$allResults}";
            }

            return $out;
        }

        // Legacy / non-map-reduce path: content is small enough to synthesize safely.
        $extraContext = '';
        if ($siteContent) {
            $extraContext .= "\n\n--- ПЪЛНО СЪДЪРЖАНИЕ НА САЙТА {$targetUrl}"
                ." (ОСНОВЕН и ЕДИНСТВЕН източник за услуги, цени и контакти."
                ." Използвай САМО тези реални данни — не измисляй нищо) ---\n{$siteContent}";
        }
        if ($allResults) {
            $extraContext .= "\n\n--- WEB SEARCH RESULTS ---\n{$allResults}";
        }
        if ($scrapedContent) {
            $extraContext .= "\n\n--- SCRAPED PRICING PAGES ---\n{$scrapedContent}";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    /**
     * Map-reduce crawl: discover the site's pages, summarize each one separately
     * (so no single LLM call exceeds the context window), then merge the compact
     * per-page summaries. Returns a knowledge base of "=== url (type) ===" blocks,
     * or '' if nothing could be summarized (caller falls back to the legacy path).
     */
    private function mapReduceCrawl(Agent $agent, AgentRun $agentRun, string $targetUrl, int $maxPages): string
    {
        $urlsRaw = $this->useTool('discover_urls', ['url' => $targetUrl, 'max' => $maxPages]);
        $urls    = array_values(array_filter(array_map('trim', explode("\n", (string) $urlsRaw))));

        if (empty($urls)) {
            $this->runLog($agentRun, "[DISCOVERY] няма открити URL-и за {$targetUrl}");

            return '';
        }
        $this->runLog($agentRun, '[DISCOVERY] открити '.count($urls)." страници (cap {$maxPages})");

        $config        = $agent->config ?? [];
        // Defaults raised so a price-DENSE page (e.g. a WooCommerce category with
        // 39 services) is neither truncated on input nor cut off on output:
        //  - max_page_chars 30000: a 28K-char pricing page fits whole;
        //  - page_summary_tokens 1500: a CEILING, not a target — short pages stay
        //    short, only data-rich pages use it, so all 39 services+prices fit;
        //  - num_ctx 16384: ~32K-char context so the 30K input is not clipped.
        $summaryTokens = max(64, (int) ($config['page_summary_tokens'] ?? 1500));
        $maxPageChars  = max(1000, (int) ($config['max_page_chars'] ?? 30000));
        $concurrency   = max(1, (int) ($config['map_concurrency'] ?? 4));
        $numCtx        = max(2048, (int) ($config['num_ctx'] ?? 16384));

        // Per-page extraction can use a SMALLER/faster model than the agent's main
        // model (the heavy synthesis stays on $agent->model). A small model both
        // runs faster and leaves GPU headroom so OLLAMA_NUM_PARALLEL actually helps.
        $mapModel = trim((string) ($config['map_model'] ?? '')) ?: $agent->model;

        $urls = array_slice($urls, 0, $maxPages);

        // Sequential fallback (map_concurrency = 1) keeps the original behaviour.
        if ($concurrency === 1) {
            return $this->mapReduceSequential($agent, $agentRun, $urls, $config, $summaryTokens, $maxPageChars, $mapModel, $numCtx);
        }

        // Process in waves of $concurrency so progress is logged INCREMENTALLY
        // (each page that finishes shows up in the run log and the live UI),
        // while fetch + summarization within a wave still run concurrently.
        //
        // Unless Ollama runs with OLLAMA_NUM_PARALLEL >= $concurrency, it processes
        // the wave's requests SERIALLY, so the last one waits behind the others.
        // The per-request timeout must therefore cover the whole wave, otherwise
        // the queued requests time out and come back empty. Scale it accordingly.
        $waveTimeout  = max(self::PAGE_SUMMARY_TIMEOUT_S, $concurrency * self::PER_PAGE_BUDGET_S);
        $systemPrompt = $this->pageSummarySystemPrompt();
        $summaries    = [];
        $ok           = 0;
        $failed       = 0;

        foreach (array_chunk($urls, $concurrency) as $wave) {
            // Concurrent fetch for this wave.
            $pages = $this->crawl->scrapeMany($wave, $concurrency); // url => markdown

            // Build one capped, boilerplate-stripped request per fetched page.
            $requests = [];
            foreach ($wave as $url) {
                $markdown = $pages[$url] ?? null;
                if (! is_string($markdown) || trim($markdown) === '') {
                    continue;
                }
                $content = PageContent::stripBoilerplate($markdown);
                if (trim($content) === '') {
                    continue;
                }
                if (mb_strlen($content) > $maxPageChars) {
                    $content = mb_substr($content, 0, $maxPageChars);
                }
                $requests[$url] = [
                    'model'   => $mapModel,
                    'system'  => $systemPrompt,
                    'user'    => "URL: {$url}\n\nСъдържание:\n{$content}",
                    'options' => [
                        'temperature' => 0.2,
                        'num_predict' => $summaryTokens,
                        'num_ctx'     => $numCtx,
                    ],
                ];
            }

            // Concurrent summarization for this wave.
            $answers = $this->ollama->chatBatch($requests, $concurrency, $waveTimeout);

            // Assemble + log each page in this wave before moving on.
            foreach ($wave as $url) {
                if (! isset($requests[$url])) {
                    $failed++;
                    $this->runLog($agentRun, "[MAP] {$url} → FAILED (няма съдържание)");

                    continue;
                }
                $summary = trim((string) ($answers[$url] ?? ''));
                if ($summary === '') {
                    $failed++;
                    $this->runLog($agentRun, "[MAP] {$url} → FAILED (празно резюме)");

                    continue;
                }
                $type        = $this->classifyUrl($url);
                $summaries[] = "=== {$url} ({$type}) ===\n{$summary}";
                $ok++;
                $this->runLog($agentRun, "[MAP] {$url} → резюме ".mb_strlen($summary).' chars (1 chunk)');
            }
        }

        $this->runLog($agentRun, "[MERGE] {$ok}/".count($urls)." страници резюмирани ({$failed} неуспешни)");

        return implode("\n\n", $summaries);
    }

    /**
     * Original sequential path, kept for map_concurrency = 1. One LLM call per
     * page (content capped so it stays a single chunk).
     *
     * @param  array<int, string>  $urls
     * @param  array<string, mixed>  $config
     */
    private function mapReduceSequential(Agent $agent, AgentRun $agentRun, array $urls, array $config, int $summaryTokens, int $maxPageChars, string $mapModel, int $numCtx): string
    {
        $chunkSize = max(1000, (int) ($config['chunk_size_chars'] ?? $maxPageChars));
        $overlap   = max(0, (int) ($config['chunk_overlap_chars'] ?? 500));

        $summaries = [];
        $ok        = 0;
        $failed    = 0;

        foreach ($urls as $url) {
            $markdown = $this->useTool('scrape_page', ['url' => $url]);
            if ($markdown === null || $markdown === '' || $markdown === 'Scraping not available for this page.') {
                $failed++;
                $this->runLog($agentRun, "[MAP] {$url} → FAILED (няма съдържание)");

                continue;
            }

            $content = PageContent::stripBoilerplate($markdown);
            if (trim($content) === '') {
                $failed++;
                $this->runLog($agentRun, "[MAP] {$url} → FAILED (празно след почистване)");

                continue;
            }
            if (mb_strlen($content) > $maxPageChars) {
                $content = mb_substr($content, 0, $maxPageChars);
            }

            $chunks  = $this->chunkText($content, $chunkSize, $overlap);
            $summary = trim($this->summarizePage($agent, $url, $chunks, $summaryTokens, $mapModel, $numCtx));
            if ($summary === '') {
                $failed++;
                $this->runLog($agentRun, "[MAP] {$url} → FAILED (празно резюме)");

                continue;
            }

            $type        = $this->classifyUrl($url);
            $summaries[] = "=== {$url} ({$type}) ===\n{$summary}";
            $ok++;
            $chunkCount = count($chunks);
            $this->runLog($agentRun, "[MAP] {$url} → резюме ".mb_strlen($summary)." chars ({$chunkCount} chunk".($chunkCount === 1 ? '' : 's').')');
        }

        $this->runLog($agentRun, "[MERGE] {$ok}/".count($urls)." страници резюмирани ({$failed} неуспешни)");

        return implode("\n\n", $summaries);
    }

    private function pageSummarySystemPrompt(): string
    {
        // The model READS and UNDERSTANDS each page — it is NOT told which strings
        // or URLs to look for. It decides what the page is and extracts whatever real
        // data is present. This is what lets prices on individual service pages get
        // captured without any hardcoded price keywords or URL patterns.
        return implode(' ', [
            'Ти анализираш съдържанието на ЕДНА уеб страница и разбираш какво представлява.',
            'Първо определи типа ѝ (начална, услуга, ценоразпис, контакти, за нас, блог, друго).',
            'После извлечи САМО реално присъстващите данни:',
            '• УСЛУГИ И ЦЕНИ: изведи ВСЯКА услуга/продукт с цена на ОТДЕЛЕН ред във формат "Услуга — Цена (валута)".',
            'ВАЖНО: ако страницата е ценоразпис/каталог с много услуги (10, 20, 39…), изброй ги ВСИЧКИТЕ — не съкращавай, не пиши "и други", не давай само първите няколко.',
            'Ако страницата описва конкретна услуга и цената ѝ е в текста (а не в таблица), пак я улови.',
            '• КОНТАКТИ: телефон, имейл, адрес, локация, работно време — дословно както са на страницата.',
            '• РЕВЮТА/МНЕНИЯ: ако има клиентски отзиви на страницата, отбележи ги с автор и оценка.',
            '• КЛЮЧОВИ ФАКТИ: уникални предимства, технологии, гаранции.',
            'Пиши структурирано на български. БЕЗ измислици — ако нещо липсва, не го споменавай. Не измисляй цени или телефони, които ги няма в текста.',
            'Без увод и заключение — само извлечените данни.',
        ]);
    }

    /**
     * Summarize one page. Each chunk is summarized with a tight, low-temperature
     * call; if the page was split into several chunks, the partials are merged
     * into one. Uses the agent's own (small) model — never exceeds its context.
     *
     * @param  array<int, string>  $chunks
     */
    /**
     * Per-chunk Ollama timeout for map-reduce summarization (seconds).
     * Keeps individual LLM calls from hanging indefinitely on bad pages.
     * The Guzzle ->timeout() only covers connection setup in stream mode;
     * this value is forwarded as http_timeout and enforced inside OllamaService.
     */
    private const PAGE_SUMMARY_TIMEOUT_S = 150;

    /**
     * Expected worst-case seconds to summarize ONE page. When Ollama serializes a
     * concurrent wave, the per-request timeout is scaled by concurrency × this so
     * queued requests don't falsely time out.
     */
    private const PER_PAGE_BUDGET_S = 60;

    private function summarizePage(Agent $agent, string $url, array $chunks, int $maxTokens, ?string $mapModel = null, int $numCtx = 8192): string
    {
        $model        = $mapModel ?: $agent->model;
        $systemPrompt = $this->pageSummarySystemPrompt();

        $ollamaOptions = [
            'temperature'  => 0.2,
            'num_predict'  => $maxTokens,
            'num_ctx'      => $numCtx,
            'http_timeout' => self::PAGE_SUMMARY_TIMEOUT_S,
        ];

        $partials = [];
        foreach ($chunks as $idx => $chunk) {
            $part = $idx + 1;
            $total = count($chunks);
            $userMessage = "URL: {$url}\n\nСъдържание (част {$part}/{$total}):\n{$chunk}";
            try {
                $partial = trim($this->ollama->chat(
                    model: $model,
                    systemPrompt: $systemPrompt,
                    userMessage: $userMessage,
                    options: $ollamaOptions,
                ));
            } catch (\Exception $e) {
                // Timeout or connection error on this chunk — skip it, don't abort the page.
                $partial = '';
            }
            if ($partial !== '') {
                $partials[] = $partial;
            }
        }

        $partials = array_values(array_filter($partials, fn (string $p): bool => $p !== ''));
        if (count($partials) <= 1) {
            return $partials[0] ?? '';
        }

        // Reduce the per-chunk partials of THIS page into a single summary.
        try {
            return trim($this->ollama->chat(
                model: $model,
                systemPrompt: 'Обедини следните частични резюмета на ЕДНА И СЪЩА страница в едно кратко резюме,'
                    .' без повторения. На български. Само факти — без увод и заключение.',
                userMessage: implode("\n\n", $partials),
                options: $ollamaOptions,
            ));
        } catch (\Exception) {
            // Merge call timed out — return what we have joined.
            return implode("\n", $partials);
        }
    }

    /**
     * Split text into overlapping chunks. Returns the text unchanged in a single
     * element when it already fits within $size.
     *
     * @return array<int, string>
     */
    private function chunkText(string $text, int $size, int $overlap): array
    {
        $len = mb_strlen($text);
        if ($len <= $size) {
            return [$text];
        }

        $step   = max(1, $size - $overlap);
        $chunks = [];
        for ($start = 0; $start < $len; $start += $step) {
            $chunks[] = mb_substr($text, $start, $size);
        }

        return $chunks;
    }

    /**
     * Classify a URL by path into core / content / legal / other, matching the
     * priority taxonomy from the design spec (Bulgarian + transliterated paths).
     */
    private function classifyUrl(string $url): string
    {
        $path = strtolower(urldecode(parse_url($url, PHP_URL_PATH) ?? ''));

        if ($path === '' || $path === '/') {
            return 'core';
        }
        foreach (['/about', '/za-nas', '/услуги', '/uslugi', '/service', '/цени', '/ceni', '/tseni',
            '/price', '/pricing', '/contact', '/kontakti', '/контакти', '/team', '/product'] as $p) {
            if (str_contains($path, $p)) {
                return 'core';
            }
        }
        foreach (['/blog', '/news', '/article', '/novini', '/статии', '/case'] as $p) {
            if (str_contains($path, $p)) {
                return 'content';
            }
        }
        foreach (['privacy', 'terms', 'cookie', 'повери', 'условия', 'gdpr'] as $p) {
            if (str_contains($path, $p)) {
                return 'legal';
            }
        }

        return 'other';
    }

    /**
     * Append a line to the flow run's log file so map-reduce progress shows up in
     * the existing run-log viewer. Matches FlowExecutorService's "[H:i:s] msg" style.
     */
    private function runLog(AgentRun $agentRun, string $message): void
    {
        $flowRunId = $agentRun->flow_run_id ?? null;
        if (! $flowRunId) {
            return;
        }
        $file = storage_path("logs/run-{$flowRunId}.log");
        @file_put_contents($file, date('[H:i:s]')." {$message}\n", FILE_APPEND | LOCK_EX);
    }

    private function scrapeTopPricingPages(string $searchResults, int $maxPages): string
    {
        $domainMap = $this->extractDomainUrls($searchResults);
        $scraped = '';
        $count = 0;

        foreach ($domainMap as $domain => $knownUrl) {
            if ($count >= $maxPages) {
                break;
            }
            $pricingUrl = $this->findPricingUrl($domain, $knownUrl);
            if (! $pricingUrl) {
                continue;
            }
            $markdown = $this->useTool('scrape_page', ['url' => $pricingUrl]);
            if ($markdown && $markdown !== 'Scraping not available for this page.' && PricingSourceQuality::hasPricingEvidence($markdown)) {
                $scraped .= "\n\n=== SCRAPED PRICING PAGE: {$pricingUrl} ===\n{$markdown}";
                $count++;
            }
        }

        return $scraped;
    }

    /**
     * Extract unique domains → first URL from BraveSearchTool output.
     * BraveSearchTool format: "[N] Title: ...\n    URL: https://...\n    Summary: ..."
     */
    private function extractDomainUrls(string $searchResults): array
    {
        preg_match_all('/URL:\s*(https?:\/\/\S+)/i', $searchResults, $matches);
        $preferredDomains = [];
        $fallbackDomains = [];
        foreach ($matches[1] as $url) {
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $domain = strtolower(preg_replace('/^www\./i', '', $host));
            if (! $domain) {
                continue;
            }
            $domainMap = PricingSourceQuality::isLowValueDomain($domain)
                ? $fallbackDomains
                : $preferredDomains;

            // Prefer URLs that already point to a pricing page
            if (! isset($domainMap[$domain])) {
                $domainMap[$domain] = $url;
            } else {
                $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
                foreach (self::PRICING_KEYWORDS as $keyword) {
                    if (str_contains($path, mb_strtolower($keyword))) {
                        $domainMap[$domain] = $url;
                        break;
                    }
                }
            }

            if (PricingSourceQuality::isLowValueDomain($domain)) {
                $fallbackDomains = $domainMap;
            } else {
                $preferredDomains = $domainMap;
            }
        }

        return $preferredDomains + $fallbackDomains;
    }

    /**
     * Returns the best pricing page URL for a domain.
     * First checks if the known URL already points to a pricing page (keyword match).
     * Falls back to HEAD-checking a list of known pricing paths.
     */
    private function findPricingUrl(string $domain, string $knownUrl): ?string
    {
        $path = strtolower(parse_url($knownUrl, PHP_URL_PATH) ?? '');
        foreach (self::PRICING_KEYWORDS as $keyword) {
            if (str_contains($path, mb_strtolower($keyword))) {
                return $knownUrl;
            }
        }

        $baseUrl = 'https://'.$domain;
        foreach (self::PRICING_PATHS as $pricingPath) {
            try {
                $response = Http::timeout(3)->head($baseUrl.$pricingPath);
                if ($response->successful()) {
                    return $baseUrl.$pricingPath;
                }
            } catch (\Exception) {
                // Try next path
            }
        }

        return null;
    }

    private function generateSearchQueries(Agent $agent, string $input, array $context): array
    {
        $count = (int) ($agent->config['search_queries_count'] ?? 4);
        $topic = $context['target_url']
            ?? $context['url']
            ?? null;
        if (empty($topic)) {
            $topic = ! empty($context['flow_topic']) ? $context['flow_topic'] : mb_substr($input, 0, 300);
        }

        $systemPrompt = 'You are a search query specialist. Output ONLY a JSON array of strings. No explanation.';
        $userMessage = "Generate {$count} specific search queries to thoroughly research this topic from multiple angles.\n\nTopic: {$topic}\n\nRules:\n- Each query must target a SPECIFIC aspect, source type, or subtopic\n- Include the market/location if evident from the topic\n- Queries must be in the same language as the topic\n- Output ONLY valid JSON array, example: [\"query 1\",\"query 2\"]";

        $raw = $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.3]
        );

        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $queries = json_decode($m[0], true);
            if (is_array($queries) && count($queries) > 0) {
                return array_slice($queries, 0, $count);
            }
        }

        return [$topic];
    }
}
