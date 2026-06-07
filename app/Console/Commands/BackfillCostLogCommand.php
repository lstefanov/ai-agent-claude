<?php

namespace App\Console\Commands;

use App\Support\PaidModel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-time (re)seed of the unified cost log (llm_requests) from the two
 * historical rollups, so the admin Разходи page shows real past activity:
 *
 *   - agent_generation_logs → "auto agent creator" sessions (one row per
 *     planner phase; grouped on the page by the planner's session token).
 *   - node_runs             → flow run agents (one row per executed node).
 *
 * Going-forward calls are logged live at the provider chokepoints; this only
 * seeds history. The owner resets the DB rather than migrating, so the command
 * truncates llm_requests and rebuilds it from scratch — re-running is safe.
 */
class BackfillCostLogCommand extends Command
{
    protected $signature = 'flows:backfill-cost-log';

    protected $description = 'Reseed llm_requests from agent_generation_logs (generation) + node_runs (runs)';

    public function handle(): int
    {
        DB::table('llm_requests')->truncate();
        $this->info('Truncated llm_requests. Reseeding…');

        $gen = $this->backfillGeneration();
        $runs = $this->backfillRuns();

        $this->info("Done. Generation rows: {$gen}. Run (agent) rows: {$runs}.");

        return self::SUCCESS;
    }

    /** agent_generation_logs → llm_requests (planner phases, with session_id). */
    private function backfillGeneration(): int
    {
        $inserted = 0;

        DB::table('agent_generation_logs')->orderBy('id')->chunk(200, function ($logs) use (&$inserted) {
            $rows = [];

            foreach ($logs as $log) {
                // provider string looks like "ollama (intent_analysis)" or "anthropic".
                $provider = strtolower(trim(explode('(', (string) $log->provider)[0]));

                // "deterministic" plan assembly is not an LLM call — skip it.
                if (! in_array($provider, ['ollama', 'openai', 'anthropic'], true)) {
                    continue;
                }

                $phase = preg_match('/\(([^)]+)\)/', (string) $log->provider, $m) ? trim($m[1]) : null;
                $pt = $log->prompt_tokens;
                $ct = $log->completion_tokens;

                // One "auto agent creator" run = one planner token. Fall back to
                // flow+date for legacy rows that lost their token.
                $date = $log->created_at ? Carbon::parse($log->created_at)->format('Y-m-d') : 'na';
                $sessionId = $log->token ?: 'genflow:'.($log->flow_id ?? '0').':'.$date;

                $rows[] = [
                    'provider' => $provider,
                    'model' => $log->model,
                    'kind' => 'chat_json',
                    'purpose' => $phase ? 'planner:'.$phase : 'planner',
                    'session_id' => $sessionId,
                    'company_id' => $log->company_id,
                    'flow_id' => $log->flow_id,
                    'flow_run_id' => null,
                    'node_run_id' => null,
                    'agent_name' => null,
                    'agent_type' => null,
                    'system_prompt' => $log->system_prompt,
                    'user_message' => $log->user_message,
                    'response_text' => $log->raw_response,
                    'options' => $log->options ? json_encode($log->options, JSON_UNESCAPED_UNICODE) : null,
                    'prompt_tokens' => $pt,
                    'completion_tokens' => $ct,
                    'total_tokens' => ($pt || $ct) ? (int) $pt + (int) $ct : null,
                    'cost_usd' => $log->cost_usd ?? 0,
                    'duration_ms' => $log->duration_ms,
                    'status' => $log->status ?: 'completed',
                    'error' => $log->error,
                    'created_at' => $log->created_at,
                    'updated_at' => $log->updated_at,
                ];
            }

            if ($rows !== []) {
                DB::table('llm_requests')->insert($rows);
                $inserted += count($rows);
            }
        });

        return $inserted;
    }

    /** node_runs → llm_requests (one runtime row per executed agent node). */
    private function backfillRuns(): int
    {
        $inserted = 0;

        DB::table('node_runs')
            ->leftJoin('flow_nodes', 'node_runs.flow_node_id', '=', 'flow_nodes.id')
            ->leftJoin('flow_runs', 'node_runs.flow_run_id', '=', 'flow_runs.id')
            ->leftJoin('flows', 'flow_runs.flow_id', '=', 'flows.id')
            ->whereNotNull('node_runs.model_used')
            ->where('node_runs.model_used', '!=', 'm')   // legacy test junk
            ->orderBy('node_runs.id')
            ->select([
                'node_runs.id as nr_id',
                'node_runs.flow_run_id',
                'node_runs.node_key',
                'node_runs.input',
                'node_runs.output',
                'node_runs.raw_output',
                'node_runs.model_used',
                'node_runs.tokens_used',
                'node_runs.prompt_tokens',
                'node_runs.completion_tokens',
                'node_runs.cost_usd',
                'node_runs.duration_ms',
                'node_runs.status',
                'node_runs.created_at',
                'node_runs.updated_at',
                'node_runs.params_snapshot',
                'flow_nodes.name as agent_name',
                'flow_nodes.type as agent_type',
                'flow_nodes.system_prompt',
                'flows.id as flow_id',
                'flows.company_id',
            ])
            ->chunk(200, function ($nodes) use (&$inserted) {
                $rows = [];

                foreach ($nodes as $n) {
                    $provider = PaidModel::provider($n->model_used) ?? 'ollama';
                    $pt = $n->prompt_tokens;
                    $ct = $n->completion_tokens;
                    $total = ($pt || $ct) ? (int) $pt + (int) $ct : ($n->tokens_used ?: null);

                    $rows[] = [
                        'provider' => $provider,
                        'model' => $n->model_used,
                        'kind' => 'chat',
                        'purpose' => 'runtime',
                        'session_id' => null,
                        'company_id' => $n->company_id,
                        'flow_id' => $n->flow_id,
                        'flow_run_id' => $n->flow_run_id,
                        'node_run_id' => $n->nr_id,
                        'agent_name' => $n->agent_name ?: $n->node_key,
                        'agent_type' => $n->agent_type,
                        'system_prompt' => $n->system_prompt,
                        'user_message' => $n->input,
                        'response_text' => $n->output ?: $n->raw_output,
                        'options' => $n->params_snapshot,
                        'prompt_tokens' => $pt,
                        'completion_tokens' => $ct,
                        'total_tokens' => $total,
                        'cost_usd' => $n->cost_usd ?? 0,
                        'duration_ms' => $n->duration_ms,
                        'status' => $n->status ?: 'completed',
                        'error' => null,
                        'created_at' => $n->created_at,
                        'updated_at' => $n->updated_at,
                    ];
                }

                if ($rows !== []) {
                    DB::table('llm_requests')->insert($rows);
                    $inserted += count($rows);
                }
            });

        return $inserted;
    }
}
