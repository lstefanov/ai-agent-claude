<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Company;
use App\Models\FlowRun;
use App\Models\LlmModel;
use App\Services\FlowExecutorService;
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

    public function test_flow_show_exposes_qa_threshold_dropdown_in_inline_editor(): void
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

        $flow->agents()->create([
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

        LlmModel::create([
            'ollama_tag' => 'qa-model:latest',
            'display_name' => 'QA Model',
            'category' => 'json',
            'description' => 'QA model',
            'ram_required_gb' => 1.0,
            'is_available' => true,
            'is_enabled' => true,
        ]);

        $response = $this->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertSee('QA праг (%)', false);
        $response->assertSee('x-model.number="agent.qa_threshold"', false);
        $this->assertStringContainsString('qa_threshold:', $response->getContent());
        $this->assertStringContainsString('agent.qa_threshold', $response->getContent());
        // Header QA% dropdowns removed — thresholds are now configured per-step via config.qa.threshold
        $response->assertSee('QA Верификация', false);
        $response->assertSee('agent.config.qa.custom_prompt', false);
    }

    public function test_manual_run_creation_snapshots_posted_qa_thresholds(): void
    {
        putenv('PHP_CLI_BINARY=/usr/bin/false');
        $_ENV['PHP_CLI_BINARY'] = '/usr/bin/false';
        $_SERVER['PHP_CLI_BINARY'] = '/usr/bin/false';

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

        $this->post(route('flow-runs.store', $flow), [
            'qa_thresholds' => [
                $verifier->id => 60,
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('flow_runs', [
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
        ]);

        $flowRun = $flow->flowRuns()->firstOrFail();

        $this->assertSame([
            (string) $verifier->id => 60,
        ], $flowRun->context['qa_thresholds']);
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

    public function test_manual_run_creation_snapshots_step_qa_policies(): void
    {
        putenv('PHP_CLI_BINARY=/usr/bin/false');
        $_ENV['PHP_CLI_BINARY'] = '/usr/bin/false';
        $_SERVER['PHP_CLI_BINARY'] = '/usr/bin/false';

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
            'order' => 2,
            'is_verifier' => true,
            'qa_threshold' => 75,
            'is_active' => true,
        ]);

        $writer = $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Writes posts',
            'prompt_template' => 'Write {{topic}}',
            'model' => 'writer-model:latest',
            'order' => 1,
            'is_active' => true,
            'config' => [
                'qa' => [
                    'enabled' => true,
                    'verifier_agent_id' => $verifier->id,
                    'threshold' => 65,
                    'max_retries' => 5,
                ],
            ],
        ]);

        $this->post(route('flow-runs.store', $flow))->assertRedirect();

        $flowRun = $flow->flowRuns()->firstOrFail();

        $this->assertSame([
            (string) $writer->id => [
                'verifier_agent_id' => $verifier->id,
                'threshold' => 65,
                'max_retries' => 5,
                'custom_prompt' => '',
            ],
        ], $flowRun->context['step_qa_policies']);
    }

    public function test_manual_run_creation_rejects_thresholds_for_non_verifier_agents(): void
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
        ]);

        $this->from(route('flows.show', $flow))
            ->post(route('flow-runs.store', $flow), [
                'qa_thresholds' => [
                    $writer->id => 60,
                ],
            ])
            ->assertRedirect(route('flows.show', $flow))
            ->assertSessionHasErrors('qa_thresholds.'.$writer->id);

        $this->assertDatabaseCount('flow_runs', 0);
    }

    public function test_flow_executor_uses_run_snapshot_threshold_for_qa_gate_and_prompt(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"Generated post #tag\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":65,\\\"verdict\\\":\\\"pass\\\"}\"}}\n"),
        ]);

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

        $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Writes posts',
            'prompt_template' => 'Write a post about {{topic}}',
            'model' => 'writer-model:latest',
            'order' => 1,
            'is_active' => true,
        ]);

        $verifier = $flow->agents()->create([
            'name' => 'Контролер на качество',
            'type' => 'qa_verifier',
            'role' => 'Checks quality',
            'prompt_template' => 'Check {{input}}',
            'model' => 'qa-model:latest',
            'order' => 2,
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

        app(FlowExecutorService::class)->run($flow, 'manual', $flowRun);

        $this->assertSame('completed', $flowRun->fresh()->status);

        Http::assertSent(function ($request) {
            return $request['model'] === 'qa-model:latest'
                && str_contains($request['messages'][0]['content'], 'Passing score threshold: 60/100');
        });
    }

    public function test_flow_executor_retries_previous_agent_when_step_qa_fails_then_continues_on_pass(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"Draft v1\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":50,\\\"verdict\\\":\\\"fail\\\"}\"}}\n")
                ->push("{\"message\":{\"content\":\"Draft v2 #tag\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":90,\\\"verdict\\\":\\\"pass\\\"}\"}}\n"),
        ]);

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
            'order' => 2,
            'is_verifier' => true,
            'qa_threshold' => 80,
            'is_active' => true,
        ]);

        $writer = $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Writes posts',
            'prompt_template' => 'Write a post about {{topic}}',
            'model' => 'writer-model:latest',
            'order' => 1,
            'is_active' => true,
            'config' => [
                'qa' => [
                    'enabled' => true,
                    'verifier_agent_id' => $verifier->id,
                    'threshold' => 80,
                    'max_retries' => 3,
                ],
            ],
        ]);

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
        ]);

        app(FlowExecutorService::class)->run($flow, 'manual', $flowRun);

        $this->assertSame('completed', $flowRun->fresh()->status);
        $this->assertSame(2, AgentRun::where('agent_id', $writer->id)->count());
        $this->assertSame(2, AgentRun::where('agent_id', $verifier->id)->count());
        $this->assertSame('Draft v2 #tag', AgentRun::where('agent_id', $writer->id)->latest('id')->first()->output);
        $this->assertSame(90, AgentRun::where('agent_id', $verifier->id)->latest('id')->first()->tokens_used);
    }

    public function test_flow_executor_fails_flow_after_step_qa_retry_limit_is_exhausted(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"Draft v1\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":50,\\\"verdict\\\":\\\"fail\\\"}\"}}\n")
                ->push("{\"message\":{\"content\":\"Draft v2\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":55,\\\"verdict\\\":\\\"fail\\\"}\"}}\n"),
        ]);

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
            'order' => 2,
            'is_verifier' => true,
            'qa_threshold' => 80,
            'is_active' => true,
        ]);

        $writer = $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Writes posts',
            'prompt_template' => 'Write a post about {{topic}}',
            'model' => 'writer-model:latest',
            'order' => 1,
            'is_active' => true,
            'config' => [
                'qa' => [
                    'enabled' => true,
                    'verifier_agent_id' => $verifier->id,
                    'threshold' => 80,
                    'max_retries' => 1,
                ],
            ],
        ]);

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
        ]);

        app(FlowExecutorService::class)->run($flow, 'manual', $flowRun);

        $flowRun->refresh();

        $this->assertSame('failed', $flowRun->status);
        $this->assertSame(2, AgentRun::where('agent_id', $writer->id)->count());
        $this->assertSame(2, AgentRun::where('agent_id', $verifier->id)->count());
        $this->assertStringContainsString('QA validation failed after 1 retries', $flowRun->context['failure_message']);
        $this->assertStringContainsString('score 55 < threshold 80', AgentRun::where('agent_id', $writer->id)->latest('id')->first()->error);
    }

    public function test_flow_executor_supports_five_steps_with_five_step_qa_policies(): void
    {
        $sequence = Http::sequence();
        foreach ([60, 65, 70, 75, 80] as $index => $score) {
            $sequence
                ->push("{\"message\":{\"content\":\"Step ".($index + 1)." output\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":{$score},\\\"verdict\\\":\\\"pass\\\"}\"}}\n");
        }

        Http::fake(['localhost:11434/api/chat' => $sequence]);

        $company = Company::create([
            'name' => 'QA Company',
            'description' => 'Company description',
            'industry' => 'Marketing',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Five Step Flow',
            'description' => 'Runs five validated steps.',
            'status' => 'active',
        ]);

        $writers = collect();
        $verifiers = collect();

        for ($i = 1; $i <= 5; $i++) {
            $writers->push($flow->agents()->create([
                'name' => "Writer {$i}",
                'type' => 'content_bg',
                'role' => 'Writes posts',
                'prompt_template' => 'Write {{topic}}',
                'model' => 'writer-model:latest',
                'order' => ($i * 2) - 1,
                'is_active' => true,
            ]));

            $verifiers->push($flow->agents()->create([
                'name' => "QA {$i}",
                'type' => 'qa_verifier',
                'role' => 'Checks quality',
                'prompt_template' => 'Check {{input}}',
                'model' => 'qa-model:latest',
                'order' => $i * 2,
                'is_verifier' => true,
                'qa_threshold' => 50 + ($i * 5),
                'is_active' => true,
            ]));
        }

        $writers->each(function ($writer, $index) use ($verifiers) {
            $writer->update([
                'config' => [
                    'qa' => [
                        'enabled' => true,
                        'verifier_agent_id' => $verifiers[$index]->id,
                        'threshold' => 55 + (($index + 1) * 5),
                        'max_retries' => $index + 1,
                    ],
                ],
            ]);
        });

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
        ]);

        app(FlowExecutorService::class)->run($flow, 'manual', $flowRun);

        $flowRun->refresh();

        $this->assertSame('completed', $flowRun->status);
        $this->assertCount(5, $flowRun->context['step_qa_results']);
        $writers->each(function ($writer, $index) use ($flowRun) {
            $result = $flowRun->context['step_qa_results'][(string) $writer->id];

            $this->assertSame(60 + ($index * 5), $result['score']);
            $this->assertTrue($result['passed']);
        });
    }

    public function test_step_qa_policy_snapshot_is_used_even_if_agent_config_changes_before_execution(): void
    {
        putenv('PHP_CLI_BINARY=/usr/bin/false');
        $_ENV['PHP_CLI_BINARY'] = '/usr/bin/false';
        $_SERVER['PHP_CLI_BINARY'] = '/usr/bin/false';

        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"Draft with acceptable score\"}}\n")
                ->push("{\"message\":{\"content\":\"{\\\"score\\\":65,\\\"verdict\\\":\\\"pass\\\"}\"}}\n"),
        ]);

        $company = Company::create([
            'name' => 'QA Company',
            'description' => 'Company description',
            'industry' => 'Marketing',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Snapshot Flow',
            'description' => 'Checks snapshot consistency.',
            'status' => 'active',
        ]);

        $verifier = $flow->agents()->create([
            'name' => 'QA',
            'type' => 'qa_verifier',
            'role' => 'Checks quality',
            'prompt_template' => 'Check {{input}}',
            'model' => 'qa-model:latest',
            'order' => 2,
            'is_verifier' => true,
            'qa_threshold' => 90,
            'is_active' => true,
        ]);

        $writer = $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Writes posts',
            'prompt_template' => 'Write {{topic}}',
            'model' => 'writer-model:latest',
            'order' => 1,
            'is_active' => true,
            'config' => [
                'qa' => [
                    'enabled' => true,
                    'verifier_agent_id' => $verifier->id,
                    'threshold' => 60,
                    'max_retries' => 0,
                ],
            ],
        ]);

        $this->post(route('flow-runs.store', $flow))->assertRedirect();
        $flowRun = $flow->flowRuns()->firstOrFail();

        $writer->update([
            'config' => [
                'qa' => [
                    'enabled' => true,
                    'verifier_agent_id' => $verifier->id,
                    'threshold' => 90,
                    'max_retries' => 0,
                ],
            ],
        ]);

        app(FlowExecutorService::class)->run($flow, 'manual', $flowRun);

        $flowRun->refresh();

        $this->assertSame('completed', $flowRun->status);
        $this->assertSame(60, $flowRun->context['step_qa_policies'][(string) $writer->id]['threshold']);
        $this->assertSame(65, $flowRun->context['step_qa_results'][(string) $writer->id]['score']);
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
        $response->assertSee('agent.qa_threshold ?? 75', false);
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
