<?php

namespace Tests\Feature;

use App\Models\LlmModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmModelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_test_command_uses_bounded_generation_options_and_caches_result(): void
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::response([
                'message' => ['content' => 'OK'],
            ]),
        ]);

        $model = LlmModel::create([
            'ollama_tag' => 'qwen2.5:14b',
            'display_name' => 'Qwen2.5 14B',
            'category' => 'json',
            'description' => 'Test model',
            'ram_required_gb' => 9.0,
            'is_available' => true,
            'is_enabled' => true,
        ]);

        $exitCode = Artisan::call('models:test', ['model' => $model->id]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'status' => 'completed',
            'success' => true,
            'response' => 'OK',
        ], collect(Cache::get("llm_model_test_{$model->id}"))->only(['status', 'success', 'response'])->all());

        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:11434/api/chat'
            && $request['model'] === 'qwen2.5:14b'
            && ($request['options']['temperature'] ?? null) === 0
            && ($request['options']['num_predict'] ?? null) === 16);
    }

    public function test_model_test_endpoint_starts_background_test_and_status_endpoint_returns_cache_result(): void
    {
        $model = LlmModel::create([
            'ollama_tag' => 'qwen2.5:14b',
            'display_name' => 'Qwen2.5 14B',
            'category' => 'json',
            'description' => 'Test model',
            'ram_required_gb' => 9.0,
            'is_available' => true,
            'is_enabled' => true,
        ]);

        $this->postJson(route('models.test', $model))
            ->assertOk()
            ->assertJson([
                'status' => 'testing',
            ]);

        Cache::put("llm_model_test_{$model->id}", [
            'status' => 'completed',
            'success' => true,
            'response' => 'OK',
            'elapsed_ms' => 123,
        ], now()->addMinutes(10));

        $this->getJson(route('models.test.status', $model))
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'success' => true,
                'response' => 'OK',
                'elapsed_ms' => 123,
            ]);
    }

    public function test_models_view_polls_test_status(): void
    {
        $view = file_get_contents(resource_path('views/models/index.blade.php'));

        $this->assertStringContainsString("'Accept': 'application/json'", $view);
        $this->assertStringContainsString('pollTestStatus()', $view);
        $this->assertStringContainsString('`/models/${this.id}/test/status`', $view);
    }
}
