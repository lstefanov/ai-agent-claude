# Deep Web Scraper — Design Spec

**Date:** 2026-05-30
**Goal:** Upgrade research quality by scraping full competitor pricing pages instead of relying only on Brave Search snippets. Enables competitor → service → exact price extraction from complete pricing menus.

---

## Problem

Brave Search returns snippets (~200 chars per result). For competitor pricing flows, this is insufficient — a fitness club's `/цени` page may list 20+ tariffs that don't appear in any snippet. The result is vague pricing data (e.g., "цените варират 15–20 лв.") instead of specific competitor tables.

---

## Architecture

```
DeepResearcherAgent (PHP, extends BaseAgent)
  │
  ├── Phase 1: BraveSearch (existing BraveSearchTool × N queries)
  │     └── 50 results: title, URL, snippet
  │
  ├── Phase 2: SmartPricingPageFinder (new, internal)
  │     ├── extracts unique domains from search URLs
  │     ├── checks if any Brave URL already points to /цени page
  │     └── for other domains: HEAD-checks /цени, /ceni, /tseni, /membership,
  │         /абонамент, /prices, /plans, /karти, /tarifi (first 200 OK wins)
  │
  ├── Phase 3: WebScraperTool (new PHP AgentTool)
  │     └── calls CrawlService::scrape(url)
  │           └── POST http://localhost:8189/scrape
  │                 └── Python: Crawl4AI (Playwright-backed, JS rendering)
  │                       └── returns clean markdown of the pricing page
  │
  └── Phase 4: LLM synthesis
        input: BraveSearch snippets + N full pricing page markdowns
        → richer, more specific output with complete price menus
```

---

## New Files

| File | Type | Responsibility |
|------|------|----------------|
| `scripts/crawl_service.py` | Python | FastAPI wrapper around Crawl4AI. Endpoints: `POST /scrape`, `GET /health`. Port 8189. |
| `app/Services/CrawlService.php` | PHP Service | HTTP client for the Python service. Methods: `scrape(url): ?string`, `isAvailable(): bool`. |
| `app/Agents/Tools/WebScraperTool.php` | PHP AgentTool | Implements `AgentTool` interface. `name() = 'scrape_page'`. Calls `CrawlService::scrape()`. Returns markdown or `null`. |
| `app/Agents/DeepResearcherAgent.php` | PHP Agent | Extends `BaseAgent`. Runs BraveSearch + pricing page discovery + scraping + LLM synthesis. |

### Modified Files

| File | Change |
|------|--------|
| `app/Agents/AgentFactory.php` | Add `'deep_researcher' => DeepResearcherAgent` |
| `app/Services/BraveSearchService.php` | No changes |
| `config/services.php` | Add `crawl` service config block |
| `.env.example` | Add `CRAWL_SERVICE_URL`, `CRAWL_SERVICE_ENABLED`, `CRAWL_MAX_PAGES` |

---

## Component Details

### Python Service: `scripts/crawl_service.py`

```python
# Endpoints:
POST /scrape
  Request:  { "url": "https://nextlevelclub.bg/tseni" }
  Response: { "url": "...", "markdown": "...", "success": true, "status_code": 200 }
  Timeout:  15s default (configurable via CRAWL_TIMEOUT env var)

GET /health
  Response: { "status": "ok", "crawl4ai": "ready" }
```

Uses `AsyncWebCrawler` from `crawl4ai` with `BrowserConfig(headless=True)` for JS rendering. Returns clean markdown via `CrawlResult.markdown`.

Dependencies: `crawl4ai`, `fastapi`, `uvicorn`

Start command (add to `scripts/start-services.sh`):
```bash
cd scripts && uvicorn crawl_service:app --host 0.0.0.0 --port 8189 &
```

### PHP Service: `app/Services/CrawlService.php`

```php
// config/services.php
'crawl' => [
    'url'       => env('CRAWL_SERVICE_URL', 'http://localhost:8189'),
    'enabled'   => env('CRAWL_SERVICE_ENABLED', true),
    'timeout'   => env('CRAWL_SERVICE_TIMEOUT', 15),
    'max_pages' => env('CRAWL_MAX_PAGES', 3),
]

// CrawlService methods:
scrape(string $url): ?string   // returns markdown or null on failure
isAvailable(): bool             // health check
```

Wraps `Http::timeout(15)->post(...)`. Returns `null` on any error (timeout, 4xx, 5xx, service down) — never throws.

### PHP Tool: `app/Agents/Tools/WebScraperTool.php`

```php
interface: AgentTool
name(): 'scrape_page'
execute(['url' => 'https://...']): string  // markdown or 'Scraping not available.'
```

### PHP Agent: `app/Agents/DeepResearcherAgent.php`

```php
Agent config JSON fields:
  search_queries: string[]   // optional: explicit search queries (same as MultiResearcherAgent)
  search_queries_count: int  // default 4
  scrape_pricing_pages: bool // default true
  max_pages_to_scrape: int   // default: value from CRAWL_MAX_PAGES env (3); agent config overrides env
```

**Execution flow:**
1. Run BraveSearch queries (same logic as `MultiResearcherAgent`)
2. If `scrape_pricing_pages=true` and `CrawlService::isAvailable()`:
   a. Extract unique domains from all search result URLs (regex, deduplicated)
   b. For each domain (up to `max_pages_to_scrape`):
      - Check if any Brave URL for that domain contains a pricing path
        (case-insensitive match against: цен, cen, price, member, абон, abon, plan, kart, tarif)
      - If yes: use that URL directly (no HEAD check needed)
      - If no: HEAD-check known pricing paths list, use first 200 OK
   c. Scrape the pricing page, append markdown to context
3. Synthesize with LLM — instruction includes: "For each competitor where full page content is provided below, extract ALL prices listed. For competitors with only search snippets, use the snippet data."

**Pricing paths to try (in order):**
`/цени`, `/ceni`, `/tseni`, `/tseni.html`, `/prices`, `/membership`, `/memberships`, `/абонамент`, `/abonamant`, `/abonament`, `/plans`, `/карти`, `/karti`, `/tarifi`, `/tariffs`, `/subscribe`

---

## Error Handling

| Failure | Behavior |
|---------|----------|
| CrawlService not running | `isAvailable()=false` → skip scraping, use search-only mode |
| Site blocks scraping (403/429) | Returns null → skip, continue with other domains |
| Timeout (>15s) | Returns null → skip, continue |
| JS rendering fails | Crawl4AI returns empty markdown → skip |
| All scraping fails | Agent falls back to BraveSearch snippets (same as MultiResearcherAgent) |

**Invariant:** DeepResearcherAgent must produce output even if all scraping fails.

---

## Registration in Flow 4

After implementation, Flow 4's Agent 1 changes:
- `type`: `multi_researcher` → `deep_researcher`
- `config.scrape_pricing_pages`: `true`
- `config.max_pages_to_scrape`: `3`

(Via seeder or UI update)

---

## Testing

1. **Unit: CrawlService** — mock HTTP responses (200 with markdown, 404, timeout)
2. **Unit: DeepResearcherAgent** — mock BraveSearchTool and WebScraperTool; verify output includes scraped content when scraper returns data; verify graceful fallback when scraper returns null
3. **Integration: crawl_service.py** — start service, `curl -X POST /scrape -d '{"url":"https://example.com"}'`, verify markdown returned
4. **End-to-end: Flow 4 Run** — run with DeepResearcher, check log shows "Scraped: X pages", verify Extractor table has more rows than previous run

---

## Installation Steps

```bash
# 1. Install Python dependencies
pip install crawl4ai fastapi uvicorn

# 2. Install Playwright browsers (required by Crawl4AI for JS rendering)
playwright install chromium

# 3. Start the service
cd /Users/lub/Sites/localhost/ai-agent-claude/scripts
uvicorn crawl_service:app --host 0.0.0.0 --port 8189

# 4. Verify
curl http://localhost:8189/health
# Expected: {"status":"ok","crawl4ai":"ready"}
```
