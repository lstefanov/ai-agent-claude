<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\NodeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeFlow(): Flow
    {
        $company = Company::create([
            'name' => 'Co', 'description' => 'd', 'industry' => 'x', 'language' => 'bg',
        ]);

        return $company->flows()->create([
            'name' => 'Graph Flow', 'description' => 'desc', 'status' => 'active',
        ]);
    }

    public function test_builder_page_renders_with_drawflow_and_palette(): void
    {
        $flow = $this->makeFlow();

        $response = $this->get(route('flows.builder', $flow));

        $response->assertOk();
        $response->assertSee('id="drawflow"', false);
        $response->assertSee('drawflow.min.js', false);
        $response->assertSee('flowBuilder(', false);
        // Palette is seeded from config/agent_types.php.
        $response->assertSee('agentTypes', false);
    }

    public function test_graph_store_persists_nodes_and_edges(): void
    {
        $flow = $this->makeFlow();

        $graph = ['drawflow' => ['Home' => ['data' => [
            '1' => [
                'id' => 1, 'name' => 'writer', 'pos_x' => 10, 'pos_y' => 20,
                'data' => ['type' => 'writer', 'name' => 'A'],
                'outputs' => ['output_1' => ['connections' => [['node' => '2', 'output' => 'input_1']]]],
            ],
            '2' => [
                'id' => 2, 'name' => 'report_composer', 'pos_x' => 200, 'pos_y' => 20,
                'data' => ['type' => 'report_composer', 'name' => 'Author'],
                'outputs' => ['output_1' => ['connections' => []]],
            ],
        ]]]];

        $this->postJson(route('flows.graph.store', $flow), ['graph' => $graph])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(2, $flow->nodes()->count());
        $this->assertSame(1, $flow->edges()->count());
        $this->assertNotNull($flow->fresh()->graph_layout);
    }

    public function test_graph_validate_reports_cycle(): void
    {
        $flow = $this->makeFlow();

        $graph = ['drawflow' => ['Home' => ['data' => [
            '1' => ['id' => 1, 'name' => 'writer', 'data' => ['type' => 'writer', 'name' => 'A'],
                'outputs' => ['output_1' => ['connections' => [['node' => '2', 'output' => 'input_1']]]]],
            '2' => ['id' => 2, 'name' => 'writer', 'data' => ['type' => 'writer', 'name' => 'B'],
                'outputs' => ['output_1' => ['connections' => [['node' => '1', 'output' => 'input_1']]]]],
        ]]]];

        $this->postJson(route('flows.graph.validate', $flow), ['graph' => $graph])
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_poll_returns_node_run_statuses(): void
    {
        $flow = $this->makeFlow();
        $node = $flow->nodes()->create(['node_key' => 'n1', 'name' => 'A', 'type' => 'writer']);
        $run = FlowRun::create(['flow_id' => $flow->id, 'status' => 'running']);
        NodeRun::create([
            'flow_run_id' => $run->id, 'flow_node_id' => $node->id,
            'node_key' => 'n1', 'status' => 'running',
        ]);

        $this->getJson(route('flow-runs.poll', $run))
            ->assertOk()
            ->assertJsonPath('node_runs.0.node_key', 'n1')
            ->assertJsonPath('node_runs.0.status', 'running');
    }
}
