<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentEditParametersHelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_edit_form_explains_output_role_options(): void
    {
        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Competition Research',
            'description' => 'Research competitors',
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

        $response = $this->get(route('agents.edit', [$flow, $agent]));

        $response->assertOk();
        $response->assertSee('Определя къде ще се появи резултатът от този агент във финалния output.', false);
        $response->assertSee('Авто от тип: използва ролята по подразбиране за типа агент.', false);
        $response->assertSee('Основно съдържание: главният видим резултат.', false);
        $response->assertSee('Добавка: добавя се след основния текст.', false);
        $response->assertSee('Скрит: използва се като междинен контекст.', false);
    }

    public function test_agent_edit_parameters_tab_explains_model_options(): void
    {
        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Competition Research',
            'description' => 'Research competitors',
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
            'config' => [
                'temperature' => 0.3,
                'top_p' => 0.9,
                'top_k' => 40,
                'repeat_penalty' => 1.1,
                'num_predict' => 1000,
            ],
        ]);

        $response = $this->get(route('agents.edit', [$flow, $agent]));

        $response->assertOk();
        $response->assertSee('Как да мислим за тези параметри', false);
        $response->assertSee('Ниско: по-предвидими и повторяеми отговори', false);
        $response->assertSee('Високо: повече разнообразие', false);
        $response->assertSee('Top P избира най-вероятните токени', false);
        $response->assertSee('Top K поставя твърд лимит', false);
        $response->assertSee('Repeat Penalty наказва вече използвани думи', false);
        $response->assertSee('num_predict задава горна граница', false);
    }
}
