<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Company;
use App\Models\FlowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunShowOutputUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_show_binds_progress_bar_width_to_progress_percent(): void
    {
        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Competition',
            'description' => 'Research report',
            'status' => 'active',
        ]);

        $flow->agents()->create([
            'name' => 'Финален доклад',
            'type' => 'report_composer',
            'role' => 'Compose',
            'prompt_template' => 'Report',
            'model' => 'qwen2.5:14b',
            'order' => 1,
            'output_role' => 'body',
            'is_active' => true,
        ]);

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'running',
            'triggered_by' => 'manual',
        ]);

        $response = $this->get(route('flow-runs.show', $flowRun));

        $response->assertOk();
        $response->assertSee('x-text="progressPercent + \'%\'"', false);
        $response->assertSee('role="progressbar"', false);
        $response->assertSee(':aria-valuenow="progressPercent"', false);
        $response->assertSee('h-full block rounded-full', false);
        $response->assertSee(':style="{ width: progressPercent + \'%\' }"', false);
    }

    public function test_run_show_uses_wide_scrollable_final_output_and_exposes_raw_reasoning(): void
    {
        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Competition',
            'description' => 'Research report',
            'status' => 'active',
        ]);

        $agent = $flow->agents()->create([
            'name' => 'Финален доклад',
            'type' => 'report_composer',
            'role' => 'Compose',
            'prompt_template' => 'Report',
            'model' => 'qwen2.5:14b',
            'order' => 1,
            'output_role' => 'body',
            'is_active' => true,
        ]);

        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'completed',
            'triggered_by' => 'manual',
        ]);

        AgentRun::create([
            'flow_run_id' => $flowRun->id,
            'agent_id' => $agent->id,
            'status' => 'completed',
            'model_used' => 'qwen2.5:14b',
            'output' => '| Конкурент | Цена |\n|---|---|\n| Ability Spa | 68.45 лв. |',
            'raw_output' => '<think>internal</think>\n| Конкурент | Цена |',
            'completed_at' => now(),
        ]);

        $response = $this->get(route('flow-runs.show', $flowRun));

        $response->assertOk();
        $response->assertSee('max-w-4xl', false);
        $response->assertSee('overflow-x-auto', false);
        $response->assertSee('raw_output', false);
        $response->assertSee('Reasoning (raw)', false);

        $this->get(route('flow-runs.poll', $flowRun))
            ->assertOk()
            ->assertJsonPath('agent_runs.0.raw_output', '<think>internal</think>\n| Конкурент | Цена |');
    }
}
