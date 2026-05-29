# Brave Search Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate Brave Search API into the `researcher` agent type using an extensible `AgentTool` pattern, and update the flow generator to auto-place researcher first when web research is needed.

**Architecture:** New `AgentTool` interface + `BraveSearchTool` adapter + `BraveSearchService` HTTP client. `BaseAgent` gains a `$tools` array and `useTool()` helper. `ResearcherAgent` calls Brave before LLM and injects results into the system prompt.

**Tech Stack:** Laravel 12, PHP 8.x, PHPUnit 11, Mockery 1.6, Illuminate\Http (Laravel HTTP client)

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Create | `app/Agents/Tools/AgentTool.php` | Interface — name() + execute() |
| Create | `app/Services/BraveSearchService.php` | HTTP client to Brave API, retry logic |
| Create | `app/Agents/Tools/BraveSearchTool.php` | Adapts BraveSearchService → AgentTool |
| Modify | `app/Agents/BaseAgent.php` | Add `$tools` array + `useTool()` |
| Modify | `app/Agents/ResearcherAgent.php` | Call web_search tool, inject results |
| Modify | `app/Agents/AgentFactory.php` | Inject BraveSearchTool into researcher |
| Modify | `app/Services/AgentGeneratorService.php` | needsWebResearch() + ensureResearcherFirst() |
| Modify | `config/services.php` | Add brave.api_key + brave.results_count |
| Create | `tests/Unit/BraveSearchServiceTest.php` | Test retry logic and response parsing |
| Create | `tests/Unit/BraveSearchToolTest.php` | Test formatting of results |
| Create | `tests/Unit/ResearcherAgentTest.php` | Test tool injection into system prompt |
| Create | `tests/Unit/AgentGeneratorWebResearchTest.php` | Test keyword detection + reordering |

---

## Task 1: Config + AgentTool Interface

**Files:**
- Modify: `config/services.php`
- Create: `app/Agents/Tools/AgentTool.php`

- [ ] **Step 1: Add Brave config to services.php**

Open `config/services.php` and add after the `'ollama'` block:

```php
'brave' => [
    'api_key'       => env('BRAVE_SEARCH_API_KEY'),
    'results_count' => env('BRAVE_RESULTS_COUNT', 10),
],
```

- [ ] **Step 2: Create AgentTool interface**

Create `app/Agents/Tools/AgentTool.php`:

```php
<?php

namespace App\Agents\Tools;

interface AgentTool
{
    public function name(): string;

    /** @param array<string, mixed> $params */
    public function execute(array $params): string;
}
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php app/Agents/Tools/AgentTool.php
git commit -m "feat: add AgentTool interface and Brave Search config"
```

---

## Task 2: BraveSearchService

**Files:**
- Create: `app/Services/BraveSearchService.php`
- Create: `tests/Unit/BraveSearchServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/BraveSearchServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\BraveSearchService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BraveSearchServiceTest extends TestCase
{
    public function test_returns_parsed_results_on_success(): void
    {
        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        ['title' => 'Game News', 'url' => 'https://ign.com/1', 'description' => 'Latest gaming news', 'age' => '2 hours ago'],
                        ['title' => 'Xbox Update', 'url' => 'https://ign.com/2', 'description' => 'Xbox gets new update', 'age' => '1 day ago'],
                    ],
                ],
            ], 200),
        ]);

        $service = new BraveSearchService();
        $results = $service->search('video games');

        $this->assertCount(2, $results);
        $this->assertEquals('Game News', $results[0]['title']);
        $this->assertEquals('https://ign.com/1', $results[0]['url']);
    }

    public function test_retries_three_times_then_throws(): void
    {
        Http::fake([
            'api.search.brave.com/*' => Http::sequence()
                ->push([], 500)
                ->push([], 500)
                ->push([], 500),
        ]);

        $service = new BraveSearchService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed after 3 attempts/');

        $service->search('video games');
    }

    public function test_returns_empty_array_when_no_web_results(): void
    {
        Http::fake([
            'api.search.brave.com/*' => Http::response(['web' => []], 200),
        ]);

        $service = new BraveSearchService();
        $results = $service->search('video games');

        $this->assertSame([], $results);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude
php artisan test tests/Unit/BraveSearchServiceTest.php
```

Expected: FAIL — `BraveSearchService not found`

- [ ] **Step 3: Implement BraveSearchService**

Create `app/Services/BraveSearchService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BraveSearchService
{
    private const ENDPOINT     = 'https://api.search.brave.com/res/v1/web/search';
    private const MAX_ATTEMPTS = 3;
    private const RETRY_SLEEP  = 1;

    public function search(string $query, ?int $count = null): array
    {
        $apiKey = config('services.brave.api_key');
        $count  = $count ?? (int) config('services.brave.results_count', 10);

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Accept'               => 'application/json',
                    'Accept-Encoding'      => 'gzip',
                    'X-Subscription-Token' => $apiKey,
                ])->get(self::ENDPOINT, [
                    'q'     => $query,
                    'count' => $count,
                ]);

                if ($response->successful()) {
                    return $response->json('web.results') ?? [];
                }

                throw new \RuntimeException(
                    "Brave Search API error: HTTP {$response->status()}"
                );

            } catch (\RuntimeException $e) {
                $lastException = $e;
                Log::warning("[BraveSearch] Attempt {$attempt} failed: " . $e->getMessage());
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep(self::RETRY_SLEEP);
                }
            }
        }

        throw new \RuntimeException(
            'Brave Search failed after ' . self::MAX_ATTEMPTS . ' attempts: ' . $lastException->getMessage(),
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/BraveSearchServiceTest.php
```

Expected: 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BraveSearchService.php tests/Unit/BraveSearchServiceTest.php
git commit -m "feat: add BraveSearchService with retry logic"
```

---

## Task 3: BraveSearchTool

**Files:**
- Create: `app/Agents/Tools/BraveSearchTool.php`
- Create: `tests/Unit/BraveSearchToolTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/BraveSearchToolTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Agents\Tools\BraveSearchTool;
use App\Services\BraveSearchService;
use Tests\TestCase;

class BraveSearchToolTest extends TestCase
{
    public function test_name_returns_web_search(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $tool    = new BraveSearchTool($service);

        $this->assertSame('web_search', $tool->name());
    }

    public function test_formats_results_with_all_fields(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('video games')
            ->andReturn([
                ['title' => 'IGN Review', 'url' => 'https://ign.com', 'description' => 'Top games of 2025', 'age' => '3 hours ago'],
            ]);

        $tool   = new BraveSearchTool($service);
        $output = $tool->execute(['query' => 'video games']);

        $this->assertStringContainsString('[1] Title: IGN Review', $output);
        $this->assertStringContainsString('URL: https://ign.com', $output);
        $this->assertStringContainsString('Date: 3 hours ago', $output);
        $this->assertStringContainsString('Summary: Top games of 2025', $output);
    }

    public function test_omits_date_line_when_age_missing(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $service->shouldReceive('search')->andReturn([
            ['title' => 'Title', 'url' => 'https://x.com', 'description' => 'Desc'],
        ]);

        $tool   = new BraveSearchTool($service);
        $output = $tool->execute(['query' => 'test']);

        $this->assertStringNotContainsString('Date:', $output);
    }

    public function test_returns_no_results_message_when_empty(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $service->shouldReceive('search')->andReturn([]);

        $tool   = new BraveSearchTool($service);
        $output = $tool->execute(['query' => 'test']);

        $this->assertSame('No web search results found.', $output);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/BraveSearchToolTest.php
```

Expected: FAIL — `BraveSearchTool not found`

- [ ] **Step 3: Implement BraveSearchTool**

Create `app/Agents/Tools/BraveSearchTool.php`:

```php
<?php

namespace App\Agents\Tools;

use App\Services\BraveSearchService;

class BraveSearchTool implements AgentTool
{
    public function __construct(private BraveSearchService $service) {}

    public function name(): string
    {
        return 'web_search';
    }

    public function execute(array $params): string
    {
        $query   = $params['query'] ?? '';
        $results = $this->service->search($query);

        if (empty($results)) {
            return 'No web search results found.';
        }

        $lines = [];
        foreach ($results as $i => $result) {
            $num     = $i + 1;
            $title   = $result['title'] ?? 'No title';
            $url     = $result['url'] ?? '';
            $summary = $result['description'] ?? 'No description';
            $age     = $result['age'] ?? null;

            $entry = "[{$num}] Title: {$title}\n";
            $entry .= "    URL: {$url}\n";
            if ($age) {
                $entry .= "    Date: {$age}\n";
            }
            $entry .= "    Summary: {$summary}";

            $lines[] = $entry;
        }

        return implode("\n\n", $lines);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/BraveSearchToolTest.php
```

Expected: 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Agents/Tools/BraveSearchTool.php tests/Unit/BraveSearchToolTest.php
git commit -m "feat: add BraveSearchTool implementing AgentTool interface"
```

---

## Task 4: BaseAgent — tools support

**Files:**
- Modify: `app/Agents/BaseAgent.php`

- [ ] **Step 1: Update BaseAgent constructor and add useTool()**

In `app/Agents/BaseAgent.php`, replace the constructor and add `useTool()`:

```php
<?php

namespace App\Agents;

use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;

abstract class BaseAgent
{
    /** @param AgentTool[] $tools */
    public function __construct(
        protected OllamaService $ollama,
        protected array $tools = []
    ) {}

    abstract public function run(Agent $agent, AgentRun $agentRun, array $context): string;

    protected function useTool(string $name, array $params): ?string
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool->execute($params);
            }
        }
        return null;
    }

    protected function buildPrompt(Agent $agent, array $context): string
    {
        $prompt = $agent->prompt_template ?? '';

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
            }
        }

        return $prompt;
    }

    protected function chat(Agent $agent, string $userMessage, string $extraSystemContext = ''): string
    {
        $systemPrompt = $agent->role . $extraSystemContext . $this->buildOutputInstructions($agent);

        return $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: $this->buildOptions($agent)
        );
    }

    protected function buildOutputInstructions(Agent $agent): string
    {
        $lines = [];

        $langMap = [
            'bg' => 'Bulgarian', 'en' => 'English', 'de' => 'German',
            'fr' => 'French',    'es' => 'Spanish', 'ru' => 'Russian',
        ];
        $lang = $langMap[$agent->output_language ?? 'bg'] ?? ($agent->output_language ?? 'Bulgarian');
        $lines[] = "Language: Always respond in {$lang}.";

        if (!empty($agent->output_tone)) {
            $lines[] = "Tone: Use a " . strtolower($agent->output_tone) . " tone.";
        }
        if (!empty($agent->output_style)) {
            $lines[] = "Style: Write in a " . strtolower($agent->output_style) . " style.";
        }
        if (!empty($agent->output_format)) {
            $lines[] = "Format: Structure your response as a " . strtolower($agent->output_format) . ".";
        }

        return "\n\n---\nOUTPUT REQUIREMENTS:\n" . implode("\n", $lines);
    }

    protected function buildOptions(Agent $agent): array
    {
        $config  = $agent->config ?? [];
        $options = [];

        foreach (['temperature', 'top_p', 'top_k', 'repeat_penalty', 'num_predict'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '' && $config[$key] !== null) {
                $options[$key] = is_numeric($config[$key]) ? (float) $config[$key] : $config[$key];
            }
        }

        return $options;
    }
}
```

Note: `chat()` gains an optional `$extraSystemContext` parameter so `ResearcherAgent` can inject search results without duplicating the chat logic.

- [ ] **Step 2: Verify existing tests still pass**

```bash
php artisan test
```

Expected: All existing tests PASS

- [ ] **Step 3: Commit**

```bash
git add app/Agents/BaseAgent.php
git commit -m "feat: add tools array and useTool() helper to BaseAgent"
```

---

## Task 5: ResearcherAgent — web search integration

**Files:**
- Modify: `app/Agents/ResearcherAgent.php`
- Create: `tests/Unit/ResearcherAgentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ResearcherAgentTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Agents\ResearcherAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Tests\TestCase;

class ResearcherAgentTest extends TestCase
{
    private function makeAgent(string $topic = ''): Agent
    {
        $agent                  = new Agent();
        $agent->role            = 'Researcher role';
        $agent->model           = 'gemma2:9b';
        $agent->output_language = 'bg';
        $agent->config          = ['temperature' => 0.3];
        return $agent;
    }

    private function makeAgentRun(string $input): AgentRun
    {
        $run        = new AgentRun();
        $run->input = $input;
        return $run;
    }

    public function test_injects_search_results_into_system_prompt(): void
    {
        $searchResults = "[1] Title: IGN\n    URL: https://ign.com\n    Summary: Gaming news";

        $webSearchTool = \Mockery::mock(AgentTool::class);
        $webSearchTool->shouldReceive('name')->andReturn('web_search');
        $webSearchTool->shouldReceive('execute')
            ->once()
            ->with(['query' => 'video games'])
            ->andReturn($searchResults);

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt, $userMessage, $options) use ($searchResults) {
                return str_contains($systemPrompt, 'WEB SEARCH RESULTS')
                    && str_contains($systemPrompt, $searchResults);
            })
            ->andReturn('Research output');

        $researcher = new ResearcherAgent($ollama, [$webSearchTool]);
        $result     = $researcher->run(
            $this->makeAgent(),
            $this->makeAgentRun('Изследвай игровите тенденции свързани с video games'),
            ['topic' => 'video games']
        );

        $this->assertSame('Research output', $result);
    }

    public function test_uses_input_as_query_when_topic_is_empty(): void
    {
        $webSearchTool = \Mockery::mock(AgentTool::class);
        $webSearchTool->shouldReceive('name')->andReturn('web_search');
        $webSearchTool->shouldReceive('execute')
            ->once()
            ->withArgs(fn($params) => str_starts_with($params['query'], 'Изследвай'))
            ->andReturn('some results');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('output');

        $researcher = new ResearcherAgent($ollama, [$webSearchTool]);
        $researcher->run(
            $this->makeAgent(),
            $this->makeAgentRun('Изследвай игровите тенденции'),
            ['topic' => '']
        );
    }

    public function test_continues_without_search_when_no_tool_registered(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt) {
                return ! str_contains($systemPrompt, 'WEB SEARCH RESULTS');
            })
            ->andReturn('output');

        $researcher = new ResearcherAgent($ollama, []);
        $result     = $researcher->run(
            $this->makeAgent(),
            $this->makeAgentRun('some input'),
            ['topic' => 'video games']
        );

        $this->assertSame('output', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/ResearcherAgentTest.php
```

Expected: FAIL — `ResearcherAgent::run()` does not inject search results

- [ ] **Step 3: Implement ResearcherAgent**

Replace `app/Agents/ResearcherAgent.php`:

```php
<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class ResearcherAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $query         = !empty($context['topic']) ? $context['topic'] : mb_substr($agentRun->input, 0, 200);
        $searchResults = $this->useTool('web_search', ['query' => $query]);

        $extraContext = '';
        if ($searchResults !== null) {
            $extraContext = "\n\n--- WEB SEARCH RESULTS (use these as your primary source) ---\n" . $searchResults;
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/ResearcherAgentTest.php
```

Expected: 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Agents/ResearcherAgent.php tests/Unit/ResearcherAgentTest.php
git commit -m "feat: ResearcherAgent fetches Brave Search results before LLM call"
```

---

## Task 6: AgentFactory — inject BraveSearchTool

**Files:**
- Modify: `app/Agents/AgentFactory.php`

- [ ] **Step 1: Update AgentFactory**

Replace `app/Agents/AgentFactory.php`:

```php
<?php

namespace App\Agents;

use App\Agents\Tools\BraveSearchTool;
use App\Models\Agent;
use App\Services\BraveSearchService;
use App\Services\ComfyUIService;
use App\Services\OllamaService;

class AgentFactory
{
    public function __construct(
        private OllamaService $ollama,
        private ComfyUIService $comfyui,
        private BraveSearchService $braveSearch,
    ) {}

    public function make(Agent $agent): BaseAgent
    {
        return match ($agent->type) {
            'image_prompt'  => new ImagePromptAgent($this->ollama, $this->comfyui),
            'qa_verifier'   => new QaVerifierAgent($this->ollama),
            'analyzer'      => new AnalyzerAgent($this->ollama),
            'researcher'    => new ResearcherAgent($this->ollama, [new BraveSearchTool($this->braveSearch)]),
            'summarizer'    => new SummarizerAgent($this->ollama),
            'decision'      => new DecisionAgent($this->ollama),
            'publisher'     => new PublisherAgent($this->ollama),
            'translator'    => new TranslatorAgent($this->ollama),
            'orchestrator'  => new OrchestratorAgent($this->ollama),
            default         => new ContentAgent($this->ollama),
        };
    }
}
```

- [ ] **Step 2: Run all tests**

```bash
php artisan test
```

Expected: All tests PASS

- [ ] **Step 3: Commit**

```bash
git add app/Agents/AgentFactory.php
git commit -m "feat: inject BraveSearchTool into ResearcherAgent via AgentFactory"
```

---

## Task 7: AgentGeneratorService — researcher-first logic

**Files:**
- Modify: `app/Services/AgentGeneratorService.php`
- Create: `tests/Unit/AgentGeneratorWebResearchTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AgentGeneratorWebResearchTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\AgentGeneratorService;
use App\Services\ModelSelectorService;
use App\Services\OllamaService;
use Tests\TestCase;

class AgentGeneratorWebResearchTest extends TestCase
{
    private function makeService(string $llmResponse = 'NO'): AgentGeneratorService
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn($llmResponse);

        $selector = \Mockery::mock(ModelSelectorService::class);

        return new AgentGeneratorService($ollama, $selector);
    }

    public function test_keyword_новини_triggers_web_research(): void
    {
        $service = $this->makeService();

        $result = $this->invokeNeedsWebResearch($service, 'Този flow преглежда актуални новини от игровия пазар.');

        $this->assertTrue($result);
    }

    public function test_keyword_web_triggers_web_research(): void
    {
        $service = $this->makeService();

        $result = $this->invokeNeedsWebResearch($service, 'Web scraping of gaming sites for updates.');

        $this->assertTrue($result);
    }

    public function test_no_keywords_falls_back_to_llm_yes(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('YES');
        $selector = \Mockery::mock(ModelSelectorService::class);
        $service  = new AgentGeneratorService($ollama, $selector);

        $result = $this->invokeNeedsWebResearch($service, 'Анализирай продажбените данни от миналия месец.');

        $this->assertTrue($result);
    }

    public function test_no_keywords_falls_back_to_llm_no(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('NO');
        $selector = \Mockery::mock(ModelSelectorService::class);
        $service  = new AgentGeneratorService($ollama, $selector);

        $result = $this->invokeNeedsWebResearch($service, 'Анализирай продажбените данни от миналия месец.');

        $this->assertFalse($result);
    }

    public function test_researcher_moved_to_first_position(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 1],
            ['name' => 'Researcher', 'type' => 'researcher', 'order' => 2],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 3],
        ];

        $result = $this->invokeEnsureResearcherFirst($service, $agents);

        $this->assertSame('researcher', $result[0]['type']);
        $this->assertSame(1, $result[0]['order']);
        $this->assertSame(2, $result[1]['order']);
        $this->assertSame(3, $result[2]['order']);
    }

    public function test_researcher_already_first_unchanged(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Researcher', 'type' => 'researcher', 'order' => 1],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 2],
        ];

        $result = $this->invokeEnsureResearcherFirst($service, $agents);

        $this->assertSame('researcher', $result[0]['type']);
        $this->assertSame(1, $result[0]['order']);
    }

    public function test_no_researcher_returns_agents_unchanged(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 1],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 2],
        ];

        $result = $this->invokeEnsureResearcherFirst($service, $agents);

        $this->assertSame('analyzer', $result[0]['type']);
    }

    private function invokeNeedsWebResearch(AgentGeneratorService $service, string $description): bool
    {
        $method = new \ReflectionMethod($service, 'needsWebResearch');
        $method->setAccessible(true);
        return $method->invoke($service, $description);
    }

    private function invokeEnsureResearcherFirst(AgentGeneratorService $service, array $agents): array
    {
        $method = new \ReflectionMethod($service, 'ensureResearcherFirst');
        $method->setAccessible(true);
        return $method->invoke($service, $agents);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/AgentGeneratorWebResearchTest.php
```

Expected: FAIL — methods `needsWebResearch` and `ensureResearcherFirst` do not exist

- [ ] **Step 3: Add needsWebResearch() and ensureResearcherFirst() to AgentGeneratorService**

In `app/Services/AgentGeneratorService.php`:

1. After the `if (count($agents) < 3)` block in `generate()`, add the researcher-first call:

```php
        // Safety net: if AI returned fewer than 3, something went wrong
        if (count($agents) < 3) {
            Log::warning('[AgentGenerator] Too few agents (' . count($agents) . '), returning empty to trigger retry');
            return [];
        }

        if ($this->needsWebResearch($flow->description ?? '')) {
            $agents = $this->ensureResearcherFirst($agents);
        }

        return $agents;
```

2. Also update the `$userMessage` in `generate()` — replace the existing `ПРАВИЛА ЗА ПРОЕКТИРАНЕ НА PIPELINE` line with the researcher-aware version. Find this line:

```
- За social media flows: researcher → content → hashtag → image_prompt → caption_writer → qa_verifier
```

And replace it with:

```
- За social media flows: researcher → content → hashtag → image_prompt → caption_writer → qa_verifier
- АКО flow-ът изисква актуални новини/web данни: researcher ЗАДЪЛЖИТЕЛНО е на позиция 1 (order: 1)
```

3. Add the two private methods at the end of the class (before the closing `}`):

```php
    private function needsWebResearch(string $description): bool
    {
        $keywords = ['новини', 'актуални', 'онлайн', 'web', 'search', 'изследвай', 'сайтове', 'интернет', 'trends', 'scrape'];

        foreach ($keywords as $keyword) {
            if (mb_stripos($description, $keyword) !== false) {
                return true;
            }
        }

        $response = $this->ollama->chat(
            model: config('services.ollama.generator_model', 'mistral-nemo'),
            systemPrompt: 'Answer only YES or NO. No other text.',
            userMessage: "Does this flow description require fetching real-time web data or current news?\n\n{$description}",
            options: ['temperature' => 0.0, 'num_predict' => 5]
        );

        return str_starts_with(strtoupper(trim($response)), 'YES');
    }

    private function ensureResearcherFirst(array $agents): array
    {
        $researcherIndex = null;
        foreach ($agents as $i => $agent) {
            if (($agent['type'] ?? '') === 'researcher') {
                $researcherIndex = $i;
                break;
            }
        }

        if ($researcherIndex === null || $researcherIndex === 0) {
            return $agents;
        }

        $researcher = array_splice($agents, $researcherIndex, 1)[0];
        array_unshift($agents, $researcher);

        foreach ($agents as $i => &$agent) {
            $agent['order'] = $i + 1;
        }
        unset($agent);

        return $agents;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/AgentGeneratorWebResearchTest.php
```

Expected: 6 tests PASS

- [ ] **Step 5: Run full test suite**

```bash
php artisan test
```

Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/AgentGeneratorService.php tests/Unit/AgentGeneratorWebResearchTest.php
git commit -m "feat: auto-place researcher first in flows that need web research"
```

---

## Task 8: Smoke test with real run

- [ ] **Step 1: Verify config is picked up**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude
php artisan tinker --execute="echo config('services.brave.api_key') ? 'KEY SET' : 'KEY MISSING';"
```

Expected: `KEY SET`

- [ ] **Step 2: Trigger a new run of flow #3 and check the log**

```bash
php artisan tinker --execute="
\$flow = \App\Models\Flow::find(3);
\$svc = app(\App\Services\FlowExecutorService::class);
\$run = \$svc->run(\$flow, 'manual');
echo 'Run #' . \$run->id . ' — ' . \$run->status;
"
```

Then tail the log:

```bash
tail -100 storage/logs/run-$(php artisan tinker --execute="echo \App\Models\FlowRun::latest()->first()->id;").log
```

Expected: Researcher agent step shows web search results in input context, not hallucinated training data.

- [ ] **Step 3: Final commit if anything was tweaked**

```bash
git add -p
git commit -m "fix: post smoke-test adjustments"
```
