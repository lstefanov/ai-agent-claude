<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Agents\QaVerifierAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Support\PricingOutputMetrics;
use App\Support\ReasoningStripper;
use Throwable;

class FlowExecutorService
{
    private string $logFile = '';

    public function __construct(private AgentFactory $factory) {}

    public function run(Flow $flow, string $triggeredBy = 'manual', ?FlowRun $flowRun = null): FlowRun
    {
        if ($flowRun) {
            $flowRun->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
        } else {
            $flowRun = FlowRun::create([
                'flow_id' => $flow->id,
                'status' => 'running',
                'triggered_by' => $triggeredBy,
                'started_at' => now(),
            ]);
        }

        // Set up dedicated log file for this run
        $this->logFile = storage_path("logs/run-{$flowRun->id}.log");
        $this->log('═══════════════════════════════════════════════════════════');
        $this->log("FLOW RUN #{$flowRun->id} STARTED");
        $this->log("Flow:        {$flow->name} (ID: {$flow->id})");
        $this->log("Triggered:   {$triggeredBy}");
        $this->log('Started at:  '.now()->format('Y-m-d H:i:s'));
        $this->log('═══════════════════════════════════════════════════════════');

        // Pre-seed context with company variables so {{company_description}} etc. resolve
        $company = $flow->company;
        $context = [
            'company_description' => $company?->description ?? '',
            'company_name' => $company?->name ?? '',
            'company_industry' => $company?->industry ?? '',
            'input' => $flow->topic ?? '',
            'topic' => $flow->topic ?? '',
            'flow_topic' => $flow->topic ?? '',  // preserved across all agent steps — never overwritten
        ];

        $agents = $flow->agents()
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        $this->ensureQaThresholdSnapshot($flowRun, $agents);
        $this->ensureStepQaPolicySnapshot($flowRun, $agents);
        $flowRun->refresh();

        $totalAgents = $agents->count();
        $this->log("Agents to execute: {$totalAgents}");
        foreach ($agents as $i => $a) {
            $this->log('  '.($i + 1).". {$a->name} ({$a->type}) → model: {$a->model}");
        }
        $this->log('');

        $referencedVerifierIds = $this->referencedStepQaVerifierIds($flowRun);

        foreach ($agents as $agentIndex => $agent) {
            if ($agent->is_verifier && in_array((int) $agent->id, $referencedVerifierIds, true)) {
                $this->log("Skipping standalone QA verifier {$agent->name}; it is used by a step QA policy.");

                continue;
            }

            $effectiveQaThreshold = null;
            if ($agent->is_verifier) {
                $flowRun->refresh();
                $effectiveQaThreshold = $this->effectiveQaThreshold($flowRun, $agent);
                $agent->qa_threshold = $effectiveQaThreshold;
            }

            $step = $agentIndex + 1;
            $contextBeforeStep = $context;
            $qaRetriesUsed = 0;

            while (true) {
                $execution = $this->executeAgent($flowRun, $agent, $context, $step, $totalAgents);

                if (! $execution['success']) {
                    $this->failFlowRun($flowRun, $execution['error_message']);

                    return $flowRun;
                }

                /** @var AgentRun $agentRun */
                $agentRun = $execution['run'];
                $agentInstance = $execution['instance'];
                $output = $execution['output'];

                $context = $this->mergeAgentOutputIntoContext($context, $agent, $output);

                // Legacy QA gate for verifier agents that are still standalone pipeline steps.
                if ($agent->is_verifier && $effectiveQaThreshold !== null) {
                    $score = $agentInstance instanceof QaVerifierAgent
                        ? $agentInstance->extractScore($agentRun)
                        : $this->extractScoreFromOutput($output);

                    $this->log("QA Score: {$score} / threshold: {$effectiveQaThreshold}");

                    if ($score < $effectiveQaThreshold) {
                        $message = "QA gate failed for {$agent->name}: score {$score} < threshold {$effectiveQaThreshold}";
                        $this->log("QA GATE: ✗ FAILED — score {$score} < threshold {$effectiveQaThreshold}");
                        $this->failFlowRun($flowRun, $message);

                        return $flowRun;
                    }

                    $this->log("QA GATE: ✓ PASSED — score {$score} ≥ threshold {$effectiveQaThreshold}");
                }

                $stepQaPolicy = $agent->is_verifier ? null : $this->stepQaPolicy($flowRun, $agent);
                if (! $stepQaPolicy) {
                    $this->log('');
                    break;
                }

                $stepQaResult = $this->runStepQaGate(
                    $flowRun,
                    $agents,
                    $agent,
                    $context,
                    $stepQaPolicy,
                    $qaRetriesUsed
                );

                if (! $stepQaResult['valid']) {
                    $agentRun->update(['status' => 'failed', 'error' => $stepQaResult['message']]);
                    $this->failFlowRun($flowRun, $stepQaResult['message']);

                    return $flowRun;
                }

                if ($stepQaResult['passed']) {
                    $this->log('');
                    break;
                }

                if ($qaRetriesUsed < $stepQaPolicy['max_retries']) {
                    $qaRetriesUsed++;
                    $context = $contextBeforeStep;
                    $this->log("STEP QA: retry {$qaRetriesUsed}/{$stepQaPolicy['max_retries']} for {$agent->name}");

                    continue;
                }

                $message = "QA validation failed after {$stepQaPolicy['max_retries']} retries for {$agent->name}: score {$stepQaResult['score']} < threshold {$stepQaPolicy['threshold']}";
                $agentRun->update(['status' => 'failed', 'error' => $message]);
                $this->failFlowRun($flowRun, $message);

                return $flowRun;
            }
        }

        $flowRun->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $flow->update(['last_run_at' => now()]);

        $this->logRunEnd($flowRun, 'completed');

        return $flowRun;
    }

    private function executeAgent(FlowRun $flowRun, Agent $agent, array $context, int $step, int $totalAgents): array
    {
        $input = $this->buildInput($agent, $context);

        $this->log('───────────────────────────────────────────────────────────');
        $this->log("STEP {$step}/{$totalAgents}: {$agent->name}");
        $this->log("Type:  {$agent->type}");
        $this->log("Model: {$agent->model}");
        $this->log("Input ({$this->charCount($input)} chars):");
        $this->log($this->indent($this->truncateLog($input, 800)));

        $agentRun = AgentRun::create([
            'flow_run_id' => $flowRun->id,
            'agent_id' => $agent->id,
            'status' => 'running',
            'model_used' => $agent->model,
            'input' => $input,
            'started_at' => now(),
        ]);

        $maxAttempts = 3;
        $lastError = null;
        $output = null;
        $rawOutput = null;
        $agentInstance = null;
        $startMs = now()->valueOf();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                if ($attempt > 1) {
                    $this->log("↻ RETRY attempt {$attempt}/{$maxAttempts} (previous: ".$lastError->getMessage().')');
                    $agentRun->update(['status' => 'running', 'error' => null]);
                    sleep(2);
                }

                $agentInstance = $this->factory->make($agent);
                $returnedOutput = $agentInstance->run($agent, $agentRun, $context);
                $rawOutput = $agentInstance->rawOutput() ?? $returnedOutput;
                $output = ReasoningStripper::strip($rawOutput);
                $lastError = null;
                break;

            } catch (Throwable $e) {
                $lastError = $e;
                $this->log("  Attempt {$attempt} failed: ".$e->getMessage());
            }
        }

        $durationMs = now()->valueOf() - $startMs;
        $durationStr = $this->formatDuration($durationMs);

        if ($lastError !== null) {
            $message = "Failed after {$maxAttempts} attempts. Last error: ".$lastError->getMessage();
            $agentRun->update([
                'status' => 'failed',
                'error' => $message,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);

            $this->log("Status:   ✗ FAILED after {$maxAttempts} attempts in {$durationStr}");
            $this->log('Last error: '.$lastError->getMessage());
            $this->log('File:       '.$lastError->getFile().':'.$lastError->getLine());
            $this->log('Trace:');
            $this->log($this->indent(collect(explode("\n", $lastError->getTraceAsString()))->take(10)->implode("\n")));

            return [
                'success' => false,
                'run' => $agentRun,
                'instance' => $agentInstance,
                'output' => null,
                'error_message' => $message,
            ];
        }

        $agentRun->update([
            'status' => 'completed',
            'output' => $output,
            'raw_output' => $rawOutput !== $output ? $rawOutput : null,
            'quality_metrics' => PricingOutputMetrics::fromOutput($output),
            'duration_ms' => $durationMs,
            'completed_at' => now(),
        ]);

        $this->log("Status:   ✓ COMPLETED in {$durationStr}");
        $this->log("Output ({$this->charCount($output)} chars):");
        $this->log($this->indent($this->truncateLog($output, 1200)));

        return [
            'success' => true,
            'run' => $agentRun,
            'instance' => $agentInstance,
            'output' => $output,
            'error_message' => null,
        ];
    }

    private function runStepQaGate(FlowRun $flowRun, iterable $agents, Agent $agent, array $context, array $policy, int $retriesUsed): array
    {
        $verifier = collect($agents)->firstWhere('id', $policy['verifier_agent_id']);

        if (! $verifier || ! $verifier->is_active || ! $verifier->is_verifier) {
            return [
                'valid' => false,
                'passed' => false,
                'score' => 0,
                'message' => "Step QA verifier for {$agent->name} is missing or inactive.",
            ];
        }

        $verifier->qa_threshold = $policy['threshold'];
        $this->log("STEP QA: {$verifier->name} validates {$agent->name} (threshold {$policy['threshold']}%, retries {$retriesUsed}/{$policy['max_retries']})");

        $execution = $this->executeAgent($flowRun, $verifier, $context, $verifier->order, collect($agents)->count());
        if (! $execution['success']) {
            return [
                'valid' => false,
                'passed' => false,
                'score' => 0,
                'message' => $execution['error_message'],
            ];
        }

        /** @var AgentRun $qaRun */
        $qaRun = $execution['run'];
        $qaInstance = $execution['instance'];
        $score = $qaInstance instanceof QaVerifierAgent
            ? $qaInstance->extractScore($qaRun)
            : $this->extractScoreFromOutput($execution['output']);
        $passed = $score >= $policy['threshold'];

        $this->recordStepQaResult($flowRun, $agent, $verifier, $score, $policy, $retriesUsed, $passed);

        if ($passed) {
            $this->log("STEP QA: ✓ PASSED — score {$score} ≥ threshold {$policy['threshold']}");
        } else {
            $this->log("STEP QA: ✗ FAILED — score {$score} < threshold {$policy['threshold']}");
        }

        return [
            'valid' => true,
            'passed' => $passed,
            'score' => $score,
            'message' => null,
        ];
    }

    private function mergeAgentOutputIntoContext(array $context, Agent $agent, string $output): array
    {
        $context[$agent->name] = $output;
        $context['input'] = $output;
        $context['topic'] = $output;

        return $context;
    }

    private function failFlowRun(FlowRun $flowRun, string $message): void
    {
        $context = $flowRun->fresh()->context ?? [];
        $context['failure_message'] = $message;

        $flowRun->update([
            'status' => 'failed',
            'context' => $context,
            'completed_at' => now(),
        ]);

        $this->log('Failure: '.$message);
        $this->logRunEnd($flowRun, 'failed');
    }

    private function ensureQaThresholdSnapshot(FlowRun $flowRun, iterable $agents): void
    {
        $context = $flowRun->context ?? [];
        $qaThresholds = $context['qa_thresholds'] ?? [];
        $changed = ! array_key_exists('qa_thresholds', $context);

        foreach ($agents as $agent) {
            if (! $agent->is_verifier) {
                continue;
            }

            $agentId = (string) $agent->id;
            if (! array_key_exists($agentId, $qaThresholds)) {
                $qaThresholds[$agentId] = $agent->qa_threshold ?? 75;
                $changed = true;
            }
        }

        if ($changed) {
            $context['qa_thresholds'] = $qaThresholds;
            $flowRun->update(['context' => $context]);
            $flowRun->refresh();
        }
    }

    private function ensureStepQaPolicySnapshot(FlowRun $flowRun, iterable $agents): void
    {
        $context = $flowRun->context ?? [];
        $policies = $context['step_qa_policies'] ?? [];
        $changed = ! array_key_exists('step_qa_policies', $context);
        $agentsCollection = collect($agents);
        $verifierIds = $agentsCollection
            ->where('is_verifier', true)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($agentsCollection as $agent) {
            if ($agent->is_verifier) {
                continue;
            }

            $agentId = (string) $agent->id;
            if (array_key_exists($agentId, $policies)) {
                continue;
            }

            $qa = ($agent->config ?? [])['qa'] ?? [];
            if (! filter_var($qa['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $verifierId = (int) ($qa['verifier_agent_id'] ?? 0);
            if (! in_array($verifierId, $verifierIds, true)) {
                continue;
            }

            $verifier = $agentsCollection->firstWhere('id', $verifierId);
            $policies[$agentId] = [
                'verifier_agent_id' => $verifierId,
                'threshold' => (int) ($qa['threshold'] ?? $verifier?->qa_threshold ?? 75),
                'max_retries' => min(10, max(0, (int) ($qa['max_retries'] ?? 3))),
            ];
            $changed = true;
        }

        if ($changed) {
            $context['step_qa_policies'] = $policies;
            $flowRun->update(['context' => $context]);
            $flowRun->refresh();
        }
    }

    private function effectiveQaThreshold(FlowRun $flowRun, object $agent): ?int
    {
        $qaThresholds = ($flowRun->context ?? [])['qa_thresholds'] ?? [];
        $agentId = (string) $agent->id;

        if (array_key_exists($agentId, $qaThresholds)) {
            return (int) $qaThresholds[$agentId];
        }

        return $agent->qa_threshold !== null ? (int) $agent->qa_threshold : null;
    }

    private function stepQaPolicy(FlowRun $flowRun, Agent $agent): ?array
    {
        $policies = ($flowRun->fresh()->context ?? [])['step_qa_policies'] ?? [];
        $policy = $policies[(string) $agent->id] ?? null;

        if (! is_array($policy)) {
            return null;
        }

        return [
            'verifier_agent_id' => (int) ($policy['verifier_agent_id'] ?? 0),
            'threshold' => (int) ($policy['threshold'] ?? 75),
            'max_retries' => min(10, max(0, (int) ($policy['max_retries'] ?? 3))),
        ];
    }

    private function referencedStepQaVerifierIds(FlowRun $flowRun): array
    {
        $policies = ($flowRun->context ?? [])['step_qa_policies'] ?? [];

        return collect($policies)
            ->pluck('verifier_agent_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function recordStepQaResult(FlowRun $flowRun, Agent $agent, Agent $verifier, int $score, array $policy, int $retriesUsed, bool $passed): void
    {
        $context = $flowRun->fresh()->context ?? [];
        $context['step_qa_results'] ??= [];
        $context['step_qa_results'][(string) $agent->id] = [
            'verifier_agent_id' => (int) $verifier->id,
            'score' => $score,
            'threshold' => (int) $policy['threshold'],
            'retries_used' => $retriesUsed,
            'max_retries' => (int) $policy['max_retries'],
            'passed' => $passed,
        ];
        $context['step_qa_retry_counts'] ??= [];
        $context['step_qa_retry_counts'][(string) $agent->id] = $retriesUsed;

        $flowRun->update(['context' => $context]);
        $flowRun->refresh();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Build the input message for an agent.
    // ──────────────────────────────────────────────────────────────────────
    // Keys that are system/alias variables, not agent outputs
    private const SYSTEM_CONTEXT_KEYS = ['company_description', 'company_name', 'company_industry', 'input', 'topic', 'flow_topic'];

    private function buildInput(object $agent, array $context): string
    {
        $prompt = $agent->prompt_template ?? '';
        $originalPrompt = $prompt;

        // Replace {{variable}} and {variable} placeholders
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $prompt = str_replace('{{'.$key.'}}', $value, $prompt);
                $prompt = str_replace('{'.$key.'}', $value, $prompt);
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
                if ($this->promptReferencesContextKey($originalPrompt, (string) $agentName)) {
                    continue;
                }
                if (is_string($output) && $output !== '') {
                    $lines[] = "[{$agentName}]:\n".$this->handoffText($output);
                }
            }
            if ($lines) {
                $prompt .= "\n\n--- Context from previous agents ---\n".implode("\n\n", $lines);
            }
        }

        return $prompt;
    }

    private function promptReferencesContextKey(string $prompt, string $key): bool
    {
        return str_contains($prompt, '{{'.$key.'}}')
            || str_contains($prompt, '{'.$key.'}');
    }

    private function handoffText(string $output): string
    {
        $maxChars = 20000;

        if (mb_strlen($output) <= $maxChars) {
            return $output;
        }

        return mb_substr($output, 0, $maxChars)
            ."\n\n[Truncated after {$maxChars} chars for agent handoff.]";
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
        $this->log("FLOW RUN #{$flowRun->id} ".strtoupper($status));
        $this->log('Finished at: '.now()->format('Y-m-d H:i:s'));
        if ($flowRun->started_at) {
            $elapsed = now()->diffInSeconds($flowRun->started_at);
            $this->log('Total time:  '.$this->formatDuration($elapsed * 1000));
        }
        $this->log('═══════════════════════════════════════════════════════════');
    }

    private function indent(string $text, string $prefix = '    '): string
    {
        return $prefix.str_replace("\n", "\n{$prefix}", $text);
    }

    private function truncateLog(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $half = (int) ($maxChars / 2);

        return mb_substr($text, 0, $half)
            ."\n... [".(mb_strlen($text) - $maxChars)." chars truncated] ...\n"
            .mb_substr($text, -$half);
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
