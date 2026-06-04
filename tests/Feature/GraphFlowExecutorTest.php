<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\NodeRun;
use App\Services\GraphFlowExecutor;
use App\Services\NodeExecutorService;
use App\Services\OllamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphFlowExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic, Ollama-free agent output: echo the user message so we can
        // assert what each node actually received as input.
        $this->mock(OllamaService::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->andReturnUsing(fn ($model, $sys, $user) => "ECHO::{$user}");
        });
    }

    public function test_fan_in_node_receives_all_predecessor_outputs(): void
    {
        $flow = $this->makeFlow();

        $a = $this->node($flow, 'A', 'Reviews Finder');
        $b = $this->node($flow, 'B', 'Prices');
        $c = $this->node($flow, 'C', 'Автор на доклад', 'Сглоби доклада.');
        $this->edge($flow, 'A', 'C');
        $this->edge($flow, 'B', 'C');

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id, 'status' => 'running',
            'context' => ['seed' => ['topic' => 'PrimeLaser']],
        ]);

        // Predecessors already completed with distinct outputs.
        $this->completedRun($flowRun, $a, 'OUTPUT_FROM_REVIEWS');
        $this->completedRun($flowRun, $b, 'OUTPUT_FROM_PRICES');

        app(NodeExecutorService::class)->executeNode($flowRun->id, $c->id);

        $authorRun = NodeRun::where('flow_run_id', $flowRun->id)
            ->where('flow_node_id', $c->id)->first();

        $this->assertSame('completed', $authorRun->status);
        // Fan-in: BOTH predecessor outputs present, each under its node name.
        $this->assertStringContainsString('Reviews Finder', $authorRun->input);
        $this->assertStringContainsString('OUTPUT_FROM_REVIEWS', $authorRun->input);
        $this->assertStringContainsString('Prices', $authorRun->input);
        $this->assertStringContainsString('OUTPUT_FROM_PRICES', $authorRun->input);
    }

    public function test_fan_in_node_is_scheduled_after_all_predecessors(): void
    {
        // Diamond: A → B, A → C, (B,C) → D. D must land in a strictly later wave
        // than BOTH B and C, so it can never start before all predecessors finish.
        $result = \App\Support\GraphTopology::analyze(
            ['A', 'B', 'C', 'D'],
            [
                ['from' => 'A', 'to' => 'B'],
                ['from' => 'A', 'to' => 'C'],
                ['from' => 'B', 'to' => 'D'],
                ['from' => 'C', 'to' => 'D'],
            ],
        );

        $this->assertTrue($result['ok'], 'diamond graph should be valid');

        $waveOf = [];
        foreach ($result['waves'] as $i => $wave) {
            foreach ($wave as $key) {
                $waveOf[$key] = $i;
            }
        }

        $this->assertGreaterThan($waveOf['B'], $waveOf['D']);
        $this->assertGreaterThan($waveOf['C'], $waveOf['D']);
    }

    public function test_end_to_end_run_completes_all_nodes(): void
    {
        // QUEUE_CONNECTION=sync in tests → batch waves cascade synchronously.
        $flow = $this->makeFlow();

        $this->node($flow, 'A', 'Root');
        $this->node($flow, 'B', 'Branch 1');
        $this->node($flow, 'C', 'Branch 2');
        $this->node($flow, 'D', 'Автор');
        $this->edge($flow, 'A', 'B');
        $this->edge($flow, 'A', 'C');
        $this->edge($flow, 'B', 'D');
        $this->edge($flow, 'C', 'D');

        $flowRun = app(GraphFlowExecutor::class)->run($flow);
        $flowRun->refresh();

        $this->assertSame('completed', $flowRun->status);
        $this->assertSame(4, $flowRun->nodeRuns()->where('status', 'completed')->count());
        $this->assertNotEmpty($flowRun->final_output); // terminal node D's output
        // Each node_runs.output is unique & namespaced — nothing overwritten.
        $this->assertSame(4, $flowRun->nodeRuns()->whereNotNull('output')->count());
    }

    public function test_final_composer_assembles_body_and_appendix_from_node_runs(): void
    {
        $flow = $this->makeFlow();

        // body, appendix and hidden nodes — composer keeps body+appendix, skips hidden.
        $body = $flow->nodes()->create(['node_key' => 'b', 'name' => 'Body', 'type' => 'writer', 'output_role' => 'body']);
        $appx = $flow->nodes()->create(['node_key' => 'a', 'name' => 'Appendix', 'type' => 'writer', 'output_role' => 'appendix']);
        $hidden = $flow->nodes()->create(['node_key' => 'h', 'name' => 'Research', 'type' => 'researcher', 'output_role' => 'hidden']);

        $run = FlowRun::create(['flow_id' => $flow->id, 'status' => 'running']);
        $this->completedRun($run, $body, 'BODY_CONTENT_aaaaaaaaaa');
        $this->completedRun($run, $appx, 'APPENDIX_CONTENT_bbbbbbbb');
        $this->completedRun($run, $hidden, 'HIDDEN_RESEARCH_should_not_appear');

        $result = app(\App\Services\FinalComposerService::class)->compose($run);

        $this->assertStringContainsString('BODY_CONTENT', $result['output']);
        $this->assertStringContainsString('APPENDIX_CONTENT', $result['output']);
        $this->assertStringNotContainsString('HIDDEN_RESEARCH', $result['output']);
    }

    public function test_cyclic_graph_fails_the_run(): void
    {
        $flow = $this->makeFlow();
        $this->node($flow, 'A', 'A');
        $this->node($flow, 'B', 'B');
        $this->edge($flow, 'A', 'B');
        $this->edge($flow, 'B', 'A');

        $flowRun = app(GraphFlowExecutor::class)->run($flow);
        $flowRun->refresh();

        $this->assertSame('failed', $flowRun->status);
        $this->assertStringContainsString('граф', $flowRun->context['failure_message']);
    }

    public function test_failure_policy_is_resolved_into_run_context(): void
    {
        $flow = $this->makeFlow();
        $this->node($flow, 'A', 'Root');

        // Default → fail_fast.
        $run = app(GraphFlowExecutor::class)->run($flow);
        $this->assertSame('fail_fast', $run->fresh()->context['failure_policy']);

        // graph_layout opt-in → best_effort.
        $flow->update(['graph_layout' => ['failure_policy' => 'best_effort']]);
        $run2 = app(GraphFlowExecutor::class)->run($flow);
        $this->assertSame('best_effort', $run2->fresh()->context['failure_policy']);
    }

    public function test_node_executor_marks_node_failed_and_throws_on_error(): void
    {
        // Override the echo mock with one that always throws.
        $this->mock(OllamaService::class, function ($mock) {
            $mock->shouldReceive('chat')->andThrow(new \RuntimeException('boom'));
        });

        $flow = $this->makeFlow();
        $node = $this->node($flow, 'A', 'Broken');
        $run = FlowRun::create(['flow_id' => $flow->id, 'status' => 'running', 'context' => ['seed' => []]]);

        try {
            app(NodeExecutorService::class)->executeNode($run->id, $node->id);
            $this->fail('Expected executeNode to throw after retries.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('failed after', $e->getMessage());
        }

        $nodeRun = NodeRun::where('flow_run_id', $run->id)->where('flow_node_id', $node->id)->first();
        $this->assertSame('failed', $nodeRun->status);
        $this->assertNotNull($nodeRun->error);
    }

    // ── helpers ──────────────────────────────────────────────────────────


    private function makeFlow(): Flow
    {
        $company = Company::create([
            'name' => 'Co', 'description' => 'd', 'industry' => 'x', 'language' => 'bg',
        ]);

        return $company->flows()->create([
            'name' => 'F', 'description' => 'desc', 'status' => 'active',
        ]);
    }

    private function node(Flow $flow, string $key, string $name, string $prompt = 'Пиши.')
    {
        return $flow->nodes()->create([
            'node_key' => $key, 'name' => $name, 'type' => 'writer',
            'model' => 'test-model', 'prompt_template' => $prompt,
        ]);
    }

    private function edge(Flow $flow, string $from, string $to): void
    {
        $flow->edges()->create(['from_node_key' => $from, 'to_node_key' => $to]);
    }

    private function completedRun(FlowRun $flowRun, $node, string $output): void
    {
        NodeRun::create([
            'flow_run_id' => $flowRun->id, 'flow_node_id' => $node->id,
            'node_key' => $node->node_key, 'status' => 'completed', 'output' => $output,
        ]);
    }
}
