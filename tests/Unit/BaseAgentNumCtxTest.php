<?php

namespace Tests\Unit;

use App\Agents\BaseAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Mockery;
use Tests\TestCase;

class BaseAgentNumCtxTest extends TestCase
{
    public function test_build_options_passes_num_ctx_from_config(): void
    {
        // num_ctx must reach Ollama — without it, large inputs are truncated to the
        // tiny default context window and the model hallucinates (run 67 root cause).
        $ollama = Mockery::mock(OllamaService::class);

        $agent = new class($ollama) extends BaseAgent
        {
            public function run(Agent $agent, AgentRun $agentRun, array $context): string
            {
                return '';
            }

            /** @return array<string,mixed> */
            public function exposeOptions(Agent $a): array
            {
                return $this->buildOptions($a);
            }
        };

        $model = new Agent(['config' => ['num_ctx' => 16384, 'temperature' => 0.3]]);
        $opts  = $agent->exposeOptions($model);

        $this->assertArrayHasKey('num_ctx', $opts);
        $this->assertEquals(16384, $opts['num_ctx']);
    }
}
