<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Agents\QaVerifierAgent;
use App\Models\AgentRun;
use App\Models\Flow;
use App\Models\FlowRun;
use Throwable;

class FlowExecutorService
{
    public function __construct(private AgentFactory $factory) {}

    public function run(Flow $flow, string $triggeredBy = 'manual'): FlowRun
    {
        $flowRun = FlowRun::create([
            'flow_id'      => $flow->id,
            'status'       => 'running',
            'triggered_by' => $triggeredBy,
            'started_at'   => now(),
        ]);

        $context = [];

        $agents = $flow->agents()
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        foreach ($agents as $agent) {
            $input = $this->buildInput($agent, $context);

            $agentRun = AgentRun::create([
                'flow_run_id' => $flowRun->id,
                'agent_id'    => $agent->id,
                'status'      => 'running',
                'model_used'  => $agent->model,
                'input'       => $input,
                'started_at'  => now(),
            ]);

            try {
                $startMs = now()->valueOf();

                $agentInstance = $this->factory->make($agent);
                $output = $agentInstance->run($agent, $agentRun, $context);

                $durationMs = now()->valueOf() - $startMs;

                $agentRun->update([
                    'status'      => 'completed',
                    'output'      => $output,
                    'duration_ms' => $durationMs,
                    'completed_at' => now(),
                ]);

                $context[$agent->name] = $output;

                // QA gate: stop flow if score below threshold
                if ($agent->is_verifier && $agent->qa_threshold !== null) {
                    $score = $agentInstance instanceof QaVerifierAgent
                        ? $agentInstance->extractScore($agentRun)
                        : $this->extractScoreFromOutput($output);

                    if ($score < $agent->qa_threshold) {
                        $flowRun->update([
                            'status'       => 'failed',
                            'completed_at' => now(),
                        ]);
                        return $flowRun;
                    }
                }

            } catch (Throwable $e) {
                $agentRun->update([
                    'status'      => 'failed',
                    'error'       => $e->getMessage(),
                    'completed_at' => now(),
                ]);

                $flowRun->update([
                    'status'       => 'failed',
                    'completed_at' => now(),
                ]);

                return $flowRun;
            }
        }

        $flowRun->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $flow->update(['last_run_at' => now()]);

        return $flowRun;
    }

    private function buildInput(object $agent, array $context): string
    {
        if (empty($context)) {
            return $agent->prompt_template;
        }

        $lines = [];
        foreach ($context as $agentName => $output) {
            $lines[] = "[{$agentName}]: " . mb_substr($output, 0, 500);
        }
        return implode("\n\n", $lines);
    }

    private function extractScoreFromOutput(string $output): int
    {
        preg_match('/\b(\d{1,3})\b/', $output, $matches);
        return isset($matches[1]) ? min(100, max(0, (int) $matches[1])) : 0;
    }
}
