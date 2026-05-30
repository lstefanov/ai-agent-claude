<?php
// tests/Unit/Agents/EmailAgentTest.php

namespace Tests\Unit\Agents;

use App\Agents\EmailAgent;
use App\Mail\FlowRunReport;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Flow;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailAgentTest extends TestCase
{
    private function makeEmailAgent(string $ollamaResponse): EmailAgent
    {
        $ollama = $this->createMock(OllamaService::class);
        $ollama->method('chat')->willReturn($ollamaResponse);

        return new EmailAgent($ollama);
    }

    private function makeAgent(string $flowDescription): Agent
    {
        $flow = new Flow(['name' => 'Test Flow', 'description' => $flowDescription]);
        $agent = new Agent(['name' => 'Email Agent', 'type' => 'email', 'model' => 'mistral']);
        $agent->setRelation('flow', $flow);
        return $agent;
    }

    public function test_sends_email_when_address_found(): void
    {
        Mail::fake();

        $agent    = $this->makeAgent('Изпрати репорта на boss@company.bg');
        $agentRun = new AgentRun(['input' => '## Репорт', 'flow_run_id' => 0]);
        $emailAgent = $this->makeEmailAgent('boss@company.bg');

        $context = ['input' => '## Репорт от ContentAgent'];
        $result = $emailAgent->run($agent, $agentRun, $context);

        Mail::assertSent(FlowRunReport::class, fn ($m) =>
            $m->hasTo('boss@company.bg')
            && $m->flowRunId === 0
            && $m->reportContent === '## Репорт от ContentAgent'
            && $m->flowName === 'Test Flow'
        );
        $this->assertStringContainsString('boss@company.bg', $result);
    }

    public function test_returns_soft_warning_when_no_email(): void
    {
        Mail::fake();

        $agent      = $this->makeAgent('Без имейл в описанието.');
        $agentRun   = new AgentRun(['input' => '## Репорт', 'flow_run_id' => 0]);
        $emailAgent = $this->makeEmailAgent('no email here');

        $result = $emailAgent->run($agent, $agentRun, []);

        Mail::assertNothingSent();
        $this->assertStringContainsString('Не е намерен', $result);
    }
}
