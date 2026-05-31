<?php

namespace Tests\Unit;

use App\Agents\ContentAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use RuntimeException;
use Tests\TestCase;

class BaseAgentOutputGuardTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $agent = new Agent();
        $agent->role = 'Report writer';
        $agent->model = 'qwen3:latest';
        $agent->output_language = 'bg';
        $agent->config = ['temperature' => 0.2];

        return $agent;
    }

    private function makeAgentRun(): AgentRun
    {
        $run = new AgentRun();
        $run->input = 'Напиши конкурентен доклад.';

        return $run;
    }

    public function test_adds_direct_user_output_requirements_to_system_prompt(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt, $userMessage, $options) {
                return str_contains($systemPrompt, 'Do not include hidden reasoning, chain-of-thought, planning notes, or <think> blocks.')
                    && str_contains($systemPrompt, 'Speak directly to the end user; do not refer to them in the third person.')
                    && str_contains($systemPrompt, 'Never write phrases like "the user wants", "I need to help the user", "той иска", "потребителят иска", or "трябва да помогна на потребителя".');
            })
            ->andReturn('Директен отговор.');

        $agent = new ContentAgent($ollama);

        $this->assertSame('Директен отговор.', $agent->run($this->makeAgent(), $this->makeAgentRun(), []));
    }

    public function test_strips_closed_think_blocks_from_model_output(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->andReturn("<think>\nТрябва да помогна на потребителя.\n</think>\n\n# Доклад\nДиректен отговор.");

        $agent = new ContentAgent($ollama);

        $result = $agent->run($this->makeAgent(), $this->makeAgentRun(), []);

        $this->assertSame("# Доклад\nДиректен отговор.", $result);
        $this->assertStringNotContainsString('<think>', $result);
        $this->assertStringNotContainsString('потребителя', $result);
    }

    public function test_rejects_output_that_is_only_unclosed_hidden_reasoning(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->andReturn('<think>Хей, трябва да помогна на потребителя да събере цени.');

        $agent = new ContentAgent($ollama);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Model returned hidden reasoning instead of a user-facing response.');

        $agent->run($this->makeAgent(), $this->makeAgentRun(), []);
    }
}
