# Brave Search Integration — Design Spec

**Date:** 2026-05-29  
**Status:** Approved

## Overview

Integrate Brave Search API into the `researcher` agent type so it fetches real-time web data before passing results to the LLM. Introduce an extensible `AgentTool` pattern for future tools. Update the flow generator to automatically place researcher agents first when web research is detected.

---

## Architecture

### New files

```
app/Services/BraveSearchService.php
app/Agents/Tools/AgentTool.php          ← interface
app/Agents/Tools/BraveSearchTool.php
docs/superpowers/specs/2026-05-29-brave-search-integration-design.md
```

### Modified files

```
app/Agents/BaseAgent.php
app/Agents/ResearcherAgent.php
app/Agents/AgentFactory.php
app/Services/AgentGeneratorService.php
config/services.php
```

---

## Components

### `AgentTool` interface

```php
interface AgentTool {
    public function name(): string;
    public function execute(array $params): string;
}
```

Single responsibility: takes params, returns a formatted string ready for LLM consumption.

---

### `BraveSearchService`

- Method: `search(string $query, int $count = 10): array`
- Returns array of results, each with: `title`, `url`, `description`, `age`
- **Retry logic:** 3 attempts with 1s sleep between them — all retry logic lives here
- Throws `\RuntimeException` after 3 failed attempts (propagates up to FlowExecutorService → flow marked as failed)
- Config: `config('services.brave.api_key')`, `config('services.brave.results_count', 10)`
- Brave Search API endpoint: `https://api.search.brave.com/res/v1/web/search`

---

### `BraveSearchTool`

- Wraps `BraveSearchService`
- `name()` returns `'web_search'`
- `execute(['query' => '...'])` → calls `BraveSearchService::search()` → formats results:

```
[1] Title: ...
    URL: ...
    Date: ...
    Summary: ...

[2] Title: ...
    ...
```

- Exceptions from `BraveSearchService` propagate — no swallowing

---

### `BaseAgent` changes

Constructor gains optional `array $tools = []` parameter:

```php
public function __construct(
    protected OllamaService $ollama,
    protected array $tools = []
) {}
```

New protected helper:

```php
protected function useTool(string $name, array $params): ?string
```

Returns formatted string if tool found, `null` if no matching tool (graceful degradation for other agent types).

---

### `ResearcherAgent::run()`

1. Determine query: `$context['topic']` if non-empty, otherwise first 200 chars of `$agentRun->input`
2. Call `useTool('web_search', ['query' => $query])`
3. If results returned → append to system prompt:
   ```
   --- WEB SEARCH RESULTS (use these as your primary source) ---
   {$results}
   ```
4. If `useTool` returns `null` → proceed with LLM only (no crash)
5. Call `$this->chat()` with the enriched system prompt

---

### `AgentFactory::make()` change

```php
case 'researcher':
    $braveSearch = new BraveSearchTool(app(BraveSearchService::class));
    return new ResearcherAgent($this->ollama, [$braveSearch]);
```

All other agent types continue to receive `[]` — no behaviour change.

---

### `AgentGeneratorService` — `needsWebResearch(string $description): bool`

**Step 1 — Keyword check** (fast, no LLM):  
Searches description (case-insensitive) for: `новини`, `актуални`, `онлайн`, `web`, `search`, `изследвай`, `сайтове`, `интернет`, `trends`, `scrape`  
→ match found → `return true`

**Step 2 — LLM fallback** (only if no keyword match):  
Prompt: `"Does this flow description require fetching real-time web data or current news? Answer only YES or NO."`  
→ parse response → `return true/false`

**When `needsWebResearch() === true`:**
- If generated agents include a `researcher` type → move it to `order = 1`, renumber others
- If no `researcher` agent → prepend instruction to generator prompt to include one at position 1

---

## Config

`config/services.php` additions:

```php
'brave' => [
    'api_key'       => env('BRAVE_SEARCH_API_KEY'),
    'results_count' => env('BRAVE_RESULTS_COUNT', 10),
],
```

---

## Error Handling

| Scenario | Behaviour |
|---|---|
| Brave API returns error (4xx/5xx) | Retry up to 3 times (1s delay), then throw RuntimeException → flow fails |
| Rate limit (429) | Same as above — counted as a failed attempt |
| API key missing/invalid | Throws on first attempt, no retry |
| Tool not found in `useTool()` | Returns `null` → agent continues without web data |

---

## Out of Scope

- No UI changes for tool configuration
- No other agent types get tools in this spec
- No caching of Brave results
- No pagination (single Brave API call per agent run)
