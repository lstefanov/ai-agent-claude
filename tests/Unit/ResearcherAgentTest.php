<?php

namespace Tests\Unit;

use App\Agents\ResearcherAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Tests\TestCase;

class ResearcherAgentTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $agent                  = new Agent();
        $agent->role            = 'Researcher role';
        $agent->model           = 'gemma2:9b';
        $agent->output_language = 'bg';
        $agent->config          = ['temperature' => 0.3];
        return $agent;
    }

    private function makeAgentRun(string $input): AgentRun
    {
        $run        = new AgentRun();
        $run->input = $input;
        return $run;
    }

    public function test_injects_search_results_into_system_prompt(): void
    {
        $searchResults = "[1] Title: IGN\n    URL: https://ign.com\n    Summary: Gaming news";

        $webSearchTool = \Mockery::mock(AgentTool::class);
        $webSearchTool->shouldReceive('name')->andReturn('web_search');
        $webSearchTool->shouldReceive('execute')
            ->once()
            ->with(['query' => 'video games'])
            ->andReturn($searchResults);

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt, $userMessage, $options) use ($searchResults) {
                return str_contains($systemPrompt, 'WEB SEARCH RESULTS')
                    && str_contains($systemPrompt, $searchResults);
            })
            ->andReturn('Research output');

        $researcher = new ResearcherAgent($ollama, [$webSearchTool]);
        $result     = $researcher->run(
            $this->makeAgent(),
            $this->makeAgentRun('Изследвай игровите тенденции свързани с video games'),
            ['flow_topic' => 'video games', 'topic' => 'video games']
        );

        $this->assertSame('Research output', $result);
    }

    public function test_uses_input_as_query_when_topic_is_empty(): void
    {
        $webSearchTool = \Mockery::mock(AgentTool::class);
        $webSearchTool->shouldReceive('name')->andReturn('web_search');
        $webSearchTool->shouldReceive('execute')
            ->once()
            ->withArgs(fn($params) => str_starts_with($params['query'], 'Изследвай'))
            ->andReturn('some results');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('output');

        $researcher = new ResearcherAgent($ollama, [$webSearchTool]);
        $researcher->run(
            $this->makeAgent(),
            $this->makeAgentRun('Изследвай игровите тенденции'),
            ['flow_topic' => '', 'topic' => '']
        );
    }

    public function test_continues_without_search_when_no_tool_registered(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt) {
                return ! str_contains($systemPrompt, 'WEB SEARCH RESULTS');
            })
            ->andReturn('output');

        $researcher = new ResearcherAgent($ollama, []);
        $result     = $researcher->run(
            $this->makeAgent(),
            $this->makeAgentRun('some input'),
            ['flow_topic' => 'video games', 'topic' => 'video games']
        );

        $this->assertSame('output', $result);
    }
}
