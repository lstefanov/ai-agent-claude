<?php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        session(['admin_authenticated' => true]);
    }

    public function test_admin_login_page_loads(): void
    {
        $this->get(route('admin.login'))->assertOk();
    }

    public function test_admin_login_redirects_with_correct_password(): void
    {
        config(['app.admin_password' => 'secret']);

        $this->post(route('admin.login.post'), ['password' => 'secret'])
            ->assertRedirect(route('admin.agent-templates.index'));
    }

    public function test_admin_login_fails_with_wrong_password(): void
    {
        config(['app.admin_password' => 'secret']);

        $this->post(route('admin.login.post'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    public function test_admin_index_requires_auth(): void
    {
        $this->get(route('admin.agent-templates.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_index_lists_system_templates(): void
    {
        $this->actAsAdmin();
        AgentTemplate::create([
            'company_id' => null, 'name' => 'Тест Шаблон',
            'description' => 'Описание', 'icon' => '🤖', 'type' => 'analyzer',
        ]);

        $this->get(route('admin.agent-templates.index'))
            ->assertOk()
            ->assertSee('Тест Шаблон');
    }

    public function test_admin_can_create_system_template(): void
    {
        $this->actAsAdmin();

        $this->post(route('admin.agent-templates.store'), [
            'name' => 'Нов Шаблон', 'description' => 'Описание', 'icon' => '🆕',
            'type' => 'summarizer', 'sort_order' => 99,
        ])->assertRedirect(route('admin.agent-templates.index'));

        $this->assertDatabaseHas('agent_templates', ['name' => 'Нов Шаблон', 'company_id' => null]);
    }

    public function test_admin_can_delete_system_template(): void
    {
        $this->actAsAdmin();
        $template = AgentTemplate::create([
            'company_id' => null, 'name' => 'За Изтриване',
            'description' => 'x', 'icon' => '🗑', 'type' => 'decision',
        ]);

        $this->delete(route('admin.agent-templates.destroy', $template))
            ->assertRedirect(route('admin.agent-templates.index'));

        $this->assertDatabaseMissing('agent_templates', ['id' => $template->id]);
    }
}
