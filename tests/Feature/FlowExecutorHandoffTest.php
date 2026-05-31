<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Company;
use App\Services\FlowExecutorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowExecutorHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_executor_keeps_raw_reasoning_but_passes_clean_output_downstream(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push("{\"message\":{\"content\":\"<think>internal chain of thought</think>\\n\\nFinal competitor facts\"}}\n")
                ->push("{\"message\":{\"content\":\"Clean synthesis\"}}\n"),
        ]);

        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Research Flow',
            'description' => 'Tests handoff',
            'status' => 'active',
        ]);

        $researcher = $flow->agents()->create([
            'name' => 'Researcher',
            'type' => 'content_bg',
            'role' => 'Research',
            'prompt_template' => 'Research {{topic}}',
            'model' => 'research-model',
            'order' => 1,
            'is_active' => true,
        ]);

        $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Write',
            'prompt_template' => 'Write from context.',
            'model' => 'writer-model',
            'order' => 2,
            'is_active' => true,
        ]);

        app(FlowExecutorService::class)->run($flow);

        $firstRun = AgentRun::where('agent_id', $researcher->id)->firstOrFail();
        $this->assertSame('Final competitor facts', $firstRun->output);
        $this->assertSame("<think>internal chain of thought</think>\n\nFinal competitor facts", $firstRun->raw_output);

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

        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Research Flow',
            'description' => 'Tests handoff',
            'status' => 'active',
        ]);

        $flow->agents()->create([
            'name' => 'Researcher',
            'type' => 'content_bg',
            'role' => 'Research',
            'prompt_template' => 'Research {{topic}}',
            'model' => 'research-model',
            'order' => 1,
            'is_active' => true,
        ]);

        $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Write',
            'prompt_template' => 'Use exactly this research: {{Researcher}}',
            'model' => 'writer-model',
            'order' => 2,
            'is_active' => true,
        ]);

        app(FlowExecutorService::class)->run($flow);

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

    public function test_bg_text_corrector_receives_only_previous_final_body_text(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push(json_encode(['message' => ['content' => 'Research context that should not be corrected']])."\n")
                ->push(json_encode(['message' => ['content' => 'цвоят текст за Плавалка в басейн']])."\n")
                ->push(json_encode(['message' => ['content' => 'цвят текст за Плуване в басейн']])."\n"),
        ]);

        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'BG Corrector Flow',
            'description' => 'Tests final Bulgarian correction',
            'status' => 'active',
        ]);

        $flow->agents()->create([
            'name' => 'Researcher',
            'type' => 'content_bg',
            'role' => 'Research',
            'prompt_template' => 'Research {{topic}}',
            'model' => 'research-model',
            'order' => 1,
            'is_active' => true,
        ]);

        $flow->agents()->create([
            'name' => 'Writer',
            'type' => 'content_bg',
            'role' => 'Write',
            'prompt_template' => 'Write final text from context.',
            'model' => 'writer-model',
            'order' => 2,
            'is_active' => true,
        ]);

        $flow->agents()->create([
            'name' => 'Български коректор',
            'type' => 'bg_text_corrector',
            'role' => 'Correct',
            'prompt_template' => 'Коригирай само този финален текст: {{input}}',
            'model' => 'corrector-model',
            'order' => 3,
            'is_active' => true,
        ]);

        app(FlowExecutorService::class)->run($flow);

        Http::assertSent(function ($request) {
            if (! isset($request['model'])) {
                return false;
            }

            if ($request['model'] !== 'corrector-model') {
                return false;
            }

            $message = $request['messages'][1]['content'];

            return str_contains($message, 'цвоят текст за Плавалка в басейн')
                && ! str_contains($message, 'Research context that should not be corrected')
                && ! str_contains($message, '--- Context from previous agents ---');
        });
    }

    public function test_executor_persists_quality_metrics_for_completed_agent_output(): void
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
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Pricing Metrics Flow',
            'description' => 'Tests quality metrics',
            'status' => 'active',
        ]);

        $agent = $flow->agents()->create([
            'name' => 'Extractor',
            'type' => 'content_bg',
            'role' => 'Extract prices',
            'prompt_template' => 'Extract {{topic}}',
            'model' => 'extractor-model',
            'order' => 1,
            'is_active' => true,
        ]);

        app(FlowExecutorService::class)->run($flow);

        $agentRun = AgentRun::where('agent_id', $agent->id)->firstOrFail();

        $this->assertSame(2, $agentRun->quality_metrics['markdown_table_rows']);
        $this->assertSame(1, $agentRun->quality_metrics['priced_rows']);
        $this->assertSame(['grabo.bg', 'vgym.bg'], $agentRun->quality_metrics['source_domains']);
    }
}
