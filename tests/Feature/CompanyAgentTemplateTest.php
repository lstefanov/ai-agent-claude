<?php
// tests/Feature/CompanyAgentTemplateTest.php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAgentTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'name' => 'Test Co', 'description' => '', 'industry' => 'IT', 'language' => 'bg',
        ]);
    }

    public function test_index_lists_company_templates(): void
    {
        AgentTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Мой Шаблон', 'description' => 'x', 'icon' => '💬', 'type' => 'content_bg',
        ]);

        $this->get(route('companies.agent-templates.index', $this->company))
            ->assertOk()
            ->assertSee('Мой Шаблон');
    }

    public function test_index_does_not_show_other_company_templates(): void
    {
        $other = Company::create(['name' => 'Other', 'description' => '', 'industry' => 'x', 'language' => 'en']);
        AgentTemplate::create([
            'company_id' => $other->id,
            'name' => 'Чужд Шаблон', 'description' => 'x', 'icon' => '🔍', 'type' => 'analyzer',
        ]);

        $this->get(route('companies.agent-templates.index', $this->company))
            ->assertOk()
            ->assertDontSee('Чужд Шаблон');
    }

    public function test_store_creates_company_template(): void
    {
        $this->post(route('companies.agent-templates.store', $this->company), [
            'name' => 'Нов Шаблон', 'description' => 'Описание', 'icon' => '🆕',
            'type' => 'summarizer', 'sort_order' => 1,
        ])->assertRedirect(route('companies.agent-templates.index', $this->company));

        $this->assertDatabaseHas('agent_templates', [
            'name' => 'Нов Шаблон',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_cannot_edit_other_company_template(): void
    {
        $other = Company::create(['name' => 'Other', 'description' => '', 'industry' => 'x', 'language' => 'en']);
        $template = AgentTemplate::create([
            'company_id' => $other->id,
            'name' => 'Чужд', 'description' => 'x', 'icon' => '🔍', 'type' => 'analyzer',
        ]);

        $this->get(route('companies.agent-templates.edit', [$this->company, $template]))
            ->assertForbidden();
    }

    public function test_destroy_deletes_own_template(): void
    {
        $template = AgentTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'За Изтриване', 'description' => 'x', 'icon' => '🗑', 'type' => 'decision',
        ]);

        $this->delete(route('companies.agent-templates.destroy', [$this->company, $template]))
            ->assertRedirect(route('companies.agent-templates.index', $this->company));

        $this->assertDatabaseMissing('agent_templates', ['id' => $template->id]);
    }
}
