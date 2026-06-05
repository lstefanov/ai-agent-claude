<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Flow;
use App\Services\AgentGeneratorService;
use App\Services\OllamaService;
use App\Support\UrlExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SiteAuditAgentGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_DESCRIPTION = 'Моят бизнес има web site на адрес: https://primelaser.bg. '
        . 'Искам да минеш през всяка страница на сайта в интернет и да дадеш пълен отчет за бизнеса, '
        . 'услуги и цени. После провери за ревюта и дай съвети.';

    public function test_url_extractor_finds_the_target_site(): void
    {
        $this->assertSame('https://primelaser.bg', UrlExtractor::first(self::SITE_DESCRIPTION));
        $this->assertSame([], UrlExtractor::all('Няма URL тук, само текст.'));
    }

    public function test_site_audit_flow_builds_deterministic_branched_pipeline(): void
    {
        $flow = $this->makeSiteFlow();

        // For a URL-analysis flow the structure is built deterministically in code —
        // the LLM is NOT consulted for the graph (small models got it wrong). The
        // Ollama mock should therefore never be hit.
        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->never();
        $this->app->instance(OllamaService::class, $ollama);

        $agents = $this->app->make(AgentGeneratorService::class)->generate($flow);

        $byUid  = collect($agents)->keyBy('uid');
        $types  = collect($agents)->pluck('type')->all();

        // Lead is the shared base-context agent; the real crawler is node 2.
        $this->assertSame('site_context', $agents[0]['type'], 'The base-context agent must lead');
        $this->assertSame([], $agents[0]['depends_on']);
        $this->assertContains('deep_researcher', $types, 'A real site crawler must be present');
        $this->assertNotContains('trend_researcher', $types);

        // Fan-out: explorer and review analyzer both depend on the base context.
        $this->assertSame(['site_context'], $byUid['site_explorer']['depends_on']);
        $this->assertSame(['site_context'], $byUid['review_finder']['depends_on']);

        // Fan-in: the report author consumes BOTH analyzers AND the raw research +
        // review outputs directly, so early content is not lost to double-condensing.
        $this->assertEqualsCanonicalizing(
            ['content_analyzer', 'review_sentiment', 'site_explorer', 'review_finder', 'site_context'],
            $byUid['report_author']['depends_on'],
        );

        // Exactly one corrector + one verifier, and the verifier is last.
        $this->assertSame(1, collect($types)->filter(fn ($t) => $t === 'bg_text_corrector')->count());
        $this->assertSame(1, collect($types)->filter(fn ($t) => $t === 'qa_verifier')->count());
        $this->assertSame('qa_verifier', end($agents)['type']);
        $this->assertTrue(end($agents)['is_verifier']);

        // The crawler is the code-selected research model, never the Bulgarian text model.
        $this->assertSame('mistral-nemo', $byUid['site_explorer']['model']);
        $this->assertNotSame('todorov/bggpt', $byUid['site_explorer']['model']);

        // Crawler/report prompts carry the right placeholders.
        $this->assertStringContainsString('{{url}}', $byUid['site_explorer']['prompt_template']);
        $this->assertStringContainsString('{{input}}', $byUid['report_author']['prompt_template']);
    }

    public function test_bare_domain_without_scheme_still_triggers_the_branched_pipeline(): void
    {
        // The user described flow 25 with a bare domain ("primelaser.bg") — no
        // https:// — and got a linear flow because the URL wasn't detected. A bare
        // domain must now trigger the deterministic branched skeleton too.
        $company = Company::create([
            'name' => 'Prime Laser', 'description' => 'Лазерна епилация',
            'industry' => 'Beauty', 'language' => 'bg',
        ]);
        $flow = Flow::create([
            'company_id' => $company->id,
            'name' => 'Site Audit (bare domain)',
            'description' => 'Един агент проверява съдържанието на сайта primelaser.bg, '
                .'втори паралелно проверява онлайн ревютата, трети анализатор обединява и прави доклад.',
        ]);

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->never();
        $this->app->instance(OllamaService::class, $ollama);

        $agents = $this->app->make(AgentGeneratorService::class)->generate($flow);

        $this->assertSame('site_context', $agents[0]['type']);
        $this->assertContains('deep_researcher', collect($agents)->pluck('type')->all());
        $this->assertCount(8, $agents);
    }

    public function test_non_site_flow_keeps_the_company_block(): void
    {
        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Спортен център',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);
        $flow = Flow::create([
            'company_id' => $company->id,
            'name' => 'Weekly content',
            'description' => 'Генерирай седмично съдържание за социалните мрежи.',
        ]);

        $this->mockOllamaReturning(json_encode([
            ['name' => 'Изследовател', 'type' => 'researcher', 'order' => 1],
            ['name' => 'Автор', 'type' => 'content_bg', 'order' => 2],
            ['name' => 'QA', 'type' => 'qa_verifier', 'order' => 3],
        ]), $captured);

        $this->app->make(AgentGeneratorService::class)->generate($flow);

        $this->assertStringContainsString('Компания: Game Sport Center', $captured);
        $this->assertStringNotContainsString('ОБЕКТ НА АНАЛИЗ', $captured);
    }

    private function makeSiteFlow(): Flow
    {
        $company = Company::create([
            'name' => 'Prime Laser',
            'description' => 'Лазерна епилация',
            'industry' => 'Beauty',
            'language' => 'bg',
        ]);

        return Flow::create([
            'company_id' => $company->id,
            'name' => 'Site Audit',
            'description' => self::SITE_DESCRIPTION,
        ]);
    }

    private function mockOllamaReturning(string $json, ?string &$capturedUserMessage): void
    {
        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->andReturnUsing(function (...$args) use ($json, &$capturedUserMessage) {
                // chat(string $model, string $systemPrompt, string $userMessage, ...)
                $capturedUserMessage ??= $args[2] ?? '';

                return $json;
            });
        $this->app->instance(OllamaService::class, $ollama);
    }
}
