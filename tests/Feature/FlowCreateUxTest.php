<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Company;
use App\Models\Flow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowCreateUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_shows_only_basic_info_and_generate_button(): void
    {
        // Agents are now built in the Graph Editor. The create page is basic-info only.
        $company = $this->createCompany();

        $content = $this->get(route('companies.flows.create', $company))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Запази и генерирай агенти', $content);
        $this->assertStringContainsString('Откажи', $content);
        // The old wizard's inline agent editor / picker are gone from create.
        $this->assertStringNotContainsString('openAgentPicker', $content);
        $this->assertStringNotContainsString('Добави агент ръчно', $content);
    }

    public function test_create_page_flow_form_buttons_all_declare_explicit_type(): void
    {
        $company = $this->createCompany();

        $content = $this->get(route('companies.flows.create', $company))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<form\b[^>]*\bid="flow-form"[^>]*>.*?<\/form>/s',
            $content,
            'The create flow form should be present.'
        );

        preg_match('/<form\b[^>]*\bid="flow-form"[^>]*>.*?<\/form>/s', $content, $formMatch);
        preg_match_all('/<button\b([^>]*)>/i', $formMatch[0], $buttonMatches, PREG_SET_ORDER);

        foreach ($buttonMatches as $buttonMatch) {
            $this->assertMatchesRegularExpression(
                '/\btype\s*=/i',
                $buttonMatch[1],
                "Button inside flow-form is missing an explicit type: {$buttonMatch[0]}"
            );
        }
    }

    public function test_agent_edit_page_shows_bulgarian_output_preference_labels_with_stable_values(): void
    {
        $company = $this->createCompany();
        $flow = Flow::create([
            'company_id' => $company->id,
            'name' => 'Weekly Report',
            'description' => 'Create a weekly market report.',
            'status' => 'draft',
        ]);
        $agent = Agent::create([
            'flow_id' => $flow->id,
            'name' => 'Researcher',
            'type' => 'content_bg',
            'role' => 'Research market signals.',
            'system_prompt' => 'You research market signals.',
            'prompt_template' => 'Research {{topic}}.',
            'model' => 'qwen2.5:14b',
            'order' => 1,
            'output_language' => 'bg',
            'output_tone' => 'Analytical',
            'output_style' => 'Technical',
            'output_format' => 'Report',
            'config' => ['temperature' => 0.25],
            'is_active' => true,
        ]);

        $content = $this->get(route('agents.edit', [$flow, $agent]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="Analytical" selected>\\s*Аналитичен\\s*<\\/option>/', $content);
        $this->assertMatchesRegularExpression('/<option value="Technical" selected>\\s*Технически\\s*<\\/option>/', $content);
        $this->assertMatchesRegularExpression('/<option value="Report" selected>\\s*Доклад\\s*<\\/option>/', $content);
        $this->assertMatchesRegularExpression('/<option value="Warm"\\s*>\\s*Топъл\\s*<\\/option>/', $content);
        $this->assertMatchesRegularExpression('/<option value="How-to guide"\\s*>\\s*Практическо ръководство\\s*<\\/option>/', $content);
        $this->assertStringContainsString('Tone: Use a <strong>аналитичен</strong> tone.', $content);
    }

    public function test_store_creates_flow_and_redirects_to_builder_with_generate_flag(): void
    {
        // New flow: store saves basic info only, then redirects to the Graph Editor
        // with ?generate=1 so the builder kicks off AI agent generation.
        $company = $this->createCompany();

        $response = $this->post(route('companies.flows.store', $company), [
            'name' => 'Weekly Report',
            'description' => 'Create a weekly market report.',
            'status' => 'draft',
            'schedule_cron' => null,
        ]);

        $flow = $company->flows()->firstOrFail();

        $response->assertRedirect(route('flows.builder', ['flow' => $flow, 'generate' => 1]));
        $this->assertSame('Weekly Report', $flow->name);
        // No agents are created at this stage — they are built in the graph.
        $this->assertSame(0, $flow->agents()->count());
    }

    private function createCompany(): Company
    {
        return Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);
    }
}
