<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Agents\DecisionAgent;
use App\Agents\McpActionAgent;
use App\Jobs\ExecuteNodeJob;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\NodeRun;
use App\Models\OrgMember;
use App\Services\Execution\AdaptiveReplanner;
use App\Services\Execution\FlowNodeAgentBridge;
use App\Services\Execution\NodePromptBuilder;
use App\Services\Execution\StepQaGate;
use App\Services\Org\PersonaService;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use App\Support\NodeDeadline;
use App\Support\PricingOutputMetrics;
use App\Support\ReasoningStripper;
use App\Support\RunLog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Executes a single graph node — the ORCHESTRATOR of the execution layer.
 * The specialised concerns live in collaborators (app/Services/Execution/):
 *
 *  - NodePromptBuilder — input assembled from direct predecessors' output
 *    (namespaced, never a flat mutating array → no information loss) and
 *    rendered into the prompt template.
 *  - StepQaGate — optional inline verifier with retry: score < threshold
 *    re-executes the node up to max_retries times.
 *  - AdaptiveReplanner — degenerate-output watchdog + planner revision of a
 *    failing agent (run-scoped only).
 *  - FlowNodeAgentBridge — FlowNode/NodeRun → transient Agent/AgentRun DTOs,
 *    so all concrete agents (BaseAgent subclasses) stay untouched.
 *
 * This class keeps: idempotency, the QA/dedup retry loop, technical retry ×3
 * on transient errors, cost/audit bookkeeping and run-level failure handling.
 */
class NodeExecutorService
{
    private const MAX_ATTEMPTS = 3;

    // Колко от job timeout-а оставяме за wrap-up call + QA gate СЛЕД agentic
    // tool loop-а. Останалото е времевият бюджет на агента (deadline_ts).
    private const TIME_HEADROOM_SECONDS = 200;

    // Котва на времевия бюджет — задава се при влизане в executeNode(), така
    // че QA retry-тата делят ЕДИН бюджет и job-ът никога не гони своя timeout.
    private ?float $jobDeadlineTs = null;

    // Най-дългият upstream вход на последния runOnce() — watchdog-ът сравнява
    // изхода на трансформерен възел срещу него (run 102: коректорът върна
    // 85-знакова „присъда" върху 26K доклад).
    private int $lastUpstreamMaxLen = 0;

    // Кеш на ПЕРСОНА БЛОКА per run (org-flow) — резолвира се веднъж, не на всеки възел.
    /** @var array<int, string> */
    private array $personaBlockCache = [];

    public function __construct(
        private AgentFactory $factory,
        private FlowMemoryService $memory,
        private KnowledgeService $knowledge,
        private FlowNodeAgentBridge $bridge,
        private StepQaGate $qaGate,
        private AdaptiveReplanner $replanner,
        private NodePromptBuilder $promptBuilder,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Public entry point called by ExecuteNodeJob
    // ──────────────────────────────────────────────────────────────────────

    public function executeNode(int $flowRunId, int $flowNodeId): void
    {
        $this->jobDeadlineTs = microtime(true) + max(60, ExecuteNodeJob::TIMEOUT_SECONDS - self::TIME_HEADROOM_SECONDS);
        // Ambient копие за дълбоките tool цикли (CrawlService, DeepResearcher),
        // които нямат достъп до agent config-а.
        NodeDeadline::set($this->jobDeadlineTs);

        try {
            $flowRun = FlowRun::findOrFail($flowRunId);
            $node = FlowNode::findOrFail($flowNodeId);

            $nodeRun = NodeRun::firstOrNew([
                'flow_run_id' => $flowRunId,
                'flow_node_id' => $flowNodeId,
            ]);

            // Idempotency — a re-dispatched job must not re-run a completed node,
            // and a paused approval node stays paused until the approval endpoint
            // settles it.
            if ($nodeRun->exists && in_array($nodeRun->status, ['completed', 'paused'], true)) {
                return;
            }

            // Persist the run row up-front so it has an id, then open the paid-call
            // attribution frame for the WHOLE node lifecycle. Everything below —
            // mcp_action, the memory/knowledge pre-pass, the agentic tool loop, and
            // the dedup/QA/replan work between attempts — records to llm_requests
            // with this run/node (the frame is cleared in the finally; nested owners
            // like the planner revision save/restore it). node_runs.cost_usd uses the
            // context-free LlmUsage accumulator, so it was already correct.
            $nodeRun->fill([
                'node_key' => $node->node_key,
                'status' => 'running',
                'started_at' => $nodeRun->started_at ?? now(),
            ])->save();

            LlmContext::set([
                'purpose' => 'runtime',
                'company_id' => $flowRun->flow?->company_id,
                'flow_id' => $node->flow_id,
                'flow_run_id' => $flowRun->id,
                'node_run_id' => $nodeRun->id,
                'agent_name' => $node->name,
                'agent_type' => $node->type,
                // Билинг-атрибуция (§0.5.1): за org-flow run-овете контролерът/
                // executor-ът слага reservation_id+context в context — node LLM
                // повикванията се атрибутират към task_run резервацията. Не-org
                // flow → null → без атрибуция (непроменено поведение).
                'context_type' => $flowRun->context['credit_context_type'] ?? null,
                'subject_type' => $flowRun->context['credit_subject_type'] ?? null,
                'subject_id' => $flowRun->context['credit_subject_id'] ?? null,
                'reservation_id' => $flowRun->context['credit_reservation_id'] ?? null,
            ]);

            // Human-in-the-loop: the node never executes an agent — it pauses the
            // run until a person approves/rejects via the run UI. Exception: when
            // every mcp_action it gates is explicitly marked requires_approval=false
            // (the per-action checkbox unchecked), the gate is waived and the node
            // auto-passes — so the checkbox actually controls the inserted node.
            if ($node->type === 'human_approval') {
                if ($this->approvalWaived($node)) {
                    RunLog::append($flowRun->id, "[MCP] {$node->name}: одобрението е прескочено — действието е маркирано без нужда от потвърждение (requires_approval=false)");
                    $nodeRun->fill([
                        'node_key' => $node->node_key,
                        'status' => 'completed',
                        'output' => 'Auto-approved: действието не изисква потвърждение.',
                        'error' => null,
                        'started_at' => $nodeRun->started_at ?? now(),
                        'completed_at' => now(),
                    ])->save();

                    return;
                }

                $this->pauseForApproval($flowRun, $node, $nodeRun);

                return;
            }

            // MCP действие: не минава през AgentLoop/QA/памет — изпълнява tool
            // call в свързана система и записва резултата.
            if ($node->type === 'mcp_action') {
                $this->executeMcpAction($flowRun, $node, $nodeRun);

                return;
            }

            $this->runWithQaRetry($flowRun, $node, $nodeRun);
        } finally {
            LlmContext::clear();
            NodeDeadline::clear();
        }
    }

    /**
     * Pause the run on a human_approval node: the NodeRun goes 'paused' with
     * the upstream material as its input (so the approver sees WHAT they are
     * approving), the FlowRun goes 'waiting_approval' — which blocks
     * GraphFlowExecutor::dispatchWave() from advancing past this wave. The
     * job itself completes normally, so the wave batch still succeeds.
     */
    private function pauseForApproval(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun): void
    {
        $ctx = $this->promptBuilder->buildNodeInput($flowRun, $node);
        $input = $this->promptBuilder->renderPrompt($node, $ctx);

        $nodeRun->fill([
            'node_key' => $node->node_key,
            'status' => 'paused',
            'input' => $input,
            'error' => null,
            'started_at' => $nodeRun->started_at ?? now(),
        ])->save();

        $waveIndex = null;
        foreach ($flowRun->context['waves'] ?? [] as $i => $keys) {
            if (in_array($node->node_key, (array) $keys, true)) {
                $waveIndex = $i;
                break;
            }
        }

        $context = $flowRun->fresh()->context ?? [];
        $context['approvals'][$node->node_key] = [
            'status' => 'pending',
            'node_name' => $node->name,
            'wave_index' => $waveIndex,
            'requested_at' => now()->toISOString(),
        ];
        $flowRun->update(['context' => $context]);

        // Conditional flip keeps a concurrent failure final (status-only update,
        // safe as a query-builder write).
        FlowRun::whereKey($flowRun->id)
            ->where('status', 'running')
            ->update(['status' => 'waiting_approval']);

        Log::info("[NodeExecutor] {$node->name} чака одобрение от човек (run {$flowRun->id})");
        RunLog::append($flowRun->id, "[PAUSE] {$node->name} чака одобрение от човек");
    }

    // ──────────────────────────────────────────────────────────────────────
    // MCP действие — изпълнява tool call в свързана система
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Изпълнява mcp_action node чрез McpActionAgent. Не хвърля при провал на
     * tool-а (за да НЕ се retry-ва job-ът и да не дублира write операция);
     * вместо това маркира node-а failed и уважава failure_policy: fail_fast
     * проваля run-а, best_effort продължава останалите клонове.
     */
    private function executeMcpAction(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun): void
    {
        $started = microtime(true);
        $tool = (string) ($node->config['tool'] ?? '');

        // Safety gate: действие, маркирано requires_approval, НЕ се изпълнява без
        // „Одобрение от човек" предшественик. Планерът го гарантира
        // (gateWriteMcpActions); ръчно поставен write node без gate се блокира —
        // никакво изходящо действие без одобрение (освен ако потребителят изрично
        // махне отметката requires_approval).
        if (($node->config['requires_approval'] ?? false) && ! $this->hasApprovalPredecessor($node)) {
            $message = "Действие „{$tool}\" изисква одобрение, но няма свързан „Одобрение от човек\" възел преди него.";
            $nodeRun->fill([
                'node_key' => $node->node_key,
                'status' => 'failed',
                'input' => "MCP: {$tool}",
                'model_used' => 'mcp:'.(explode('.', $tool)[0] ?: 'action'),
                'error' => $message,
                'started_at' => $nodeRun->started_at ?? now(),
                'completed_at' => now(),
            ])->save();
            RunLog::append($flowRun->id, "[MCP] {$node->name}: блокирано — write действие без human_approval gate");

            if (($flowRun->context['failure_policy'] ?? 'fail_fast') !== 'best_effort') {
                $this->failFlowRun($flowRun, $message);
            }

            return;
        }

        // act HARD GATE под preview (§B2): org flow (има assistant_task_id) + ORG_ACT_ENABLED=false
        // → произвежда „чернова на действието" (tool/аргументи/очакван ефект), БЕЗ реален
        // страничен ефект и БЕЗ ред в connector_tool_logs. Реалният act иска реален auth (Фаза 6).
        if (isset($flowRun->context['assistant_task_id']) && ! config('organization.act.enabled')) {
            $params = (array) ($node->config['tool_params'] ?? []);
            $connectorId = (int) ($node->config['connector_id'] ?? 0);
            $draft = "ЧЕРНОВА НА ДЕЙСТВИЕ (act изключен под preview — без реален ефект)\n"
                ."Инструмент: {$tool}\nКонектор: #{$connectorId}\n"
                .'Аргументи: '.json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n"
                .'Очакван ефект: действието БИ било изпълнено при включен реален auth (ORG_ACT_ENABLED=true).';
            $nodeRun->fill([
                'node_key' => $node->node_key,
                'status' => 'completed',
                'input' => "MCP draft: {$tool}",
                'output' => $draft,
                'model_used' => 'mcp:draft',
                'error' => null,
                'started_at' => $nodeRun->started_at ?? now(),
                'completed_at' => now(),
            ])->save();
            RunLog::append($flowRun->id, "[MCP] {$node->name}: act изключен → чернова на действието (без реален страничен ефект)");

            return;
        }

        $nodeRun->fill([
            'node_key' => $node->node_key,
            'status' => 'running',
            'input' => "MCP: {$tool} (connector #".((int) ($node->config['connector_id'] ?? 0)).')',
            'model_used' => 'mcp:'.(explode('.', $tool)[0] ?: 'action'),
            'error' => null,
            'started_at' => $nodeRun->started_at ?? now(),
        ])->save();

        $outputs = $this->mcpRunOutputs($flowRun, $node);
        $result = app(McpActionAgent::class)->run($node, $flowRun, $outputs, $nodeRun->id, $this->finalReportOutput($flowRun));
        $durationMs = (int) ((microtime(true) - $started) * 1000);

        if ($result->success) {
            $nodeRun->update([
                'status' => 'completed',
                'output' => $result->text,
                'cost_usd' => 0,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);
            RunLog::append($flowRun->id, "[MCP] {$node->name}: {$tool} ✅");

            return;
        }

        // Провал — без throw (write tool не бива да се повтаря при job retry).
        $message = "MCP tool '{$tool}' fail: {$result->error}";
        $nodeRun->update([
            'status' => 'failed',
            'error' => $message,
            'duration_ms' => $durationMs,
            'completed_at' => now(),
        ]);
        RunLog::append($flowRun->id, "[MCP] {$node->name}: ГРЕШКА — {$result->error}");

        if (($flowRun->context['failure_policy'] ?? 'fail_fast') !== 'best_effort') {
            $this->failFlowRun($flowRun, $message);
        }
    }

    /**
     * Дали одобрението е waive-нато: всеки mcp_action, който този „Одобрение от
     * човек" възел гейтва (пряк наследник), е ИЗРИЧНО маркиран
     * requires_approval=false. Така отметката „изисква потвърждение" в имейл/
     * action-нода реално управлява авто-вмъкнатия approval възел — без редакция
     * на графа. Generic approval без mcp_action наследник никога не се waive-ва.
     */
    private function approvalWaived(FlowNode $node): bool
    {
        $downstreamKeys = FlowEdge::where('flow_version_id', $node->flow_version_id)
            ->where('from_node_key', $node->node_key)
            ->pluck('to_node_key')
            ->all();
        if (empty($downstreamKeys)) {
            return false;
        }

        $actions = FlowNode::where('flow_version_id', $node->flow_version_id)
            ->whereIn('node_key', $downstreamKeys)
            ->where('type', 'mcp_action')
            ->get(['config']);
        if ($actions->isEmpty()) {
            return false;
        }

        foreach ($actions as $action) {
            if (($action->config['requires_approval'] ?? true) !== false) {
                return false;
            }
        }

        return true;
    }

    /** Дали node-ът има пряк „Одобрение от човек" предшественик в графа. */
    private function hasApprovalPredecessor(FlowNode $node): bool
    {
        $predKeys = FlowEdge::where('flow_version_id', $node->flow_version_id)
            ->where('to_node_key', $node->node_key)
            ->pluck('from_node_key')
            ->all();
        if (empty($predKeys)) {
            return false;
        }

        return FlowNode::where('flow_version_id', $node->flow_version_id)
            ->whereIn('node_key', $predKeys)
            ->where('type', 'human_approval')
            ->exists();
    }

    /**
     * Изходите на ВСИЧКИ завършили възли в run-а, keyed по node_key И по име — за
     * да работят {{agent.<node_key>.output}} и {{agent.<име>.output}} за ЛЮБОЙ
     * възел, не само пряк предшественик (прекият предшественик на имейла обикн. е
     * „Одобрение от човек", а потребителят иска да реферира доклада).
     *
     * @return array<string,string>
     */
    private function mcpRunOutputs(FlowRun $flowRun, FlowNode $node): array
    {
        $names = FlowNode::where('flow_version_id', $node->flow_version_id)
            ->pluck('name', 'node_key');

        $outputs = NodeRun::where('flow_run_id', $flowRun->id)
            ->where('status', 'completed')
            ->pluck('output', 'node_key');

        $map = [];
        foreach ($outputs as $key => $output) {
            if ($output === null || $output === '') {
                continue;
            }
            $map[$key] = $output;
            if (! empty($names[$key])) {
                $map[$names[$key]] = $output;
            }
        }

        return $map;
    }

    /**
     * Финалният доклад на run-а за {{report}} / {{flow.output}}: изходът на
     * последния завършил body-възел (с предимство bg_text_corrector — финално
     * изгладеният текст). Празно, ако няма body възел.
     */
    private function finalReportOutput(FlowRun $flowRun): string
    {
        $nodes = FlowNode::where('flow_version_id', $flowRun->flow_version_id)
            ->where('is_active', true)
            ->get();

        $bodyKeys = $nodes->filter(fn (FlowNode $n) => $n->effectiveOutputRole() === 'body')
            ->pluck('node_key')
            ->all();
        if ($bodyKeys === []) {
            return '';
        }

        $correctorKeys = array_values(array_intersect(
            $nodes->where('type', 'bg_text_corrector')->pluck('node_key')->all(),
            $bodyKeys,
        ));

        $pick = fn (array $keys): ?string => $keys === [] ? null : NodeRun::where('flow_run_id', $flowRun->id)
            ->whereIn('node_key', $keys)
            ->where('status', 'completed')
            ->whereNotNull('output')
            ->orderByDesc('completed_at')
            ->value('output');

        return (string) ($pick($correctorKeys) ?? $pick($bodyKeys) ?? '');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Core execution + step-QA retry loop
    // ──────────────────────────────────────────────────────────────────────

    private function runWithQaRetry(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun): void
    {
        $qaConfig = $this->qaGate->configFor($node);
        $maxQaRetries = $qaConfig ? min(10, max(0, (int) ($qaConfig['max_retries'] ?? 3))) : 0;
        $qaRetriesUsed = 0;
        // Фаза 3: revision applied for THIS RUN only (the saved graph stays
        // untouched — the suggestion is recorded in the run context instead).
        $revision = null;
        // Памет: a content output too similar to remembered content retries
        // with concrete feedback; after the cap it is accepted with a flag.
        $maxDedupRetries = min(5, max(0, (int) config('services.memory.dedup_max_retries', 2)));
        $dedupRetriesUsed = 0;
        $dedupFeedback = null;
        // QA feedback from a sub-threshold attempt — fed into the next run so the
        // retry knows WHAT to fix instead of blindly repeating the same prompt.
        $qaFeedback = null;
        // Cumulative paid cost across all attempts of THIS node (runOnce overwrites
        // node_runs.cost_usd per attempt) — drives the retry cost cap.
        $cumulativeCost = 0.0;

        while (true) {
            $output = $this->runOnce($flowRun, $node, $nodeRun, $revision, $dedupFeedback, $qaFeedback);
            $cumulativeCost += (float) $nodeRun->cost_usd;

            // Watchdog: degenerate output (empty/placeholder boilerplate) is a
            // failure even before QA — try one planner revision immediately.
            if ($revision === null && $this->replanner->looksDegenerate($output, $node, $this->lastUpstreamMaxLen)) {
                $revision = $this->replanner->requestRevision($flowRun, $node, $nodeRun, $output, 'Изходът е изроден: празен/твърде кратък или съдържа шаблонен placeholder текст.');
                if ($revision !== null) {
                    RunLog::append($flowRun->id, "[REPLAN] {$node->name}: изроден изход — повторен опит с ревизия от планера");
                    $this->resetForRetry($nodeRun);

                    continue;
                }
            }

            if (! $qaConfig) {
                if (($feedback = $this->dedupGate($flowRun, $node, $nodeRun, $output, $dedupRetriesUsed, $maxDedupRetries)) !== null) {
                    $dedupRetriesUsed++;
                    $dedupFeedback = $feedback;
                    $this->resetForRetry($nodeRun);

                    continue;
                }

                // No step-QA — done. A revision that produced a healthy output
                // is a success — offer it for persisting into the graph.
                $this->replanner->recordRevisionSuccess($flowRun, $node, $revision);

                return;
            }

            $qaResult = $this->qaGate->verify($flowRun, $node, $nodeRun, $qaConfig, $output);

            // Историята за model router-а: финалният QA score остава на
            // node_run-а (при retry следващата оценка го презаписва).
            $nodeRun->update(['qa_score' => max(0, min(100, (int) ($qaResult['score'] ?? 0)))]);

            RunLog::append($flowRun->id, "[QA] {$node->name}: score {$qaResult['score']} (праг {$qaConfig['threshold']}) — "
                .($qaResult['passed'] ? 'OK' : 'под прага'));

            if ($qaResult['passed']) {
                // Dedup gate AFTER the QA gate — no point embedding an output
                // QA would reject anyway. The retried output re-runs QA.
                if (($feedback = $this->dedupGate($flowRun, $node, $nodeRun, $output, $dedupRetriesUsed, $maxDedupRetries)) !== null) {
                    $dedupRetriesUsed++;
                    $dedupFeedback = $feedback;
                    $this->resetForRetry($nodeRun);

                    continue;
                }

                $this->replanner->recordRevisionSuccess($flowRun, $node, $revision);

                return;
            }

            // Budget guard: stop retrying if the node ran out of time, blew the
            // cost cap, or the run already died — keep the best output we have
            // instead of burning another full re-run (16 web searches etc.).
            if (($capReason = $this->qaRetryBudgetExceeded($flowRun, $cumulativeCost)) !== null) {
                RunLog::append($flowRun->id, "[QA] {$node->name}: приема се изход под прага (score {$qaResult['score']}) — {$capReason}");
                $nodeRun->update([
                    'status' => 'completed',
                    'quality_metrics' => array_merge((array) $nodeRun->quality_metrics, [
                        'qa_accepted_below_threshold' => true,
                        'qa_cap_reason' => $capReason,
                    ]),
                ]);

                return;
            }

            if ($qaRetriesUsed < $maxQaRetries) {
                $qaRetriesUsed++;

                // Feed the verifier's actionable feedback into the next attempt so
                // even the cheap first retry is informed, not a blind repeat.
                $fb = trim((string) ($qaResult['feedback'] ?? ''));
                $qaFeedback = $fb !== ''
                    ? 'ОБРАТНА ВРЪЗКА ОТ ПРОВЕРКАТА НА КАЧЕСТВОТО (предишният опит беше отхвърлен — поправи конкретно следното): '.$fb
                    : null;

                // First retry is plain (cheap). From the second on, ask the
                // planner to revise the failing agent (Фаза 3).
                if ($qaRetriesUsed >= 2 && $revision === null) {
                    $revision = $this->replanner->requestRevision(
                        $flowRun, $node, $nodeRun, $output,
                        "QA score {$qaResult['score']} < праг {$qaConfig['threshold']} (".($qaConfig['custom_prompt'] ?? 'обща проверка').')',
                    );
                }

                RunLog::append($flowRun->id, "[QA] {$node->name}: повторен опит {$qaRetriesUsed}/{$maxQaRetries}"
                    .($revision !== null ? ' с ревизия от планера' : '')
                    .($qaFeedback !== null ? ' (с обратна връзка)' : ''));

                $this->resetForRetry($nodeRun);

                continue;
            }

            // Max retries exhausted — QA gate failed. Handled as a business-logic
            // failure (not a thrown exception) so the NodeRun update commits and
            // ExecuteNodeJob returns normally.
            $message = "QA gate failed after {$maxQaRetries} retries for {$node->name}: "
                ."score {$qaResult['score']} < threshold {$qaConfig['threshold']}";

            // fail_fast marks the whole run failed (subsequent dispatchWave calls
            // see status ≠ 'running' and stop the chain).
            if (($flowRun->context['failure_policy'] ?? 'best_effort') === 'fail_fast') {
                $nodeRun->update(['status' => 'failed', 'error' => $message]);
                $this->failFlowRun($flowRun, $message);

                return;
            }

            // best_effort: a non-empty output WAS produced — accept it below the
            // threshold (flagged) instead of marking the node failed. Marking it
            // failed would let resolveActiveNodes prune everything downstream,
            // silently dropping a delivery tail (e.g. approval → email) even
            // though usable content exists. The best available text still flows;
            // the flag keeps it auditable.
            if (trim($output) !== '') {
                RunLog::append($flowRun->id, "[QA] {$node->name}: провал след {$maxQaRetries} опита — приема се най-добрият изход под прага (best_effort); веригата продължава");
                $nodeRun->update([
                    'status' => 'completed',
                    'quality_metrics' => array_merge((array) $nodeRun->quality_metrics, [
                        'qa_accepted_below_threshold' => true,
                        'qa_cap_reason' => "score {$qaResult['score']} < threshold {$qaConfig['threshold']} след {$maxQaRetries} опита",
                    ]),
                ]);

                return;
            }

            // Empty/degenerate output — nothing usable to pass on; mark failed and
            // let resolveActiveNodes prune the dead branch.
            $nodeRun->update(['status' => 'failed', 'error' => $message]);
            RunLog::append($flowRun->id, "[QA] {$node->name}: провал след {$maxQaRetries} опита — празен изход, run-ът продължава без този възел");

            return;
        }
    }

    /**
     * Reason to stop the QA retry loop early (time/cost budget or a dead run), or
     * null to keep retrying. Prevents a single gated node from burning unbounded
     * money and time on repeated full re-runs.
     */
    private function qaRetryBudgetExceeded(FlowRun $flowRun, float $cumulativeCost): ?string
    {
        if ($this->jobDeadlineTs !== null && microtime(true) >= $this->jobDeadlineTs) {
            return 'времевият бюджет на възела изтече';
        }

        $cap = (float) config('services.qa.max_retry_cost_usd', 0);
        if ($cap > 0 && $cumulativeCost > $cap) {
            return 'разходният таван ($'.number_format($cap, 2).') е надхвърлен';
        }

        if (FlowRun::whereKey($flowRun->id)->value('status') !== 'running') {
            return 'run-ът вече не е активен';
        }

        return null;
    }

    private function failFlowRun(FlowRun $flowRun, string $message): void
    {
        // Единственото място, което знае как се проваля run (вкл. помитането на
        // осиротели node_runs + RunLog FAILED реда), е GraphFlowExecutor::fail().
        app(GraphFlowExecutor::class)->fail($flowRun, $message);
    }

    /**
     * Should the company-knowledge ("ЗНАНИЕ") block be injected into this node's
     * prompt? Yes for synthesis/content nodes (incl. the ones that build
     * comparisons/tables, which need OUR own services & prices to fill the
     * own-company column); no for transformers, control nodes, or nodes that
     * already pull facts through the agentic knowledge_search prepass.
     */
    private function shouldInjectKnowledge(FlowNode $node): bool
    {
        $excluded = ['bg_text_corrector', 'translator', 'human_approval', 'mcp_action', 'decision', 'qa_verifier'];
        if (in_array($node->type, $excluded, true)) {
            return false;
        }

        $tools = array_map('strval', (array) ($node->config['tools'] ?? []));
        if (in_array('knowledge_search', $tools, true)) {
            return false;
        }

        return true;
    }

    /**
     * Персона блок се инжектира навсякъде ОСВЕН в persona-неутралните възли — същият
     * под на компетентност като знанието (§5.3): „импулсивен" член мени КАК, не дали QA минава.
     */
    private function shouldInjectPersona(FlowNode $node): bool
    {
        $excluded = ['bg_text_corrector', 'translator', 'human_approval', 'mcp_action', 'decision', 'qa_verifier'];

        return ! in_array($node->type, $excluded, true);
    }

    /**
     * ПЕРСОНА БЛОКЪТ на члена-собственик на run-а (резолвиран веднъж и кеширан per run,
     * за да не удря БД на всеки възел). Празен низ за не-org flow (без org_member_id).
     */
    private function personaBlock(FlowRun $flowRun): string
    {
        $memberId = $flowRun->context['org_member_id'] ?? null;
        if (! $memberId) {
            return '';
        }

        if (array_key_exists($flowRun->id, $this->personaBlockCache)) {
            return $this->personaBlockCache[$flowRun->id];
        }

        $member = OrgMember::with('persona')->find($memberId);
        $block = $member ? app(PersonaService::class)->compileSystemPrompt($member) : '';

        return $this->personaBlockCache[$flowRun->id] = $block;
    }

    /**
     * Execute the node exactly once. Returns the cleaned output string.
     * Throws RuntimeException after MAX_ATTEMPTS technical failures.
     *
     * A planner revision (Фаза 3) is applied to the in-memory node only —
     * never persisted to flow_nodes.
     */
    private function runOnce(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun, ?array $revision = null, ?string $dedupFeedback = null, ?string $qaFeedback = null): string
    {
        if ($revision !== null) {
            $this->replanner->applyRevision($node, $revision);
        }

        $ctx = $this->promptBuilder->buildNodeInput($flowRun, $node);
        $input = $this->promptBuilder->renderPrompt($node, $ctx);
        $this->lastUpstreamMaxLen = (int) max([0, ...array_map('mb_strlen', $ctx['upstream'])]);

        // Памет: steer content nodes away from already-produced content and
        // give every node its distilled lessons. The blocks land in
        // node_runs.input — visible verbatim in the node-detail viewer.
        if (FlowMemoryService::enabled($flowRun->flow)) {
            if (($memoryBlock = $this->memory->outputMemoryBlock($flowRun->flow, $node)) !== '') {
                $input .= "\n\n".$memoryBlock;
            }
            if (($lessons = $this->memory->lessonsBlock($flowRun->flow, $node)) !== '') {
                $input .= "\n\n".$lessons;
            }
        }

        // Знание: фактологичен RAG блок от базата знания на фирмата — за синтез/
        // content нодовете (вкл. тези, които строят сравнения/таблици), но НЕ за
        // трансформъри/контрол-нодове или нодове с knowledge_search (те ползват
        // агентния prepass). Само от grounding колекциите (документи/сайт, НЕ
        // история). Работи и за локални модели, които не могат tool-calling.
        if (KnowledgeService::enabledForFlow($flowRun->flow)
            && ($node->config['knowledge']['enabled'] ?? true) !== false
            && $this->shouldInjectKnowledge($node)) {
            // Таблица/синтез нодове, които пълнят СОБСТВЕНАТА колона/ред, получават
            // ПЪЛНИЯ структуриран профил (всички цени/услуги) — чисти факти, без
            // зашумени уеб-чънкове, за да влязат цените по зони. Останалите нодове
            // получават по-лекия relevance блок (безопасен за локални модели).
            $tableBuilders = ['data_extractor', 'formatter', 'price_optimizer', 'competitor_profiler', 'swot_builder'];
            $knowledgeBlock = in_array($node->type, $tableBuilders, true)
                ? $this->knowledge->ownProfileBlock($flowRun->flow->company)
                : $this->knowledge->knowledgeBlock($flowRun->flow->company, $input, [
                    'company_id' => $flowRun->flow->company_id,
                    'flow_id' => $node->flow_id,
                    'flow_run_id' => $flowRun->id,
                ]);
            if ($knowledgeBlock !== '') {
                $input .= "\n\n".$knowledgeBlock;
            }
        }

        // Персона: за org-flow нодовете добавяме ПЕРСОНА БЛОК на члена-собственик
        // (характер/стил, НЕ компетентност), за да „действат като себе си" (§0.5.5).
        // Персона-неутралните възли (QA/трансформъри/контрол) са изключени (под на
        // компетентност, §5.3). Не-org flow (без org_member_id) → no-op.
        if ($this->shouldInjectPersona($node) && ($personaBlock = $this->personaBlock($flowRun)) !== '') {
            $input .= "\n\n".$personaBlock;
        }

        if ($dedupFeedback !== null) {
            $input .= "\n\n".$dedupFeedback;
        }

        if ($qaFeedback !== null) {
            $input .= "\n\n".$qaFeedback;
        }

        $nodeRun->fill([
            'node_key' => $node->node_key,
            'status' => 'running',
            'input' => $input,
            'model_used' => $node->model,
            'error' => null,
            'started_at' => $nodeRun->started_at ?? now(),
        ])->save();

        // "STEP n/total: име" — parseRunProgress() реже live-прогреса по
        // последния такъв маркер. При паралелна вълна номерата са приблизителни.
        $totalSteps = max(1, count(array_merge(...(array) ($flowRun->context['waves'] ?? [[]]))));
        $stepNo = min($totalSteps, NodeRun::where('flow_run_id', $flowRun->id)->where('status', 'completed')->count() + 1);
        RunLog::append($flowRun->id, "STEP {$stepNo}/{$totalSteps}: {$node->name}");

        $agent = $this->bridge->bridgeAgent($node);
        // Eval Suite: run-scoped model override — позволява една и съща версия
        // да се пуска на различно ниво (моделите за нивото са пресметнати при
        // eval и сложени в context['model_overrides']). '' = локален авто.
        $overrides = $flowRun->context['model_overrides'] ?? null;
        if (is_array($overrides) && array_key_exists($node->node_key, $overrides)) {
            $agent->model = (string) $overrides[$node->node_key];
        }
        // Времевият бюджет и фирменият контекст пътуват през transient DTO-то
        // (никога не се persist-ват) — deadline_ts до GenericAgent::runAgentic →
        // AgentLoop, company_id до KnowledgeSearchTool през AgentFactory.
        $agent->config = array_merge((array) $agent->config, [
            'deadline_ts' => $this->jobDeadlineTs,
            'company_id' => $flowRun->flow?->company_id,
            'flow_run_id' => $flowRun->id,
            'node_key' => $node->node_key,
            'predecessor_roles' => $ctx['upstream_roles'] ?? [],
        ]);
        $this->bridge->ensureModelInstalled($agent);

        $bridgeRun = $this->bridge->bridgeRun($flowRun, $input);
        $agentContext = $this->promptBuilder->agentContext($ctx);

        $lastError = null;
        $output = null;
        $rawOutput = null;
        $agentInstance = null;
        $startMs = now()->valueOf();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                if ($attempt > 1) {
                    sleep(2);
                }
                $agentInstance = $this->factory->make($agent);
                $returned = $agentInstance->run($agent, $bridgeRun, $agentContext);
                // The agent's return value is canonical — it carries the agent's
                // post-processing (guardCorrection, sanitizeModelOutput …). The
                // raw model reply is kept ONLY for the raw_output audit column.
                $rawOutput = $agentInstance->rawOutput() ?? $returned;
                $output = ReasoningStripper::strip($returned);
                $lastError = null;
                break;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        $durationMs = now()->valueOf() - $startMs;

        // Collect paid-provider usage accumulated during this node execution
        // (openai/* chat calls + any planner revision calls it triggered). The
        // LlmContext frame is owned by executeNode() and spans every attempt.
        $usage = LlmUsage::take();

        if ($lastError !== null) {
            $message = "Node {$node->name} failed after ".self::MAX_ATTEMPTS.' attempts: '.$lastError->getMessage();
            $nodeRun->update(array_merge($usage, [
                'status' => 'failed',
                // The resolved model (auto-select leaves node->model empty).
                'model_used' => $agentInstance?->chatParams()['model'] ?? $node->model,
                'error' => $message,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]));

            RunLog::append($flowRun->id, "[NODE] ✗ {$node->name} — {$message}");

            throw new RuntimeException($message, previous: $lastError);
        }

        $nodeRun->update(array_merge($usage, [
            'status' => 'completed',
            // The resolved model (auto-select leaves node->model empty).
            'model_used' => $agentInstance?->chatParams()['model'] ?? $node->model,
            'output' => $output,
            'raw_output' => $rawOutput !== $output ? $rawOutput : null,
            'quality_metrics' => PricingOutputMetrics::fromOutput($output),
            'params_snapshot' => $agentInstance?->chatParams(),
            'duration_ms' => $durationMs,
            'completed_at' => now(),
        ]));

        RunLog::append($flowRun->id, "[NODE] ✓ {$node->name} — ".mb_strlen($output).' chars, '
            .round($durationMs / 1000, 1).'s, модел '.($agentInstance?->chatParams()['model'] ?? $node->model));

        // WS4: a decision node records which branch it picked so GraphFlowExecutor
        // can prune the not-taken branches in later waves.
        if ($agentInstance instanceof DecisionAgent && ($port = $agentInstance->chosenBranch()) !== null) {
            $this->recordDecision($flowRun, $node, $port);
        }

        return $output;
    }

    /** Persist a decision node's chosen output port into the run context. */
    private function recordDecision(FlowRun $flowRun, FlowNode $node, string $port): void
    {
        $context = $flowRun->fresh()->context ?? [];
        $context['decisions'][$node->node_key] = $port;
        $flowRun->update(['context' => $context]);

        Log::info("[NodeExecutor] Decision {$node->name} → {$port}");
    }

    private function resetForRetry(NodeRun $nodeRun): void
    {
        $nodeRun->update([
            'status' => 'running',
            'output' => null,
            'raw_output' => null,
            'error' => null,
            'started_at' => now(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Памет — post-generation dedup gate (enforces "не повтаряй" with retries)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Returns retry feedback when the output is too similar to remembered
     * content, null when the node may finish. Never fails the run — exhausted
     * retries accept the output with an 'accepted_flagged' audit entry.
     */
    private function dedupGate(
        FlowRun $flowRun,
        FlowNode $node,
        NodeRun $nodeRun,
        string $output,
        int $retriesUsed,
        int $maxRetries
    ): ?string {
        if (! FlowMemoryService::enabled($flowRun->flow) || ! $this->memory->isContentNode($node)) {
            return null;
        }

        $match = $this->memory->similarityCheck($flowRun->flow, $output, [
            'company_id' => $flowRun->flow?->company_id,
            'flow_id' => $node->flow_id,
            'flow_run_id' => $flowRun->id,
            'node_run_id' => $nodeRun->id,
            'agent_name' => $node->name,
            'agent_type' => $node->type,
        ]);

        // Fold the embed call's usage into this node's cost columns — runOnce
        // already took its own accumulation, so without this the worker's next
        // unit of work would inherit it.
        $usage = LlmUsage::take();
        if (($usage['cost_usd'] ?? null) !== null) {
            $nodeRun->update([
                'prompt_tokens' => (int) $nodeRun->prompt_tokens + (int) ($usage['prompt_tokens'] ?? 0),
                'cost_usd' => round((float) $nodeRun->cost_usd + (float) $usage['cost_usd'], 6),
            ]);
        }

        if ($match === null) {
            return null;
        }

        $pct = (int) round($match['similarity'] * 100);
        $action = $retriesUsed < $maxRetries ? 'retry' : 'accepted_flagged';

        // Audit trail for the run viewer (mirrors the replan pattern).
        $context = $flowRun->fresh()->context ?? [];
        $context['memory_dedup'][$node->node_key][] = [
            'similarity' => $match['similarity'],
            'matched_title' => $match['title'],
            'action' => $action,
            'at' => now()->toISOString(),
        ];
        $flowRun->update(['context' => $context]);

        if ($action === 'accepted_flagged') {
            Log::info("[NodeExecutor] Памет: {$node->name} е {$pct}% подобен на „{$match['title']}“ — приет с предупреждение (изчерпани retry-та)");
            RunLog::append($flowRun->id, "[ПАМЕТ] {$node->name}: {$pct}% подобие с „{$match['title']}“ — приет с предупреждение");

            return null;
        }

        Log::info("[NodeExecutor] Памет: {$node->name} е {$pct}% подобен на „{$match['title']}“ — повторен опит с feedback");
        RunLog::append($flowRun->id, "[ПАМЕТ] {$node->name}: {$pct}% подобие с „{$match['title']}“ — повторен опит с feedback");

        return "ВНИМАНИЕ: Предишният ти вариант е {$pct}% подобен на вече създадено съдържание: "
            ."„{$match['title']}“ ({$match['summary']}). Това надхвърля допустимите 30-40% припокриване. "
            .'Напиши СЪЩЕСТВЕНО различен вариант — нова тема/ъгъл, нови заглавия и формулировки.';
    }
}
