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
}
