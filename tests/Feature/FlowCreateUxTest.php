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

    public function test_create_page_exposes_manual_agent_creation_and_advanced_inline_controls(): void
    {
        $company = $this->createCompany();

        $content = $this->get(route('companies.flows.create', $company))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('@click="openAgentPicker"', $content);
        $this->assertStringContainsString('Добави агент ръчно', $content);
        $this->assertStringContainsString('Разширени настройки', $content);
        $this->assertStringContainsString('Език на изхода', $content);
        $this->assertStringContainsString('agent.output_language', $content);
        $this->assertStringContainsString('Как да мислим за тези параметри', $content);
        $this->assertStringContainsString('Top P избира най-вероятните токени', $content);
        $this->assertStringContainsString('Repeat Penalty наказва вече използвани думи', $content);
        $this->assertStringContainsString('text-gray-500 text-xs leading-5', $content);
        $this->assertStringNotContainsString('text-gray-500 text-[10px] leading-4', $content);
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

    public function test_create_page_shows_bulgarian_output_preference_labels_with_stable_values(): void
    {
        $company = $this->createCompany();

        $content = $this->get(route('companies.flows.create', $company))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<option value="Analytical">Аналитичен</option>', $content);
        $this->assertStringContainsString('<option value="Technical">Технически</option>', $content);
        $this->assertStringContainsString('<option value="Report">Доклад</option>', $content);
        $this->assertMatchesRegularExpression('/<option value="Warm"\\s*>\\s*Топъл\\s*<\\/option>/', $content);
        $this->assertMatchesRegularExpression('/<option value="How-to guide"\\s*>\\s*Практическо ръководство\\s*<\\/option>/', $content);
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

    public function test_store_persists_output_preferences_and_advanced_config_from_create_form(): void
    {
        $company = $this->createCompany();

        $this->post(route('companies.flows.store', $company), [
            'name' => 'Weekly Report',
            'description' => 'Create a weekly market report.',
            'status' => 'draft',
            'agents' => [
                [
                    '_uid' => 'agent-1',
                    'name' => 'Researcher',
                    'type' => 'content_bg',
                    'role' => 'Research market signals.',
                    'system_prompt' => 'You research market signals.',
                    'prompt_template' => 'Research {{topic}}.',
                    'model' => 'qwen2.5:14b',
                    'order' => 1,
                    'output_language' => 'en',
                    'output_tone' => 'Analytical',
                    'output_style' => 'Technical',
                    'output_format' => 'Report',
                    'config' => [
                        'temperature' => 0.25,
                        'top_p' => 0.8,
                        'top_k' => 50,
                        'repeat_penalty' => 1.15,
                        'num_predict' => 1200,
                        'qa' => [
                            'enabled' => false,
                        ],
                    ],
                ],
            ],
        ])->assertRedirect();

        $agent = $company->flows()->firstOrFail()->agents()->firstOrFail();

        $this->assertSame('en', $agent->output_language);
        $this->assertSame('Analytical', $agent->output_tone);
        $this->assertSame('Technical', $agent->output_style);
        $this->assertSame('Report', $agent->output_format);
        $this->assertSame(0.25, $agent->config['temperature']);
        $this->assertSame(0.8, $agent->config['top_p']);
        $this->assertSame(50, $agent->config['top_k']);
        $this->assertSame(1.15, $agent->config['repeat_penalty']);
        $this->assertSame(1200, $agent->config['num_predict']);
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
