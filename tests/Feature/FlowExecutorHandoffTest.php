<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NodeRun;
use App\Services\GraphFlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for node handoff behaviour in the graph executor:
 *  - reasoning stripping
 *  - no duplicate context when an explicit {{Name}} placeholder is used
 *  - bg_text_corrector isolation
 *  - quality_metrics persistence
 *
 * Uses Http::fake to control exactly what each node's Ollama call returns.
 */
class FlowExecutorHandoffTest extends TestCase
{
    use RefreshDatabase;

    /** Build a company + flow with a simple linear graph (A → B). */
    private function makeLinearFlow(array $nodeA, array $nodeB): array
    {
        $company = Company::create([
            'name' => 'Game Sport Center', 'description' => 'Sports center',
            'industry' => 'Fitness', 'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Test Flow', 'description' => 'Handoff tests', 'status' => 'active',
        ]);

        $nA = $flow->nodes()->create(array_merge(['node_key' => 'A', 'is_active' => true], $nodeA));
        $nB = $flow->nodes()->create(array_merge(['node_key' => 'B', 'is_active' => true], $nodeB));
        $flow->edges()->create(['from_node_key' => 'A', 'to_node_key' => 'B']);

        return [$flow, $nA, $nB];
    }

    public function test_executor_keeps_raw_reasoning_but_passes_clean_output_downstream(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"<think>internal chain of thought</think>\\n\\nFinal competitor facts\"}}\n")
                ->push("{\"message\":{\"content\":\"Clean synthesis\"}}\n"),
        ]);

        [$flow, $nA] = $this->makeLinearFlow(
            ['name' => 'Researcher', 'type' => 'content_bg', 'model' => 'research-model', 'prompt_template' => 'Research {{topic}}'],
            ['name' => 'Writer', 'type' => 'content_bg', 'model' => 'writer-model', 'prompt_template' => 'Write from context.'],
        );

        $flowRun = app(GraphFlowExecutor::class)->run($flow);

        $researcherRun = NodeRun::where('flow_run_id', $flowRun->id)
            ->where('node_key', 'A')->firstOrFail();

        $this->assertSame('Final competitor facts', $researcherRun->output);
        $this->assertSame(
            "<think>internal chain of thought</think>\n\nFinal competitor facts",
            $researcherRun->raw_output
        );

        Http::assertSent(function ($request) {
            if ($request['model'] !== 'writer-model') {
                return false;
            }
            $message = $request['messages'][1]['content'];

            return str_contains($message, 'Final competitor facts')
                && ! str_contains($message, 'internal chain of thought')
                && ! str_contains($message, '<think>');
        });
    }

    public function test_template_references_are_not_auto_appended_or_truncated(): void
    {
        $longOutput = str_repeat('A', 1200).' UNIQUE-END-MARKER';

        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push(json_encode(['message' => ['content' => $longOutput]])."\n")
                ->push("{\"message\":{\"content\":\"Done\"}}\n"),
        ]);

        [$flow] = $this->makeLinearFlow(
            ['name' => 'Researcher', 'type' => 'content_bg', 'model' => 'research-model', 'prompt_template' => 'Research {{topic}}'],
            // Uses {{node:Researcher}} — explicit ref, should NOT be auto-appended again
            ['name' => 'Writer', 'type' => 'content_bg', 'model' => 'writer-model', 'prompt_template' => 'Use exactly this research: {{node:Researcher}}'],
        );

        app(GraphFlowExecutor::class)->run($flow);

        Http::assertSent(function ($request) {
            if ($request['model'] !== 'writer-model') {
                return false;
            }
            $message = $request['messages'][1]['content'];

            return str_contains($message, 'UNIQUE-END-MARKER')
                && substr_count($message, 'UNIQUE-END-MARKER') === 1
                && ! str_contains($message, '--- Context from previous agents ---');
        });
    }

    public function test_bg_text_corrector_receives_only_its_prompt_no_upstream_context(): void
    {
        // Use OllamaService mock so we control exactly what each node receives.
        // Http::fake with sequences is tricky for 3+ node flows due to batch transaction ordering.
        $calls = [];
        $this->mock(\App\Services\OllamaService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('chat')->andReturnUsing(function ($model, $sys, $user) use (&$calls) {
                $calls[] = ['model' => $model, 'user' => $user];

                return match ($model) {
                    'research-model' => 'Research context that should not be corrected',
                    'writer-model'   => 'цвоят текст за Плавалка в басейн',
                    default          => 'цвят текст за Плуване в басейн',
                };
            });
        });

        $company = Company::create([
            'name' => 'Game Sport Center', 'description' => 'Sports center',
            'industry' => 'Fitness', 'language' => 'bg',
        ]);
        $flow = $company->flows()->create([
            'name' => 'BG Corrector Flow', 'description' => 'desc', 'status' => 'active',
        ]);
        $flow->nodes()->create(['node_key' => 'R', 'name' => 'Researcher', 'type' => 'content_bg', 'model' => 'research-model', 'prompt_template' => 'Research {{topic}}', 'is_active' => true]);
        $flow->nodes()->create(['node_key' => 'W', 'name' => 'Writer', 'type' => 'content_bg', 'model' => 'writer-model', 'prompt_template' => 'Write final text from context.', 'is_active' => true, 'output_role' => 'body']);
        $flow->nodes()->create(['node_key' => 'C', 'name' => 'Коректор', 'type' => 'bg_text_corrector', 'model' => 'corrector-model', 'prompt_template' => 'Коригирай само: {{node:Writer}}', 'is_active' => true]);
        $flow->edges()->create(['from_node_key' => 'R', 'to_node_key' => 'W']);
        $flow->edges()->create(['from_node_key' => 'W', 'to_node_key' => 'C']);

        $flowRun = app(GraphFlowExecutor::class)->run($flow);
        $flowRun->refresh();

        $this->assertSame('completed', $flowRun->status,
            'Status: '.$flowRun->status.' | Calls: '.json_encode(array_column($calls, 'model')).
            ' | NodeRuns: '.json_encode($flowRun->nodeRuns()->get(['node_key','status'])->toArray()).
            ' | Failure: '.($flowRun->context['failure_message'] ?? 'none')
        );
        $this->assertSame(3, $flowRun->nodeRuns()->count(), 'Expected 3 node_runs (R, W, C)');

        $correctorCall = collect($calls)->firstWhere('model', 'corrector-model');
        $this->assertNotNull($correctorCall, 'corrector-model was not called');

        $message = $correctorCall['user'];
        $this->assertStringContainsString('цвоят текст за Плавалка в басейн', $message);
        $this->assertStringNotContainsString('Research context that should not be corrected', $message);
        $this->assertStringNotContainsString('--- Context from previous agents ---', $message);
    }

    public function test_executor_persists_quality_metrics_for_completed_node_output(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::response([
                'message' => [
                    'content' => "| Конкурент | Услуга | Цена | Тип | Линк |\n"
                        ."|-----------|--------|------|-----|------|\n"
                        ."| V Gym | Месечна карта | 78.23 лв. | фитнес | https://vgym.bg/prices |\n"
                        .'| Grabo | Оферта | н/д | агрегатор | https://grabo.bg/sport |',
                ],
            ]),
        ]);

        $company = Company::create([
            'name' => 'Game Sport Center', 'description' => 'Sports center',
            'industry' => 'Fitness', 'language' => 'bg',
        ]);
        $flow = $company->flows()->create(['name' => 'Pricing Metrics Flow', 'description' => 'desc', 'status' => 'active']);
        $flow->nodes()->create(['node_key' => 'E', 'name' => 'Extractor', 'type' => 'content_bg', 'model' => 'extractor-model', 'prompt_template' => 'Extract {{topic}}', 'is_active' => true]);

        $flowRun = app(GraphFlowExecutor::class)->run($flow);

        $nodeRun = NodeRun::where('flow_run_id', $flowRun->id)->where('node_key', 'E')->firstOrFail();

        $this->assertSame(2, $nodeRun->quality_metrics['markdown_table_rows']);
        $this->assertSame(1, $nodeRun->quality_metrics['priced_rows']);
        $this->assertSame(['grabo.bg', 'vgym.bg'], $nodeRun->quality_metrics['source_domains']);
    }
}
