<?php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use App\Models\LlmModel;
use Database\Seeders\AgentTemplateSeeder;
use Database\Seeders\LlmModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelCurationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_llm_model_seeder_includes_bulgarian_curation_models(): void
    {
        $this->seed(LlmModelSeeder::class);

        $bgptLight = LlmModel::where('ollama_tag', 's_emanuilov/BgGPT-v1.0:2.6b')->firstOrFail();
        $this->assertSame('qa', $bgptLight->category);
        $this->assertContains('qa_verifier', $bgptLight->is_default_for);
        $this->assertLessThanOrEqual(40, (float) $bgptLight->ram_required_gb);

        $bgptPremium = LlmModel::where('ollama_tag', 's_emanuilov/BgGPT-v1.0:27b')->firstOrFail();
        $this->assertSame('bulgarian', $bgptPremium->category);
        $this->assertContains('premium_bulgarian', $bgptPremium->strengths);
        $this->assertLessThanOrEqual(40, (float) $bgptPremium->ram_required_gb);

        $bgpt3 = LlmModel::where('ollama_tag', 'todorov/bggpt')->firstOrFail();
        $this->assertContains('multimodal', $bgpt3->strengths);
        $this->assertNotContains('image_describer', $bgpt3->is_default_for);

        $qwenVision = LlmModel::where('ollama_tag', 'qwen2.5vl:7b')->firstOrFail();
        $this->assertContains('image_describer', $qwenVision->is_default_for);

        $reasoning = LlmModel::where('ollama_tag', 'qwq:32b')->firstOrFail();
        $this->assertSame('reasoning', $reasoning->category);
        $this->assertContains('reasoning', $reasoning->strengths);
        $this->assertLessThanOrEqual(40, (float) $reasoning->ram_required_gb);

        $llavaFallback = LlmModel::where('ollama_tag', 'llava:7b')->firstOrFail();
        $this->assertSame('vision', $llavaFallback->category);
    }

    public function test_agent_template_seeder_sets_recommended_model_defaults(): void
    {
        AgentTemplate::create([
            'company_id' => null,
            'name' => 'Анализатор',
            'description' => 'Old',
            'icon' => '📊',
            'type' => 'analyzer',
            'model' => '',
        ]);

        $this->seed(AgentTemplateSeeder::class);

        $this->assertDatabaseHas('agent_templates', [
            'company_id' => null,
            'name' => 'Анализатор',
            'model' => 'qwen2.5:14b',
        ]);

        $this->assertDatabaseHas('agent_templates', [
            'company_id' => null,
            'name' => 'Съдържание BG',
            'model' => 's_emanuilov/BgGPT-v1.0:9b',
        ]);

        $this->assertDatabaseHas('agent_templates', [
            'company_id' => null,
            'name' => 'Описател на изображения',
            'model' => 'qwen2.5vl:7b',
        ]);
    }
}
