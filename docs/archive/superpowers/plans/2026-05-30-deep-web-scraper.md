# Deep Web Scraper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `DeepResearcherAgent` that runs Brave Search queries AND scrapes full competitor pricing pages via a Python Crawl4AI microservice, giving agents complete price menus instead of search snippets.

**Architecture:** Python FastAPI + Crawl4AI runs on port 8189 (same pattern as ComfyUI on 8188). A new `CrawlService` PHP class wraps it; `WebScraperTool` exposes it to agents; `DeepResearcherAgent` orchestrates search + smart pricing page discovery + scraping + LLM synthesis.

**Tech Stack:** Python 3.12, Crawl4AI (Playwright-backed), FastAPI, Uvicorn, PHP 8.2, Laravel 12, Illuminate\Http facade

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `scripts/crawl_service.py` | CREATE | FastAPI + Crawl4AI, port 8189, `/scrape` + `/health` |
| `app/Services/CrawlService.php` | CREATE | PHP HTTP client for the Python service |
| `app/Agents/Tools/WebScraperTool.php` | CREATE | AgentTool wrapper over CrawlService |
| `app/Agents/DeepResearcherAgent.php` | CREATE | Search + pricing page discovery + scrape + synthesize |
| `app/Agents/BaseAgent.php` | MODIFY | Add `hasTool(string $name): bool` helper |
| `app/Agents/AgentFactory.php` | MODIFY | Register `deep_researcher` type |
| `config/services.php` | MODIFY | Add `crawl` service config block |
| `.env.example` | MODIFY | Add `CRAWL_SERVICE_URL`, `CRAWL_SERVICE_ENABLED`, `CRAWL_MAX_PAGES` |
| `tests/Unit/CrawlServiceTest.php` | CREATE | Unit tests for CrawlService |
| `tests/Unit/DeepResearcherAgentTest.php` | CREATE | Unit tests for DeepResearcherAgent |

---

## Task 1: Python Crawl4AI Microservice

**Files:**
- Create: `scripts/crawl_service.py`

- [ ] **Step 1: Install Python dependencies**

```bash
pip install "crawl4ai>=0.5.0" fastapi uvicorn
playwright install chromium
```

Expected: No errors. Verify with:
```bash
python3 -c "import crawl4ai, fastapi, uvicorn; print('OK')"
```

- [ ] **Step 2: Create the service**

Create `/Users/lub/Sites/localhost/ai-agent-claude/scripts/crawl_service.py`:

```python
import asyncio
import os
from fastapi import FastAPI
from pydantic import BaseModel

app = FastAPI()


class ScrapeRequest(BaseModel):
    url: str
    timeout: int = 15


@app.post("/scrape")
async def scrape(request: ScrapeRequest):
    from crawl4ai import AsyncWebCrawler

    try:
        async with AsyncWebCrawler(headless=True, verbose=False) as crawler:
            result = await asyncio.wait_for(
                crawler.arun(url=request.url),
                timeout=request.timeout,
            )
            return {
                "url": request.url,
                "markdown": result.markdown or "",
                "success": bool(result.success),
                "status_code": getattr(result, "status_code", 200) or 200,
            }
    except asyncio.TimeoutError:
        return {"url": request.url, "markdown": "", "success": False, "status_code": 408}
    except Exception as exc:
        return {"url": request.url, "markdown": "", "success": False, "status_code": 500}


@app.get("/health")
async def health():
    return {"status": "ok", "crawl4ai": "ready"}


if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("CRAWL_PORT", 8189))
    uvicorn.run(app, host="0.0.0.0", port=port)
```

- [ ] **Step 3: Start the service and verify**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude/scripts
uvicorn crawl_service:app --host 0.0.0.0 --port 8189 &
sleep 3
curl -s http://localhost:8189/health
```

Expected: `{"status":"ok","crawl4ai":"ready"}`

- [ ] **Step 4: Test a real scrape**

```bash
curl -s -X POST http://localhost:8189/scrape \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com","timeout":10}' | python3 -m json.tool
```

Expected: JSON with `"success": true` and `"markdown"` containing "Example Domain".

- [ ] **Step 5: Stop background service (will be started properly later)**

```bash
pkill -f "uvicorn crawl_service"
```

- [ ] **Step 6: Commit**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude
git add scripts/crawl_service.py
git commit -m "feat: add Python Crawl4AI microservice for full-page scraping"
```

---

## Task 2: CrawlService PHP Client

**Files:**
- Create: `app/Services/CrawlService.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Create: `tests/Unit/CrawlServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/CrawlServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\CrawlService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrawlServiceTest extends TestCase
{
    public function test_scrape_returns_markdown_on_success(): void
    {
        Http::fake([
            'localhost:8189/scrape' => Http::response([
                'markdown'    => '# Ценоразпис',
                'success'     => true,
                'status_code' => 200,
            ], 200),
        ]);

        $service = new CrawlService();
        $result  = $service->scrape('https://example.com/prices');

        $this->assertSame('# Ценоразпис', $result);
    }

    public function test_scrape_returns_null_when_service_returns_error(): void
    {
        Http::fake([
            'localhost:8189/scrape' => Http::response([], 500),
        ]);

        $service = new CrawlService();
        $this->assertNull($service->scrape('https://example.com/prices'));
    }

    public function test_scrape_returns_null_when_markdown_is_empty(): void
    {
        Http::fake([
            'localhost:8189/scrape' => Http::response([
                'markdown' => '',
                'success'  => false,
            ], 200),
        ]);

        $service = new CrawlService();
        $this->assertNull($service->scrape('https://example.com/prices'));
    }

    public function test_scrape_returns_null_on_connection_exception(): void
    {
        Http::fake([
            'localhost:8189/scrape' => fn () => throw new \Exception('Connection refused'),
        ]);

        $service = new CrawlService();
        $this->assertNull($service->scrape('https://example.com/prices'));
    }

    public function test_is_available_returns_true_when_health_endpoint_responds(): void
    {
        Http::fake([
            'localhost:8189/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $service = new CrawlService();
        $this->assertTrue($service->isAvailable());
    }

    public function test_is_available_returns_false_on_connection_error(): void
    {
        Http::fake([
            'localhost:8189/health' => fn () => throw new \Exception('refused'),
        ]);

        $service = new CrawlService();
        $this->assertFalse($service->isAvailable());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude
php artisan test tests/Unit/CrawlServiceTest.php 2>&1 | tail -10
```

Expected: `FAIL` — "Class App\Services\CrawlService not found"

- [ ] **Step 3: Add config entries**

In `config/services.php`, add after the `'comfyui'` block:

```php
'crawl' => [
    'url'       => env('CRAWL_SERVICE_URL', 'http://localhost:8189'),
    'enabled'   => env('CRAWL_SERVICE_ENABLED', true),
    'timeout'   => env('CRAWL_SERVICE_TIMEOUT', 15),
    'max_pages' => env('CRAWL_MAX_PAGES', 3),
],
```

In `.env.example`, add:

```
CRAWL_SERVICE_URL=http://localhost:8189
CRAWL_SERVICE_ENABLED=true
CRAWL_SERVICE_TIMEOUT=15
CRAWL_MAX_PAGES=3
```

- [ ] **Step 4: Create the service**

Create `app/Services/CrawlService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CrawlService
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.crawl.url', 'http://localhost:8189');
        $this->timeout = (int) config('services.crawl.timeout', 15);
    }

    /**
     * Scrape a URL and return clean markdown. Returns null on any failure.
     */
    public function scrape(string $url): ?string
    {
        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/scrape", [
                'url'     => $url,
                'timeout' => $this->timeout,
            ]);

            if (! $response->successful()) {
                return null;
            }

            return $response->json('markdown') ?: null;
        } catch (\Exception) {
            return null;
        }
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->get("{$this->baseUrl}/health")->successful();
        } catch (\Exception) {
            return false;
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test tests/Unit/CrawlServiceTest.php
```

Expected: `6 passed`

- [ ] **Step 6: Commit**

```bash
git add app/Services/CrawlService.php config/services.php .env.example \
        tests/Unit/CrawlServiceTest.php
git commit -m "feat: add CrawlService PHP client for Crawl4AI microservice"
```

---

## Task 3: WebScraperTool

**Files:**
- Create: `app/Agents/Tools/WebScraperTool.php`

No separate test file — the tool is a thin wrapper; behavior is covered by integration in DeepResearcherAgentTest.

- [ ] **Step 1: Create the tool**

Create `app/Agents/Tools/WebScraperTool.php`:

```php
<?php

namespace App\Agents\Tools;

use App\Services\CrawlService;

class WebScraperTool implements AgentTool
{
    public function __construct(private CrawlService $service) {}

    public function name(): string
    {
        return 'scrape_page';
    }

    public function execute(array $params): string
    {
        $url    = $params['url'] ?? '';
        $result = $this->service->scrape($url);

        return $result ?? 'Scraping not available for this page.';
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l app/Agents/Tools/WebScraperTool.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Agents/Tools/WebScraperTool.php
git commit -m "feat: add WebScraperTool AgentTool wrapping CrawlService"
```

---

## Task 4: BaseAgent::hasTool() + DeepResearcherAgent

**Files:**
- Modify: `app/Agents/BaseAgent.php` (add `hasTool()`)
- Create: `app/Agents/DeepResearcherAgent.php`
- Create: `tests/Unit/DeepResearcherAgentTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/DeepResearcherAgentTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Agents\DeepResearcherAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepResearcherAgentTest extends TestCase
{
    private function makeAgent(array $config = []): Agent
    {
        $agent                  = new Agent();
        $agent->role            = 'Deep researcher';
        $agent->model           = 'gemma2:9b';
        $agent->output_language = 'bg';
        $agent->config          = array_merge(['search_queries' => ['фитнес цени']], $config);
        return $agent;
    }

    private function makeAgentRun(string $input = 'Research prices'): AgentRun
    {
        $run        = new AgentRun();
        $run->input = $input;
        return $run;
    }

    private function makeSearchTool(string $resultsToReturn): AgentTool
    {
        $tool = \Mockery::mock(AgentTool::class);
        $tool->shouldReceive('name')->andReturn('web_search');
        $tool->shouldReceive('execute')->andReturn($resultsToReturn);
        return $tool;
    }

    public function test_scrapes_pricing_url_detected_in_search_results(): void
    {
        // URL contains /tseni/ — matches pricing keyword, used directly (no HEAD check)
        $searchResults = "[1] Title: Next Level\n    URL: https://nextlevelclub.bg/tseni/\n    Summary: prices";

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://nextlevelclub.bg/tseni/'])
            ->andReturn('## Ценоразпис\n| Абонамент | 89 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('synthesis output');

        $agent = $this->makeAgent(['scrape_pricing_pages' => true, 'max_pages_to_scrape' => 1]);

        $deep   = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults), $scraperTool]);
        $result = $deep->run($agent, $this->makeAgentRun(), []);

        $this->assertSame('synthesis output', $result);
    }

    public function test_falls_back_gracefully_when_scraper_not_registered(): void
    {
        $searchResults = "[1] Title: Next Level\n    URL: https://nextlevelclub.bg/tseni/\n    Summary: prices";

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('search only output');

        // No scraper tool — only web_search
        $deep   = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults)]);
        $result = $deep->run($this->makeAgent(), $this->makeAgentRun(), []);

        $this->assertSame('search only output', $result);
    }

    public function test_skips_scraping_when_config_flag_is_false(): void
    {
        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldNotReceive('execute');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('output');

        $agent = $this->makeAgent(['scrape_pricing_pages' => false]);
        $deep  = new DeepResearcherAgent(
            $ollama,
            [$this->makeSearchTool('some results'), $scraperTool]
        );
        $deep->run($agent, $this->makeAgentRun(), []);
    }

    public function test_discovers_pricing_page_via_head_request_when_url_has_no_pricing_path(): void
    {
        // URL has no pricing keyword in path — triggers HEAD discovery
        $searchResults = "[1] Title: MAXIFIT\n    URL: https://maxifit.bg/about/\n    Summary: gym";

        Http::fake([
            'maxifit.bg/цени'      => Http::response('', 404),
            'maxifit.bg/ceni'      => Http::response('', 404),
            'maxifit.bg/tseni'     => Http::response('', 200), // found!
            '*'                    => Http::response('', 404),
        ]);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://maxifit.bg/tseni'])
            ->andReturn('# Prices\n| 89 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('result');

        $agent = $this->makeAgent(['scrape_pricing_pages' => true, 'max_pages_to_scrape' => 1]);
        $deep  = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults), $scraperTool]);
        $deep->run($agent, $this->makeAgentRun(), []);
    }

    public function test_deduplicates_domains_across_search_results(): void
    {
        // Two results from same domain — should only scrape once
        $searchResults  = "[1] Title: A\n    URL: https://nextlevelclub.bg/news/\n    Summary: s";
        $searchResults .= "\n\n[2] Title: B\n    URL: https://nextlevelclub.bg/tseni/\n    Summary: prices";

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once() // only once despite two results from same domain
            ->andReturn('# Prices');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('output');

        $agent = $this->makeAgent(['scrape_pricing_pages' => true, 'max_pages_to_scrape' => 5]);
        $deep  = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults), $scraperTool]);
        $deep->run($agent, $this->makeAgentRun(), []);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/DeepResearcherAgentTest.php 2>&1 | tail -5
```

Expected: `FAIL` — "Class App\Agents\DeepResearcherAgent not found"

- [ ] **Step 3: Add hasTool() to BaseAgent**

In `app/Agents/BaseAgent.php`, add after `useTool()`:

```php
protected function hasTool(string $name): bool
{
    return isset($this->tools[$name]);
}
```

- [ ] **Step 4: Create DeepResearcherAgent**

Create `app/Agents/DeepResearcherAgent.php`:

```php
<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Http;

class DeepResearcherAgent extends BaseAgent
{
    private const PRICING_KEYWORDS = [
        'цен', 'абон', 'карт',                         // Cyrillic
        'tseni', 'tsena', 'ceni', 'cena', 'abon',      // transliterated
        'price', 'pricing', 'member', 'plan', 'tarif', 'subscribe', 'kart',
    ];

    private const PRICING_PATHS = [
        '/цени', '/цена', '/абонамент', '/абонаменти', '/карти',
        '/ceni', '/tseni', '/tseni.html', '/abonamant', '/abonament',
        '/prices', '/pricing', '/membership', '/memberships',
        '/plans', '/plan', '/tarifi', '/tariffs', '/subscribe', '/karti',
    ];

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config = $agent->config ?? [];

        // Phase 1: BraveSearch
        $queryTemplates = $config['search_queries'] ?? [];
        if (empty($queryTemplates)) {
            $queryTemplates = $this->generateSearchQueries($agent, $agentRun->input, $context);
        } else {
            foreach ($queryTemplates as &$tpl) {
                foreach ($context as $key => $value) {
                    if (is_string($value)) {
                        $tpl = str_replace('{{' . $key . '}}', $value, $tpl);
                    }
                }
            }
        }

        $allResults = '';
        foreach ($queryTemplates as $i => $query) {
            $results = $this->useTool('web_search', ['query' => $query]);
            if ($results !== null) {
                $num        = $i + 1;
                $allResults .= "\n\n=== SEARCH {$num}: \"{$query}\" ===\n{$results}";
            }
        }

        // Phase 2: Scrape pricing pages (only if tool is registered and config allows)
        $scrapedContent = '';
        if ($allResults && ($config['scrape_pricing_pages'] ?? true) && $this->hasTool('scrape_page')) {
            $maxPages       = (int) ($config['max_pages_to_scrape'] ?? config('services.crawl.max_pages', 3));
            $scrapedContent = $this->scrapeTopPricingPages($allResults, $maxPages);
        }

        // Phase 3: Synthesize
        $extraContext = '';
        if ($allResults) {
            $extraContext .= "\n\n--- WEB SEARCH RESULTS (preserve EXACT competitor names, EXACT prices, EXACT service names — cite source URL next to every price) ---\n{$allResults}";
        }
        if ($scrapedContent) {
            $extraContext .= "\n\n--- FULL PRICING PAGE CONTENT (complete menus scraped directly from competitor websites) ---\n{$scrapedContent}";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    private function scrapeTopPricingPages(string $searchResults, int $maxPages): string
    {
        $domainMap = $this->extractDomainUrls($searchResults);
        $scraped   = '';
        $count     = 0;

        foreach ($domainMap as $domain => $knownUrl) {
            if ($count >= $maxPages) {
                break;
            }

            $pricingUrl = $this->findPricingUrl($domain, $knownUrl);
            if (! $pricingUrl) {
                continue;
            }

            $markdown = $this->useTool('scrape_page', ['url' => $pricingUrl]);
            if ($markdown && $markdown !== 'Scraping not available for this page.') {
                $scraped .= "\n\n=== SCRAPED PRICING PAGE: {$pricingUrl} ===\n{$markdown}";
                $count++;
            }
        }

        return $scraped;
    }

    /**
     * Extract unique domains with their first URL from BraveSearchTool output.
     * Returns [domain => first_url_for_that_domain].
     *
     * BraveSearchTool format:
     * [N] Title: ...
     *     URL: https://...
     */
    private function extractDomainUrls(string $searchResults): array
    {
        preg_match_all('/URL:\s*(https?:\/\/\S+)/i', $searchResults, $matches);

        $domainMap = [];
        foreach ($matches[1] as $url) {
            $host   = parse_url($url, PHP_URL_HOST) ?? '';
            $domain = strtolower(preg_replace('/^www\./i', '', $host));
            if ($domain && ! isset($domainMap[$domain])) {
                $domainMap[$domain] = $url;
            }
        }

        return $domainMap;
    }

    /**
     * Return the best pricing page URL for a domain.
     * First checks if knownUrl already points to a pricing page.
     * If not, tries known pricing paths via HEAD requests.
     */
    private function findPricingUrl(string $domain, string $knownUrl): ?string
    {
        $path = strtolower(parse_url($knownUrl, PHP_URL_PATH) ?? '');
        foreach (self::PRICING_KEYWORDS as $keyword) {
            if (str_contains($path, mb_strtolower($keyword))) {
                return $knownUrl;
            }
        }

        $baseUrl = 'https://' . $domain;
        foreach (self::PRICING_PATHS as $pricingPath) {
            try {
                $response = Http::timeout(3)->head($baseUrl . $pricingPath);
                if ($response->successful()) {
                    return $baseUrl . $pricingPath;
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
        $topic = ! empty($context['flow_topic']) ? $context['flow_topic'] : mb_substr($input, 0, 300);

        $systemPrompt = 'You are a search query specialist. Output ONLY a JSON array of strings. No explanation.';
        $userMessage  = "Generate {$count} specific search queries to thoroughly research this topic from multiple angles.\n\nTopic: {$topic}\n\nRules:\n- Each query must target a SPECIFIC aspect, source type, or subtopic\n- Include the market/location if evident from the topic\n- Queries must be in the same language as the topic\n- Output ONLY valid JSON array, example: [\"query 1\",\"query 2\"]";

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
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test tests/Unit/DeepResearcherAgentTest.php
```

Expected: `5 passed`

- [ ] **Step 6: Commit**

```bash
git add app/Agents/BaseAgent.php app/Agents/DeepResearcherAgent.php \
        tests/Unit/DeepResearcherAgentTest.php
git commit -m "feat: add DeepResearcherAgent with smart pricing page discovery and scraping"
```

---

## Task 5: Register in AgentFactory

**Files:**
- Modify: `app/Agents/AgentFactory.php`

- [ ] **Step 1: Add import and match arm**

In `app/Agents/AgentFactory.php`:

Add import after `use App\Agents\MultiResearcherAgent;`:
```php
use App\Agents\DeepResearcherAgent;
```

Add import after `use App\Agents\Tools\BraveSearchTool;`:
```php
use App\Agents\Tools\WebScraperTool;
use App\Services\CrawlService;
```

Add match arm after `'multi_researcher'`:
```php
'deep_researcher' => new DeepResearcherAgent($this->ollama, [
    new BraveSearchTool($this->braveSearch),
    new WebScraperTool(new CrawlService()),
]),
```

- [ ] **Step 2: Verify syntax**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan about 2>&1 | head -5
```

Expected: Application info printed, no PHP errors.

- [ ] **Step 3: Run full test suite**

```bash
php artisan test 2>&1 | tail -5
```

Expected: All existing tests still pass.

- [ ] **Step 4: Commit**

```bash
git add app/Agents/AgentFactory.php
git commit -m "feat: register deep_researcher agent type in AgentFactory"
```

---

## Task 6: Update Flow 4 to Use DeepResearcher

**Files:**
- Create: `database/seeders/UpgradeFlow4ToDeepResearcherSeeder.php`

- [ ] **Step 1: Create seeder**

Create `database/seeders/UpgradeFlow4ToDeepResearcherSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Seeder;

class UpgradeFlow4ToDeepResearcherSeeder extends Seeder
{
    public function run(): void
    {
        $researcher = Agent::where('flow_id', 4)
            ->where('type', 'multi_researcher')
            ->first();

        if (! $researcher) {
            $this->command->error('multi_researcher agent not found in Flow 4.');
            return;
        }

        $researcher->update([
            'type'   => 'deep_researcher',
            'config' => array_merge($researcher->config ?? [], [
                'scrape_pricing_pages' => true,
                'max_pages_to_scrape'  => 3,
            ]),
        ]);

        $this->command->info("Agent {$researcher->id} ({$researcher->name}) upgraded: multi_researcher → deep_researcher");
        $this->command->info("Config: scrape_pricing_pages=true, max_pages_to_scrape=3");
    }
}
```

- [ ] **Step 2: Run the seeder**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude
php artisan db:seed --class=UpgradeFlow4ToDeepResearcherSeeder
```

Expected:
```
Agent 21 (Изследовател на ценовите стратегии) upgraded: multi_researcher → deep_researcher
Config: scrape_pricing_pages=true, max_pages_to_scrape=3
```

- [ ] **Step 3: Verify in DB**

```bash
php artisan tinker --execute="print App\Models\Agent::find(21)->type . PHP_EOL;"
```

Expected: `deep_researcher`

- [ ] **Step 4: Commit**

```bash
git add database/seeders/UpgradeFlow4ToDeepResearcherSeeder.php
git commit -m "feat: upgrade Flow 4 researcher to deep_researcher with pricing page scraping"
```

---

## Task 7: Start Crawl Service and Run End-to-End Verification

- [ ] **Step 1: Start the Crawl4AI service**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude/scripts
uvicorn crawl_service:app --host 0.0.0.0 --port 8189 &
sleep 2
curl -s http://localhost:8189/health
```

Expected: `{"status":"ok","crawl4ai":"ready"}`

- [ ] **Step 2: Run the full test suite**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude
php artisan test
```

Expected: All tests pass (CrawlServiceTest uses Http::fake(), no real service needed for tests).

- [ ] **Step 3: Trigger Flow 4 run from UI**

Open https://flowai.local/flows/4 and trigger a new run.

- [ ] **Step 4: Verify logs show scraping activity**

```bash
# Replace XX with the new run ID
tail -f /Users/lub/Sites/localhost/ai-agent-claude/storage/logs/run-XX.log
```

Look for lines like:
```
=== SCRAPED PRICING PAGE: https://nextlevelclub.bg/tseni ===
```

The researcher's output should be significantly longer than Run 19 (4,497 chars) and contain complete pricing menus.

- [ ] **Step 5: Verify Extractor output has more rows**

The Extractor table in the new run should have more rows than in Run 19 (which had ~15 rows). A good result is 20+ rows with full competitor names in the Конкурент column.

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Python Crawl4AI service on port 8189 → Task 1
- ✅ CrawlService PHP client with `scrape()` and `isAvailable()` → Task 2
- ✅ WebScraperTool implementing AgentTool → Task 3
- ✅ DeepResearcherAgent: search + pricing URL detection + scrape + synthesize → Task 4
- ✅ hasTool() added to BaseAgent → Task 4, Step 3
- ✅ Register `deep_researcher` in AgentFactory → Task 5
- ✅ config/services.php + .env.example → Task 2
- ✅ Flow 4 upgrade to deep_researcher → Task 6
- ✅ Fallback behavior (scraper not registered / service down) → Task 4 tests
- ✅ Domain deduplication → Task 4 tests
- ✅ Pricing URL detection from search results (keyword match) → Task 4 tests
- ✅ HEAD-based discovery (no keyword in URL) → Task 4 tests

**Type consistency:**
- `CrawlService::scrape()` returns `?string` ✓ — used as `?string` in WebScraperTool
- `WebScraperTool::execute()` returns `string` ✓ — implements AgentTool contract
- `hasTool()` is `protected bool` ✓ — called as `$this->hasTool()` in DeepResearcherAgent
- `extractDomainUrls()` returns `array<string, string>` ✓ — iterated with `foreach ($domainMap as $domain => $knownUrl)`
