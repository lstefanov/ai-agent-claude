<?php

namespace Tests\Unit;

use App\Models\LlmModel;
use App\Services\ModelSelectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelSelectorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ModelSelectorService $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = new ModelSelectorService;
    }

    private function install(string ...$tags): void
    {
        foreach ($tags as $tag) {
            LlmModel::create([
                'ollama_tag' => $tag,
                'display_name' => $tag,
                'category' => 'test',
                'description' => 'test model',
                'is_available' => true,
                'is_enabled' => true,
            ]);
        }
    }

    public function test_select_model_returns_ideal_per_type_even_if_not_installed(): void
    {
        // No models installed — selectModel still returns the ideal candidate.
        $this->assertSame('mistral-nemo', $this->selector->selectModel('deep_researcher'));
        $this->assertSame('qwen2.5:14b', $this->selector->selectModel('analyzer'));
        $this->assertSame('todorov/bggpt', $this->selector->selectModel('report_writer'));
        $this->assertSame('s_emanuilov/BgGPT-v1.0:2.6b', $this->selector->selectModel('qa_verifier'));
    }

    public function test_unknown_type_falls_back_to_profile_from_output_role(): void
    {
        // press_release_writer has output_role=body in config → bg_writer profile.
        $this->assertSame('todorov/bggpt', $this->selector->selectModel('press_release_writer'));
        // hashtag_generator has output_role=appendix → utility profile.
        $this->assertSame('mistral', $this->selector->selectModel('hashtag_generator'));
    }

    public function test_description_hint_overrides_profile(): void
    {
        $this->assertSame('qwen2.5vl:7b', $this->selector->selectModel('researcher', 'Описател на изображения'));
        $this->assertSame('aya-expanse:8b', $this->selector->selectModel('writer', 'превод на текст между езици'));
        $this->assertSame('qwen2.5-coder:7b', $this->selector->selectModel('writer', 'генерира програмен код'));
    }

    public function test_resolve_runnable_picks_first_installed_candidate(): void
    {
        // Ideal research model (mistral-nemo) not installed, but a later candidate is.
        $this->install('qwen2.5:7b');

        $this->assertSame('qwen2.5:7b', $this->selector->resolveRunnable('deep_researcher'));
    }

    public function test_resolve_runnable_never_returns_uninstalled_tag(): void
    {
        // Only 'mistral' is installed; 'code' profile has no installed candidate →
        // global fallback must still be an installed tag.
        $this->install('mistral');

        $result = $this->selector->resolveRunnable('code');

        $this->assertSame('mistral', $result);
        $this->assertContains($result, $this->selector->installedTags());
    }
}
