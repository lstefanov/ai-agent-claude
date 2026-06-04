<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Company;
use App\Models\FlowRun;
use App\Models\LlmModel;
use App\Models\NodeRun;
use App\Services\GraphFlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QaThresholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_flow_agent_manager_stores_qa_verifier_default_threshold(): void
    {
        $company = Company::create([
            'name' => 'QA Company',
            'description' => 'Company description',
            'industry' => 'Marketing',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Social Flow',
            'description' => 'Creates and checks social posts.',
            'status' => 'active',
        ]);

        $this->postJson(route('agents.store', $flow), [
            'name' => 'Контролер на качество',
            'model' => 'qa-model:latest',
            'type' => 'qa_verifier',
            'qa_threshold' => 85,
        ])
            ->assertCreated()
            ->assertJsonPath('agent.is_verifier', true)
            ->assertJsonPath('agent.qa_threshold', 85);

        $this->assertDatabaseHas('agents', [
            'flow_id' => $flow->id,
            'type' => 'qa_verifier',
            'is_verifier' => true,
            'qa_threshold' => 85,
        ]);
    }

    public function test_agent_update_persists_step_qa_policy_for_non_verifier_agent(): void
    {
        $company = Company::create([
            'name' => 'QA Company',
            'description' => 'Company description',
            'industry' => 'Marketing',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Social Flow',
            'description' => 'Creates and checks social posts.',
            'status' => 'active',
        ]);

        $writer = $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Writes posts',
            'prompt_template' => 'Write {{topic}}',
            'model' => 'writer-model:latest',
            'order' => 1,
            'is_active' => true,
            'config' => ['temperature' => 0.7, 'num_predict' => 1000],
        ]);

        $verifier = $flow->agents()->create([
            'name' => 'Контролер на качество',
            'type' => 'qa_verifier',
            'role' => 'Checks quality',
            'prompt_template' => 'Check {{input}}',
            'model' => 'qa-model:latest',
            'order' => 2,
            'is_verifier' => true,
            'qa_threshold' => 70,
            'is_active' => true,
        ]);

        $this->putJson(route('agents.update', [$flow, $writer]), [
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Writes posts',
            'system_prompt' => '',
            'prompt_template' => 'Write {{topic}}',
            'model' => 'writer-model:latest',
            'is_verifier' => false,
            'config' => [
                'temperature' => 0.8,
                'num_predict' => 1200,
                'qa' => [
                    'enabled' => true,
                    'verifier_agent_id' => $verifier->id,
                    'threshold' => 82,
                    'max_retries' => 3,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('agent.config.qa.enabled', true)
            ->assertJsonPath('agent.config.qa.verifier_agent_id', $verifier->id)
            ->assertJsonPath('agent.config.qa.threshold', 82)
            ->assertJsonPath('agent.config.qa.max_retries', 3);

        $config = $writer->fresh()->config;

        $this->assertSame([
            'enabled' => true,
            'verifier_agent_id' => $verifier->id,
            'threshold' => 82,
            'max_retries' => 3,
            'custom_prompt' => '',
        ], $config['qa']);
    }

    public function test_node_qa_gate_uses_configured_threshold_in_verifier_prompt(): void
    {
        // The verifier system prompt must say "Passing score threshold: 60/100"
        // (from writer.config.qa.threshold=60), not 80 (the verifier node's default).
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"Generated post #tag\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":65,\\\"verdict\\\":\\\"pass\\\"}\"}}\n"),
        ]);

        $company = Company::create(['name' => 'QA Co', 'description' => 'desc', 'industry' => 'Marketing', 'language' => 'bg']);
        $flow = $company->flows()->create(['name' => 'Social Flow', 'description' => 'Posts', 'status' => 'active']);

        $flow->nodes()->create(['node_key' => 'V', 'name' => 'Верификатор', 'type' => 'qa_verifier', 'model' => 'qa-model:latest', 'is_active' => true]);
        $flow->nodes()->create([
            'node_key' => 'W', 'name' => 'Writer', 'type' => 'content_bg', 'model' => 'writer-model:latest',
            'prompt_template' => 'Write a post about {{topic}}', 'is_active' => true,
            'config' => ['qa' => ['enabled' => true, 'verifier_node_key' => 'V', 'threshold' => 60, 'max_retries' => 0]],
        ]);
        // Single node W (no edges needed — just a single node run)

        $flowRun = app(GraphFlowExecutor::class)->run($flow);

        $this->assertSame('completed', $flowRun->fresh()->status);

        Http::assertSent(fn ($req) =>
            $req['model'] === 'qa-model:latest'
            && str_contains($req['messages'][0]['content'], 'Passing score threshold: 60/100')
        );
    }

    public function test_node_qa_retries_on_fail_and_passes_on_second_attempt(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"Draft v1\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":50,\\\"verdict\\\":\\\"fail\\\"}\"}}\n")
                ->push("{\"message\":{\"content\":\"Draft v2 #tag\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":90,\\\"verdict\\\":\\\"pass\\\"}\"}}\n"),
        ]);

        $company = Company::create(['name' => 'QA Co', 'description' => 'desc', 'industry' => 'Marketing', 'language' => 'bg']);
        $flow = $company->flows()->create(['name' => 'QA Retry Flow', 'description' => 'desc', 'status' => 'active']);

        $flow->nodes()->create(['node_key' => 'V', 'name' => 'QA', 'type' => 'qa_verifier', 'model' => 'qa-model:latest', 'is_active' => true]);
        $flow->nodes()->create([
            'node_key' => 'W', 'name' => 'Writer', 'type' => 'content_bg', 'model' => 'writer-model:latest',
            'prompt_template' => 'Write a post about {{topic}}', 'is_active' => true,
            'config' => ['qa' => ['enabled' => true, 'verifier_node_key' => 'V', 'threshold' => 80, 'max_retries' => 3]],
        ]);

        $flowRun = app(GraphFlowExecutor::class)->run($flow);
        $flowRun->refresh();

        $this->assertSame('completed', $flowRun->status);
        // Node W ran twice, verifier ran twice.
        $this->assertSame(4, Http::recorded()->count());

        $writerRun = NodeRun::where('flow_run_id', $flowRun->id)->where('node_key', 'W')->firstOrFail();
        $this->assertSame('Draft v2 #tag', $writerRun->output);
        $this->assertSame('completed', $writerRun->status);

        $qaResult = $flowRun->context['step_qa_results']['W'] ?? null;
        $this->assertNotNull($qaResult);
        $this->assertSame(90, $qaResult['score']);
    }

    public function test_node_qa_fails_run_after_retry_limit_exhausted(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"Draft v1\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":50,\\\"verdict\\\":\\\"fail\\\"}\"}}\n")
                ->push("{\"message\":{\"content\":\"Draft v2\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":55,\\\"verdict\\\":\\\"fail\\\"}\"}}\n"),
        ]);

        $company = Company::create(['name' => 'QA Co', 'description' => 'desc', 'industry' => 'Marketing', 'language' => 'bg']);
        $flow = $company->flows()->create(['name' => 'QA Fail Flow', 'description' => 'desc', 'status' => 'active']);

        $flow->nodes()->create(['node_key' => 'V', 'name' => 'QA', 'type' => 'qa_verifier', 'model' => 'qa-model:latest', 'is_active' => true]);
        $flow->nodes()->create([
            'node_key' => 'W', 'name' => 'Writer', 'type' => 'content_bg', 'model' => 'writer-model:latest',
            'prompt_template' => 'Write a post about {{topic}}', 'is_active' => true,
            'config' => ['qa' => ['enabled' => true, 'verifier_node_key' => 'V', 'threshold' => 80, 'max_retries' => 1]],
        ]);

        $flowRun = app(GraphFlowExecutor::class)->run($flow);
        $flowRun->refresh();

        $this->assertSame('failed', $flowRun->status);
        $this->assertSame(4, Http::recorded()->count()); // 2 writer + 2 verifier

        $writerRun = NodeRun::where('flow_run_id', $flowRun->id)->where('node_key', 'W')->firstOrFail();
        $this->assertSame('failed', $writerRun->status);
        $this->assertStringContainsString('QA gate failed after 1 retries', $writerRun->error);
        $this->assertStringContainsString('score 55 < threshold 80', $writerRun->error);
    }

    public function test_five_nodes_each_with_step_qa_all_pass(): void
    {
        $sequence = Http::sequence();
        foreach ([60, 65, 70, 75, 80] as $i => $score) {
            $sequence
                ->push("{\"message\":{\"content\":\"Step ".($i + 1)." output\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":{$score},\\\"verdict\\\":\\\"pass\\\"}\"}}\n");
        }
        Http::fake(['localhost:11434/api/chat' => $sequence]);

        $company = Company::create(['name' => 'QA Co', 'description' => 'desc', 'industry' => 'Marketing', 'language' => 'bg']);
        $flow = $company->flows()->create(['name' => 'Five Node Flow', 'description' => 'desc', 'status' => 'active']);

        // Create 5 (writer + verifier) pairs wired sequentially: W1→W2→...→W5
        $prevKey = null;
        for ($i = 1; $i <= 5; $i++) {
            $vKey = "V{$i}";
            $wKey = "W{$i}";
            $threshold = 55 + ($i * 5);

            $flow->nodes()->create(['node_key' => $vKey, 'name' => "QA {$i}", 'type' => 'qa_verifier', 'model' => 'qa-model:latest', 'is_active' => true]);
            $flow->nodes()->create([
                'node_key' => $wKey, 'name' => "Writer {$i}", 'type' => 'content_bg',
                'model' => 'writer-model:latest', 'prompt_template' => 'Write {{topic}}',
                'is_active' => true,
                'config' => ['qa' => ['enabled' => true, 'verifier_node_key' => $vKey, 'threshold' => $threshold, 'max_retries' => $i]],
            ]);

            if ($prevKey) {
                $flow->edges()->create(['from_node_key' => $prevKey, 'to_node_key' => $wKey]);
            }
            $prevKey = $wKey;
        }

        $flowRun = app(GraphFlowExecutor::class)->run($flow);
        $flowRun->refresh();

        $this->assertSame('completed', $flowRun->status);
        $this->assertCount(5, $flowRun->context['step_qa_results']);

        foreach ([1 => 60, 2 => 65, 3 => 70, 4 => 75, 5 => 80] as $i => $expectedScore) {
            $result = $flowRun->context['step_qa_results']["W{$i}"] ?? null;
            $this->assertNotNull($result, "Missing QA result for W{$i}");
            $this->assertSame($expectedScore, $result['score']);
        }
    }

    public function test_run_show_uses_snapshot_threshold_and_exposes_pending_dropdown(): void
    {
        $company = Company::create([
            'name' => 'QA Company',
            'description' => 'Company description',
            'industry' => 'Marketing',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Social Flow',
            'description' => 'Creates and checks social posts.',
            'status' => 'active',
        ]);

        $verifier = $flow->agents()->create([
            'name' => 'Контролер на качество',
            'type' => 'qa_verifier',
            'role' => 'Checks quality',
            'prompt_template' => 'Check {{input}}',
            'model' => 'qa-model:latest',
            'order' => 1,
            'is_verifier' => true,
            'qa_threshold' => 80,
            'is_active' => true,
        ]);

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
            'context' => [
                'qa_thresholds' => [
                    (string) $verifier->id => 60,
                ],
            ],
        ]);

        $response = $this->get(route('flow-runs.show', $flowRun));

        $response->assertOk();
        $response->assertSee('"qa_threshold":60', false);
        $response->assertSee('x-model.number="agent.qa_threshold"', false);
        $response->assertSee('stepQaBadge(agent)', false);
        $response->assertSee('agent.qa_threshold ?? 60', false);
        $response->assertSee('agent.qa_threshold !== null', false);
    }

    public function test_run_show_preserves_zero_percent_snapshot_threshold(): void
    {
        $company = Company::create([
            'name' => 'QA Company',
            'description' => 'Company description',
            'industry' => 'Marketing',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Social Flow',
            'description' => 'Creates and checks social posts.',
            'status' => 'active',
        ]);

        $verifier = $flow->agents()->create([
            'name' => 'Контролер на качество',
            'type' => 'qa_verifier',
            'role' => 'Checks quality',
            'prompt_template' => 'Check {{input}}',
            'model' => 'qa-model:latest',
            'order' => 1,
            'is_verifier' => true,
            'qa_threshold' => 80,
            'is_active' => true,
        ]);

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'completed',
            'triggered_by' => 'manual',
            'context' => [
                'qa_thresholds' => [
                    (string) $verifier->id => 0,
                ],
            ],
        ]);

        AgentRun::create([
            'flow_run_id' => $flowRun->id,
            'agent_id' => $verifier->id,
            'status' => 'completed',
            'model_used' => 'qa-model:latest',
            'tokens_used' => 0,
            'completed_at' => now(),
        ]);

        $response = $this->get(route('flow-runs.show', $flowRun));

        $response->assertOk();
        $response->assertSee('"qa_threshold":0', false);
        $response->assertSee('qaThresholdLabel(agent)', false);
    }

    /** @deprecated Endpoint removed — QA thresholds are now configured per-step via config.qa.threshold */
    public function test_run_threshold_patch_updates_pending_verifier_and_rejects_started_verifier(): void
    {
        $this->markTestSkipped('updateQaThresholds endpoint removed — thresholds configured per-step via config.qa.threshold');
    }

    public function test_run_threshold_patch_removed_endpoint_returns_404(): void
    {
        $company = Company::create(['name' => 'QA Co', 'description' => 'd', 'industry' => 'Tech', 'language' => 'bg']);
        $flow = $company->flows()->create(['name' => 'F', 'description' => 'd', 'status' => 'active']);
        $flowRun = FlowRun::create(['flow_id' => $flow->id, 'status' => 'pending', 'triggered_by' => 'manual', 'context' => []]);

        $this->patchJson("/runs/{$flowRun->id}/qa-thresholds", ['agent_id' => 1, 'qa_threshold' => 70])
            ->assertNotFound();
    }

    public function _test_run_threshold_patch_updates_pending_verifier_and_rejects_started_verifier_REMOVED(): void
    {
        $company = Company::create([
            'name' => 'QA Company',
            'description' => 'Company description',
            'industry' => 'Marketing',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Social Flow',
            'description' => 'Creates and checks social posts.',
            'status' => 'active',
        ]);

        $verifier = $flow->agents()->create([
            'name' => 'Контролер на качество',
            'type' => 'qa_verifier',
            'role' => 'Checks quality',
            'prompt_template' => 'Check {{input}}',
            'model' => 'qa-model:latest',
            'order' => 1,
            'is_verifier' => true,
            'qa_threshold' => 70,
            'is_active' => true,
        ]);

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
            'context' => [
                'qa_thresholds' => [
                    (string) $verifier->id => 70,
                ],
            ],
        ]);

        $this->patchJson("/runs/{$flowRun->id}/qa-thresholds", [
            'agent_id' => $verifier->id,
            'qa_threshold' => 55,
        ])
            ->assertOk()
            ->assertJsonPath('qa_thresholds.'.$verifier->id, 55);

        $this->assertSame(55, $flowRun->fresh()->context['qa_thresholds'][(string) $verifier->id]);

        AgentRun::create([
            'flow_run_id' => $flowRun->id,
            'agent_id' => $verifier->id,
            'status' => 'running',
            'model_used' => 'qa-model:latest',
            'started_at' => now(),
        ]);

        $this->patchJson("/runs/{$flowRun->id}/qa-thresholds", [
            'agent_id' => $verifier->id,
            'qa_threshold' => 90,
        ])->assertUnprocessable();

        $this->assertSame(55, $flowRun->fresh()->context['qa_thresholds'][(string) $verifier->id]);

        $newVerifier = $flow->agents()->create([
            'name' => 'New QA',
            'type' => 'qa_verifier',
            'role' => 'Checks quality',
            'prompt_template' => 'Check {{input}}',
            'model' => 'qa-model:latest',
            'order' => 2,
            'is_verifier' => true,
            'qa_threshold' => 75,
            'is_active' => true,
        ]);

        $secondFlowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
            'context' => [
                'qa_thresholds' => [
                    (string) $verifier->id => 70,
                ],
            ],
        ]);

        $this->patchJson("/runs/{$secondFlowRun->id}/qa-thresholds", [
            'agent_id' => $newVerifier->id,
            'qa_threshold' => 40,
        ])->assertUnprocessable();

        $this->assertArrayNotHasKey((string) $newVerifier->id, $secondFlowRun->fresh()->context['qa_thresholds']);

        $secondFlowRun->update(['status' => 'failed']);

        $this->patchJson("/runs/{$secondFlowRun->id}/qa-thresholds", [
            'agent_id' => $verifier->id,
            'qa_threshold' => 40,
        ])->assertUnprocessable();

        $this->assertSame(70, $secondFlowRun->fresh()->context['qa_thresholds'][(string) $verifier->id]);
    }
}
