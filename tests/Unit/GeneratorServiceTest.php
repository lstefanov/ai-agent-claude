<?php

namespace Tests\Unit;

use App\Services\GeneratorService;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeneratorServiceTest extends TestCase
{
    private function make(): GeneratorService
    {
        return new GeneratorService(\Mockery::mock(OllamaService::class));
    }

    public function test_anthropic_provider_posts_to_messages_and_returns_text(): void
    {
        config([
            'services.generator.provider' => 'anthropic',
            'services.anthropic.api_key'  => 'sk-ant-test',
            'services.anthropic.model'    => 'claude-sonnet-4-6',
            'services.anthropic.base_url' => 'https://api.anthropic.com',
            'services.anthropic.version'  => '2023-06-01',
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'hello from claude']],
            ]),
        ]);

        $out = $this->make()->chat('sys', 'user msg', ['temperature' => 0.2, 'num_predict' => 4000]);

        $this->assertSame('hello from claude', $out);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'sk-ant-test')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-sonnet-4-6'
                && $request['system'] === 'sys'
                && $request['max_tokens'] === 4000
                && $request['messages'][0]['content'] === 'user msg';
        });
    }

    public function test_openai_provider_posts_to_chat_completions_and_returns_content(): void
    {
        config([
            'services.generator.provider' => 'openai',
            'services.openai.api_key'  => 'sk-openai-test',
            'services.openai.model'    => 'gpt-4o',
            'services.openai.base_url' => 'https://api.openai.com',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'hello from gpt']]],
            ]),
        ]);

        $out = $this->make()->chat('sys', 'user msg', ['temperature' => 0.3, 'num_predict' => 800]);

        $this->assertSame('hello from gpt', $out);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-openai-test')
                && $request['model'] === 'gpt-4o'
                && $request['max_tokens'] === 800
                && $request['messages'][0]['role'] === 'system'
                && $request['messages'][1]['content'] === 'user msg';
        });
    }

    public function test_is_available_reflects_api_key_presence_for_external_providers(): void
    {
        config(['services.generator.provider' => 'anthropic', 'services.anthropic.api_key' => null]);
        $this->assertFalse($this->make()->isAvailable());

        config(['services.anthropic.api_key' => 'sk-ant-test']);
        $this->assertTrue($this->make()->isAvailable());
    }

    public function test_ollama_provider_delegates_to_ollama_service(): void
    {
        config(['services.generator.provider' => 'ollama']);

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('from ollama');
        $ollama->shouldReceive('isAvailable')->once()->andReturnTrue();

        $service = new GeneratorService($ollama);

        $this->assertSame('from ollama', $service->chat('sys', 'user'));
        $this->assertTrue($service->isAvailable());
    }
}
