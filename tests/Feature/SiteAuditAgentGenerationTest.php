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

    public function test_site_audit_flow_leads_with_a_crawler_not_trend_researcher(): void
    {
        $flow = $this->makeSiteFlow();

        // AI deliberately returns trend_researcher first — the service must reorder
        // so the site crawler (deep_researcher) leads the pipeline instead.
        $aiAgents = json_encode([
            ['name' => 'Изследовател на тенденции', 'type' => 'trend_researcher', 'order' => 1],
            ['name' => 'Дълбок изследовател', 'type' => 'deep_researcher', 'order' => 2,
                'prompt_template' => 'Обходи {{url}} и извлечи услуги и цени.'],
            ['name' => 'Автор на доклад', 'type' => 'report_writer', 'order' => 3],
            ['name' => 'QA Верификатор', 'type' => 'qa_verifier', 'order' => 4],
        ]);

        $this->mockOllamaReturning($aiAgents, $capturedUserMessage);

        $agents = $this->app->make(AgentGeneratorService::class)->generate($flow);

        $this->assertSame('deep_researcher', $agents[0]['type'], 'A site crawler must lead the pipeline');
        $this->assertNotSame('trend_researcher', $agents[0]['type']);

        // The model is chosen by code (by type), NOT taken from the LLM — a research
        // agent must get a research model, never the Bulgarian text model.
        $this->assertSame('mistral-nemo', $agents[0]['model']);
        $this->assertNotSame('todorov/bggpt', $agents[0]['model']);

        // The generation prompt must surface the real URL and a {{url}} directive.
        $this->assertStringContainsString('https://primelaser.bg', $capturedUserMessage);
        $this->assertStringContainsString('{{url}}', $capturedUserMessage);

        // For a site flow the company record is the subject of NOTHING: the prompt
        // must present the site as the analysis target and forbid company placeholders.
        $this->assertStringContainsString('ОБЕКТ НА АНАЛИЗ', $capturedUserMessage);
        $this->assertStringNotContainsString('Компания: Prime Laser', $capturedUserMessage);
        $this->assertStringContainsString('{{input}}', $capturedUserMessage);
        // The directive forbids referencing the static company description.
        $this->assertMatchesRegularExpression('/ЗАБРАНЕНО.*company_description/s', $capturedUserMessage);
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
