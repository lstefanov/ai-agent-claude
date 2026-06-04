<?php

namespace Tests\Unit;

use App\Models\FlowNode;
use App\Models\LlmModel;
use App\Services\ModelSelectorService;
use App\Services\NodeExecutorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that NodeExecutorService::ensureModelInstalled properly
 * down-grades the node's model when it is not listed as available.
 */
class FlowExecutorPreflightModelTest extends TestCase
{
    use RefreshDatabase;

    /** Call the private ensureModelInstalled through a fresh NodeExecutorService. */
    private function ensure(FlowNode $node): void
    {
        // NodeExecutorService is injected; call the private method via reflection.
        $service = app(NodeExecutorService::class);
        $method = new \ReflectionMethod($service, 'ensureModelInstalled');
        $method->setAccessible(true);
        // bridgeAgent produces an Agent matching the FlowNode.
        $bridgeAgent = (new \ReflectionMethod($service, 'bridgeAgent'));
        $bridgeAgent->setAccessible(true);
        $agent = $bridgeAgent->invoke($service, $node);

        $method->invoke($service, $agent);
        // Write downgraded model back to node for assertion.
        $node->model = $agent->model;
    }

    public function test_missing_model_is_downgraded_when_auto_pull_enabled(): void
    {
        // phpunit.xml sets OLLAMA_AUTO_PULL=false, so override it for this test.
        config(['services.ollama.auto_pull' => true]);

        LlmModel::create([
            'ollama_tag' => 'mistral', 'display_name' => 'x', 'category' => 'json',
            'description' => 'test', 'is_available' => true, 'is_enabled' => true,
        ]);

        $node = new FlowNode([
            'flow_id' => 0, 'node_key' => 'n1', 'name' => 'R',
            'type' => 'deep_researcher', 'model' => 'mistral-nemo',
        ]);

        $this->ensure($node);

        // 'mistral-nemo' is not in LlmModel as available → selector should downgrade.
        $this->assertNotSame('mistral-nemo', $node->model);
    }

    public function test_available_model_is_not_downgraded(): void
    {
        LlmModel::create([
            'ollama_tag' => 'todorov/bggpt', 'display_name' => 'x', 'category' => 'text',
            'description' => 'test', 'is_available' => true, 'is_enabled' => true,
        ]);

        $node = new FlowNode([
            'flow_id' => 0, 'node_key' => 'n2', 'name' => 'W',
            'type' => 'report_writer', 'model' => 'todorov/bggpt',
        ]);

        $this->ensure($node);

        $this->assertSame('todorov/bggpt', $node->model);
    }
}
