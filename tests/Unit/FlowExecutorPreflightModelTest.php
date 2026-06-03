<?php

namespace Tests\Unit;

use App\Agents\AgentFactory;
use App\Models\Agent;
use App\Models\LlmModel;
use App\Services\FlowExecutorService;
use App\Services\ModelSelectorService;
use App\Services\OllamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FlowExecutorPreflightModelTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, Agent> $agents */
    private function ensure(OllamaService $ollama, array $agents): void
    {
        $service = new FlowExecutorService(app(AgentFactory::class), $ollama, new ModelSelectorService);
        $method = new \ReflectionMethod($service, 'ensureModelsInstalled');
        $method->setAccessible(true);
        $method->invoke($service, $agents);
    }

    public function test_missing_model_is_pulled_and_marked_available(): void
    {
        LlmModel::create([
            'ollama_tag' => 'mistral-nemo', 'display_name' => 'x', 'category' => 'json',
            'description' => 'test', 'is_available' => false, 'is_enabled' => true,
        ]);

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('listModels')->andReturn([['name' => 'todorov/bggpt']]);
        $ollama->shouldReceive('pull')->with('mistral-nemo')->once()->andReturnTrue();

        $agent = new Agent(['name' => 'R', 'role' => 'r', 'type' => 'deep_researcher', 'model' => 'mistral-nemo']);
        $this->ensure($ollama, [$agent]);

        $this->assertTrue(LlmModel::where('ollama_tag', 'mistral-nemo')->first()->is_available);
        $this->assertSame('mistral-nemo', $agent->model);
    }

    public function test_failed_pull_downgrades_to_installed_model(): void
    {
        LlmModel::create([
            'ollama_tag' => 'mistral', 'display_name' => 'x', 'category' => 'json',
            'description' => 'test', 'is_available' => true, 'is_enabled' => true,
        ]);

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('listModels')->andReturn([['name' => 'mistral']]);
        $ollama->shouldReceive('pull')->with('mistral-nemo')->andReturnFalse();

        $agent = new Agent(['name' => 'R', 'role' => 'r', 'type' => 'deep_researcher', 'model' => 'mistral-nemo']);
        $this->ensure($ollama, [$agent]);

        // research candidates: mistral-nemo, qwen2.5:14b, qwen2.5:7b, mistral → first installed is 'mistral'
        $this->assertSame('mistral', $agent->model);
    }

    public function test_installed_model_is_not_pulled(): void
    {
        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('listModels')->andReturn([['name' => 'todorov/bggpt']]);
        $ollama->shouldReceive('pull')->never();

        $agent = new Agent(['name' => 'W', 'role' => 'w', 'type' => 'report_writer', 'model' => 'todorov/bggpt']);
        $this->ensure($ollama, [$agent]);

        $this->assertSame('todorov/bggpt', $agent->model);
    }
}
