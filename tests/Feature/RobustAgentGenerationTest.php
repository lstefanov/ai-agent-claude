<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\AgentGeneratorService;
use App\Services\OllamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RobustAgentGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_agents_fast_fails_when_llm_is_unavailable(): void
    {
        $company = $this->createCompany();
        $this->fakeBackgroundExecBinary();

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('isAvailable')->once()->andReturnFalse();
        $this->app->instance(OllamaService::class, $ollama);

        $this->postJson(route('flows.generate-agents'), [
            'company_id' => $company->id,
            'name' => 'Slow Flow',
            'description' => 'Generate a detailed pipeline for weekly content.',
        ])
            ->assertStatus(503)
            ->assertJson([
                'error' => 'AI услугата не е достъпна. Провери конфигурацията на LLM провайдъра.',
            ]);
    }

    public function test_generate_agents_initializes_status_cache_with_heartbeat_fields(): void
    {
        $company = $this->createCompany();
        $this->fakeBackgroundExecBinary();

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('isAvailable')->once()->andReturnTrue();
        $this->app->instance(OllamaService::class, $ollama);

        $token = $this->postJson(route('flows.generate-agents'), [
            'company_id' => $company->id,
            'name' => 'Slow Flow',
            'description' => 'Generate a detailed pipeline for weekly content.',
        ])
            ->assertOk()
            ->json('token');

        $status = Cache::get("agent_gen_{$token}");

        $this->assertSame('pending', $status['status']);
        $this->assertSame('Стартиране...', $status['stage']);
        $this->assertIsInt($status['updated_at']);
    }

    public function test_generate_agents_command_writes_heartbeat_from_progress_callback(): void
    {
        $company = $this->createCompany();
        $token = 'heartbeat-token';

        Cache::put("agent_gen_request_{$token}", [
            'company_id' => $company->id,
            'name' => 'Slow Flow',
            'description' => 'Generate a detailed pipeline for weekly content.',
        ], now()->addMinutes(15));
        Cache::put("agent_gen_{$token}", [
            'status' => 'pending',
            'agents' => [],
            'error' => null,
            'stage' => 'Стартиране...',
            'updated_at' => now()->subMinute()->timestamp,
        ], now()->addMinutes(15));

        $generator = Mockery::mock(AgentGeneratorService::class);
        $generator->shouldReceive('generate')
            ->once()
            ->withArgs(function ($flow, $onProgress) use ($token) {
                $this->assertSame('Slow Flow', $flow->name);
                $this->assertIsCallable($onProgress);

                $onProgress('Генериране на агенти');

                $status = Cache::get("agent_gen_{$token}");
                $this->assertSame('pending', $status['status']);
                $this->assertSame('Генериране на агенти', $status['stage']);
                $this->assertIsInt($status['updated_at']);

                return true;
            })
            ->andReturn([
                ['name' => 'Researcher', 'type' => 'researcher', 'order' => 1],
                ['name' => 'Writer', 'type' => 'content_bg', 'order' => 2],
                ['name' => 'QA', 'type' => 'qa_verifier', 'order' => 3],
            ]);
        $this->app->instance(AgentGeneratorService::class, $generator);

        $this->assertSame(0, Artisan::call('flows:generate-agents', ['token' => $token]));
        $this->assertSame('completed', Cache::get("agent_gen_{$token}")['status']);
    }

    public function test_normalized_agent_always_has_prompt_template_and_system_prompt(): void
    {
        // A partial LLM response (missing prompts entirely) must still produce
        // a usable agent with defensive default prompts — the executor would
        // otherwise send empty messages to Ollama.
        $service = app(\App\Services\AgentGeneratorService::class);
        $ref = new \ReflectionMethod($service, 'normalizeAgent');
        $ref->setAccessible(true);

        $agent = $ref->invoke($service, [
            'name' => 'Test Agent',
            'type' => 'content_bg',
            'role' => '',
            'prompt_template' => '',
            'system_prompt' => '',
        ], 1);

        $this->assertIsArray($agent);
        $this->assertNotSame('', trim((string) $agent['prompt_template']));
        $this->assertNotSame('', trim((string) $agent['system_prompt']));
        $this->assertStringContainsString('Test Agent', $agent['system_prompt']);
    }

    public function test_graph_builder_drives_agent_generation_with_progress_polling(): void
    {
        // Agent generation moved from the create wizard into the Graph Editor:
        // a non-dismissable progress modal that polls the generation-status endpoint.
        $view = file_get_contents(resource_path('views/flows/builder.blade.php'));

        $this->assertStringContainsString('startGeneration', $view);
        $this->assertStringContainsString('pollGeneration', $view);
        $this->assertStringContainsString('generationStatusUrlBase', $view);
        $this->assertStringContainsString('applyGeneratedGraph', $view);
        $this->assertStringContainsString('gen.active', $view);
    }

    private function createCompany(): Company
    {
        return Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);
    }

    private function fakeBackgroundExecBinary(): void
    {
        putenv('PHP_CLI_BINARY=echo');
        $_ENV['PHP_CLI_BINARY'] = 'echo';
        $_SERVER['PHP_CLI_BINARY'] = 'echo';
    }
}
