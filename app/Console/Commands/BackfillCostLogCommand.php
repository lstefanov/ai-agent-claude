<?php

namespace App\Console\Commands;

use App\Models\AgentGenerationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill of the unified cost log (llm_requests) from the historical
 * planner audit (agent_generation_logs), so the admin Разходи page shows real
 * past activity immediately. Going-forward calls are logged live at the
 * provider chokepoints; this only seeds history.
 *
 * Idempotency note: meant to run once on a fresh/empty llm_requests table
 * (the owner resets the DB rather than migrating). Re-running appends again.
 */
class BackfillCostLogCommand extends Command
{
    protected $signature = 'flows:backfill-cost-log {--force : Skip the confirmation when llm_requests already has rows}';

    protected $description = 'Backfill llm_requests from agent_generation_logs (historical planner activity)';

    public function handle(): int
    {
        $existing = DB::table('llm_requests')->count();
        if ($existing > 0 && ! $this->option('force')) {
            $this->warn("llm_requests already has {$existing} rows. Re-running will append duplicates.");
            if (! $this->confirm('Continue anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $inserted = 0;
        $skipped = 0;

        AgentGenerationLog::query()->orderBy('id')->chunk(200, function ($logs) use (&$inserted, &$skipped) {
            $rows = [];

            foreach ($logs as $log) {
                // provider string looks like "ollama (intent_analysis)" or "anthropic".
                $provider = strtolower(trim(explode('(', (string) $log->provider)[0]));

                // "deterministic" plan assembly is not an LLM call — skip it.
                if (! in_array($provider, ['ollama', 'openai', 'anthropic'], true)) {
                    $skipped++;

                    continue;
                }

                $phase = preg_match('/\(([^)]+)\)/', (string) $log->provider, $m) ? trim($m[1]) : null;
                $pt = $log->prompt_tokens;
                $ct = $log->completion_tokens;

                $rows[] = [
                    'provider' => $provider,
                    'model' => $log->model,
                    'kind' => 'chat_json',
                    'purpose' => $phase ? 'planner:'.$phase : 'planner',
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

        $this->info("Backfilled {$inserted} rows into llm_requests (skipped {$skipped} non-LLM/deterministic).");

        return self::SUCCESS;
    }
}
