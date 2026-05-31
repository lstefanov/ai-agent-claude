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
    private string $logFile = '';

    public function __construct(private AgentFactory $factory) {}

    public function run(Flow $flow, string $triggeredBy = 'manual', ?FlowRun $flowRun = null): FlowRun
    {
        if ($flowRun) {
            $flowRun->update([
                'status'     => 'running',
                'started_at' => now(),
            ]);
        } else {
            $flowRun = FlowRun::create([
                'flow_id'      => $flow->id,
                'status'       => 'running',
                'triggered_by' => $triggeredBy,
                'started_at'   => now(),
            ]);
        }

        // Set up dedicated log file for this run
        $this->logFile = storage_path("logs/run-{$flowRun->id}.log");
        $this->log('═══════════════════════════════════════════════════════════');
        $this->log("FLOW RUN #{$flowRun->id} STARTED");
        $this->log("Flow:        {$flow->name} (ID: {$flow->id})");
        $this->log("Triggered:   {$triggeredBy}");
        $this->log("Started at:  " . now()->format('Y-m-d H:i:s'));
        $this->log('═══════════════════════════════════════════════════════════');

        // Pre-seed context with company variables so {{company_description}} etc. resolve
        $company = $flow->company;
        $context = [
            'company_description' => $company?->description ?? '',
            'company_name'        => $company?->name ?? '',
            'company_industry'    => $company?->industry ?? '',
            'input'               => $flow->topic ?? '',
            'topic'               => $flow->topic ?? '',
            'flow_topic'          => $flow->topic ?? '',  // preserved across all agent steps — never overwritten
        ];

        $agents = $flow->agents()
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        $totalAgents = $agents->count();
        $this->log("Agents to execute: {$totalAgents}");
        foreach ($agents as $i => $a) {
            $this->log("  " . ($i + 1) . ". {$a->name} ({$a->type}) → model: {$a->model}");
        }
        $this->log('');

        foreach ($agents as $agentIndex => $agent) {
            $step   = $agentIndex + 1;
            $input  = $this->buildInput($agent, $context);

            $this->log("───────────────────────────────────────────────────────────");
            $this->log("STEP {$step}/{$totalAgents}: {$agent->name}");
            $this->log("Type:  {$agent->type}");
            $this->log("Model: {$agent->model}");
            $this->log("Input ({$this->charCount($input)} chars):");
            $this->log($this->indent($this->truncateLog($input, 800)));

            $agentRun = AgentRun::create([
                'flow_run_id' => $flowRun->id,
                'agent_id'    => $agent->id,
                'status'      => 'running',
                'model_used'  => $agent->model,
                'input'       => $input,
                'started_at'  => now(),
            ]);

            // ── Execute with up to 3 retry attempts ───────────────────────
            $maxAttempts   = 3;
            $lastError     = null;
            $output        = null;
            $agentInstance = null;
            $startMs       = now()->valueOf();

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    if ($attempt > 1) {
                        $this->log("↻ RETRY attempt {$attempt}/{$maxAttempts} (previous: " . $lastError->getMessage() . ')');
                        $agentRun->update(['status' => 'running', 'error' => null]);
                        sleep(2);
                    }

                    $agentInstance = $this->factory->make($agent);
                    $output        = $agentInstance->run($agent, $agentRun, $context);
                    $lastError     = null;
                    break; // success

                } catch (Throwable $e) {
                    $lastError = $e;
                    $this->log("  Attempt {$attempt} failed: " . $e->getMessage());
                }
            }

            $durationMs  = now()->valueOf() - $startMs;
            $durationStr = $this->formatDuration($durationMs);

            if ($lastError !== null) {
                // All attempts exhausted
                $agentRun->update([
                    'status'       => 'failed',
                    'error'        => "Failed after {$maxAttempts} attempts. Last error: " . $lastError->getMessage(),
                    'duration_ms'  => $durationMs,
                    'completed_at' => now(),
                ]);

                $this->log("Status:   ✗ FAILED after {$maxAttempts} attempts in {$durationStr}");
                $this->log("Last error: " . $lastError->getMessage());
                $this->log("File:       " . $lastError->getFile() . ':' . $lastError->getLine());
                $this->log("Trace:");
                $this->log($this->indent(collect(explode("\n", $lastError->getTraceAsString()))->take(10)->implode("\n")));

                $flowRun->update(['status' => 'failed', 'completed_at' => now()]);
                $this->logRunEnd($flowRun, 'failed');

                return $flowRun;
            }

            // ── Successful output ──────────────────────────────────────────
            $agentRun->update([
                'status'       => 'completed',
                'output'       => $output,
                'duration_ms'  => $durationMs,
                'completed_at' => now(),
            ]);

            $context[$agent->name] = $output;
            $context['input']      = $output;   // {{input}} in next agent's template
            $context['topic']      = $output;   // {{topic}} in next agent's template

            $this->log("Status:   ✓ COMPLETED in {$durationStr}");
            $this->log("Output ({$this->charCount($output)} chars):");
            $this->log($this->indent($this->truncateLog($output, 1200)));

            // QA gate
            if ($agent->is_verifier && $agent->qa_threshold !== null) {
                $score = $agentInstance instanceof QaVerifierAgent
                    ? $agentInstance->extractScore($agentRun)
                    : $this->extractScoreFromOutput($output);

                $this->log("QA Score: {$score} / threshold: {$agent->qa_threshold}");

                if ($score < $agent->qa_threshold) {
                    $this->log("QA GATE: ✗ FAILED — score {$score} < threshold {$agent->qa_threshold}");
                    $this->logRunEnd($flowRun, 'failed');

                    $flowRun->update(['status' => 'failed', 'completed_at' => now()]);
                    return $flowRun;
                }

                $this->log("QA GATE: ✓ PASSED — score {$score} ≥ threshold {$agent->qa_threshold}");
            }

            $this->log('');
        }

        $flowRun->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $flow->update(['last_run_at' => now()]);

        $this->logRunEnd($flowRun, 'completed');

        return $flowRun;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Build the input message for an agent.
    // ──────────────────────────────────────────────────────────────────────
    // Keys that are system/alias variables, not agent outputs
    private const SYSTEM_CONTEXT_KEYS = ['company_description', 'company_name', 'company_industry', 'input', 'topic'];

    private function buildInput(object $agent, array $context): string
    {
        $prompt = $agent->prompt_template ?? '';

        // Replace {{variable}} and {variable} placeholders
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
                $prompt = str_replace('{' . $key . '}',   $value, $prompt);
            }
        }

        // Always append outputs from previous agents as an explicit context section
        $agentOutputs = array_filter(
            $context,
            fn ($k) => ! in_array($k, self::SYSTEM_CONTEXT_KEYS, true),
            ARRAY_FILTER_USE_KEY
        );

        if (! empty($agentOutputs)) {
            $lines = [];
            foreach ($agentOutputs as $agentName => $output) {
                if (is_string($output) && $output !== '') {
                    $lines[] = "[{$agentName}]:\n" . mb_substr($output, 0, 1000);
                }
            }
            if ($lines) {
                $prompt .= "\n\n--- Context from previous agents ---\n" . implode("\n\n", $lines);
            }
        }

        return $prompt;
    }

    private function extractScoreFromOutput(string $output): int
    {
        preg_match('/\b(\d{1,3})\b/', $output, $matches);
        return isset($matches[1]) ? min(100, max(0, (int) $matches[1])) : 0;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Logging helpers
    // ──────────────────────────────────────────────────────────────────────
    private function log(string $message): void
    {
        if (! $this->logFile) {
            return;
        }
        $ts = date('[H:i:s]');
        file_put_contents($this->logFile, "{$ts} {$message}\n", FILE_APPEND | LOCK_EX);
    }

    private function logRunEnd(FlowRun $flowRun, string $status, ?int $startedMs = null): void
    {
        $this->log('═══════════════════════════════════════════════════════════');
        $this->log("FLOW RUN #{$flowRun->id} " . strtoupper($status));
        $this->log("Finished at: " . now()->format('Y-m-d H:i:s'));
        if ($flowRun->started_at) {
            $elapsed = now()->diffInSeconds($flowRun->started_at);
            $this->log("Total time:  " . $this->formatDuration($elapsed * 1000));
        }
        $this->log('═══════════════════════════════════════════════════════════');
    }

    private function indent(string $text, string $prefix = '    '): string
    {
        return $prefix . str_replace("\n", "\n{$prefix}", $text);
    }

    private function truncateLog(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $half = (int) ($maxChars / 2);
        return mb_substr($text, 0, $half)
            . "\n... [" . (mb_strlen($text) - $maxChars) . " chars truncated] ...\n"
            . mb_substr($text, -$half);
    }

    private function charCount(string $text): string
    {
        return number_format(mb_strlen($text));
    }

    private function formatDuration(int $ms): string
    {
        $s = round($ms / 1000, 1);
        if ($s < 60) {
            return "{$s}с";
        }
        $m = floor($s / 60);
        $r = round($s - $m * 60, 1);
        return "{$m}м {$r}с";
    }
}
