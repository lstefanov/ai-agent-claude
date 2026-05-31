<?php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiButtonUiTest extends TestCase
{
    use RefreshDatabase;

    private const AI_BUTTON_CLASS = 'inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition';

    private const OLD_AI_BUTTON_CLASS = 'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200';

    public function test_flow_create_and_edit_place_improve_button_above_description_textarea(): void
    {
        $company = $this->createCompany();
        $flow = $company->flows()->create([
            'name' => 'Web Game Search',
            'description' => 'Search for web games.',
            'status' => 'active',
        ]);

        foreach ([
            route('companies.flows.create', $company),
            route('flows.edit', $flow),
        ] as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            $improveButtonPosition = strpos($content, '@click="improveDescription"');
            $textareaPosition = strpos($content, '<textarea name="description"');

            $this->assertNotFalse($improveButtonPosition);
            $this->assertNotFalse($textareaPosition);
            $this->assertLessThan($textareaPosition, $improveButtonPosition);
            $this->assertStringContainsString(self::AI_BUTTON_CLASS, $content);
            $this->assertStringNotContainsString('absolute bottom-2 right-2', $content);
        }
    }

    public function test_ai_generate_buttons_use_the_solid_indigo_style_on_every_page(): void
    {
        $company = $this->createCompany();
        $flow = $company->flows()->create([
            'name' => 'Web Game Search',
            'description' => 'Search for web games.',
            'status' => 'active',
        ]);
        $agent = $flow->agents()->create([
            'name' => 'Researcher',
            'type' => 'content_bg',
            'role' => 'Research competitor data',
            'prompt_template' => 'Research {{topic}}',
            'model' => 'qwen2.5:14b',
            'order' => 1,
            'is_active' => true,
        ]);
        $companyTemplate = AgentTemplate::create([
            'company_id' => $company->id,
            'name' => 'Company Template',
            'description' => 'Company template description',
            'icon' => '🤖',
            'type' => 'content_bg',
        ]);
        $adminTemplate = AgentTemplate::create([
            'company_id' => null,
            'name' => 'Admin Template',
            'description' => 'Admin template description',
            'icon' => '🤖',
            'type' => 'content_bg',
        ]);

        $pages = [
            route('companies.flows.create', $company),
            route('flows.show', $flow),
            route('agents.edit', [$flow, $agent]),
            route('companies.agent-templates.create', $company),
            route('companies.agent-templates.edit', [$company, $companyTemplate]),
        ];

        foreach ($pages as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Генерирай с AI', $content);
            $this->assertStringContainsString(self::AI_BUTTON_CLASS, $content);
            $this->assertStringNotContainsString(self::OLD_AI_BUTTON_CLASS, $content);
        }

        session(['admin_authenticated' => true]);

        foreach ([
            route('admin.agent-templates.create'),
            route('admin.agent-templates.edit', $adminTemplate),
        ] as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Генерирай с AI', $content);
            $this->assertStringContainsString(self::AI_BUTTON_CLASS, $content);
            $this->assertStringNotContainsString(self::OLD_AI_BUTTON_CLASS, $content);
        }
    }

    private function createCompany(): Company
    {
        return Company::create([
            'name' => 'Спортен Център',
            'description' => 'Описание',
            'industry' => 'Sports',
            'language' => 'bg',
        ]);
    }
}
