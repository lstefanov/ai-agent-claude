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

    public function test_generate_agents_fast_fails_when_ollama_is_unavailable(): void
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
                'error' => 'Ollama не е достъпна. Стартирай Ollama и опитай отново.',
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

    public function test_create_page_uses_stall_detection_instead_of_old_hard_timeout(): void
    {
        $view = file_get_contents(resource_path('views/flows/create.blade.php'));

        $this->assertStringContainsString('STALL_LIMIT', $view);
        $this->assertStringContainsString('HARD_LIMIT', $view);
        $this->assertStringContainsString('generationStage', $view);
        $this->assertStringContainsString('genStalled', $view);
        $this->assertStringContainsString('resumePolling', $view);
        $this->assertStringNotContainsString('const maxWait = 300', $view);
        $this->assertStringNotContainsString('Генерацията отне прекалено дълго', $view);
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
