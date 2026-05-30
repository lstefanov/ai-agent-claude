<?php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTemplatePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_returns_system_and_company_templates(): void
    {
        $company = Company::create([
            'name' => 'Test Co', 'description' => '', 'industry' => 'IT', 'language' => 'bg',
        ]);

        AgentTemplate::create([
            'company_id' => null,
            'name' => 'Email Изпращач', 'description' => 'Изпраща имейл', 'icon' => '📧',
            'type' => 'email', 'sort_order' => 1,
        ]);

        AgentTemplate::create([
            'company_id' => $company->id,
            'name' => 'Социален Пост', 'description' => 'FB пост', 'icon' => '💬',
            'type' => 'content_bg', 'sort_order' => 1,
        ]);

        $response = $this->getJson("/agent-templates/picker?company_id={$company->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'system')
            ->assertJsonCount(1, 'company')
            ->assertJsonPath('system.0.name', 'Email Изпращач')
            ->assertJsonPath('company.0.name', 'Социален Пост');
    }

    public function test_picker_excludes_other_company_templates(): void
    {
        $company1 = Company::create(['name' => 'Co1', 'description' => '', 'industry' => 'IT', 'language' => 'bg']);
        $company2 = Company::create(['name' => 'Co2', 'description' => '', 'industry' => 'IT', 'language' => 'bg']);

        AgentTemplate::create([
            'company_id' => $company2->id,
            'name' => 'Other Co Template', 'description' => 'x', 'icon' => '🔍',
            'type' => 'analyzer', 'sort_order' => 1,
        ]);

        $response = $this->getJson("/agent-templates/picker?company_id={$company1->id}");

        $response->assertOk()->assertJsonCount(0, 'company');
    }
}
