<?php

namespace App\Console\Commands;

use App\Models\FlowRun;
use App\Models\NodeRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark every pending/running FlowRun as failed and purge the queue rows that
 * back it. Used as a one-shot cleanup when workers were down (or after pivoting
 * the run-execution architecture) and old jobs would otherwise flood the queue
 * the moment workers come back online.
 *
 * Run history is preserved — only status/timestamps and the queue tables change.
 */
class CancelStuckFlowRunsCommand extends Command
{
    protected $signature = 'flows:cancel-stuck {--reason= : Optional reason recorded in context.failure_message}';

    protected $description = 'Mark all pending/running FlowRuns as failed and purge their queued jobs/batches.';

    public function handle(): int
    {
        $reason = $this->option('reason') ?: 'Изпълнението е прекъснато ръчно (cleanup).';

        $runs = FlowRun::whereIn('status', ['pending', 'running'])->get();
        $runIds = $runs->pluck('id')->all();

        DB::transaction(function () use ($runs, $runIds, $reason) {
            foreach ($runs as $run) {
                $context = (array) ($run->context ?? []);
                $context['failure_message'] = $reason;

                $run->update([
                    'status' => 'failed',
                    'context' => $context,
                    'completed_at' => $run->completed_at ?: now(),
                ]);
            }

            if (! empty($runIds)) {
                // Drop any still-running node runs to failed so the UI / final composer
                // skip them cleanly.
                NodeRun::whereIn('flow_run_id', $runIds)
                    ->whereIn('status', ['pending', 'running'])
                    ->update([
                        'status' => 'failed',
                        'error' => $reason,
                        'completed_at' => now(),
                    ]);
            }

            // Purge the actual queue rows so workers don't pick them up on restart.
            // `jobs.payload` is a JSON string that contains the serialized job
            // (which embeds the flow_run_id). A LIKE per id covers every job
            // tied to a cancelled run regardless of batch.
            // ExecuteNodeJob is PHP-serialized into jobs.payload; flowRunId looks
            // like `s:9:"flowRunId";i:55;` — match that exact integer with no
            // false positives on substrings (e.g. 5 matching 55, 155).
            $jobsDeleted = 0;
            foreach ($runIds as $id) {
                $needle = 's:9:"flowRunId";i:' . (int) $id . ';';
                $jobsDeleted += DB::table('jobs')
                    ->where('payload', 'like', '%' . $needle . '%')
                    ->delete();
            }

            // Sweep ALL orphaned flows-queue jobs whose referenced FlowRun is no
            // longer running (e.g. stale jobs from runs that were already failed
            // before this command ever ran — workers down for a long stretch).
            $aliveRunIds = FlowRun::whereIn('status', ['pending', 'running'])
                ->pluck('id')->all();
            $jobsForCheck = DB::table('jobs')->where('queue', 'flows')->get(['id', 'payload']);
            foreach ($jobsForCheck as $j) {
                $cmd = json_decode($j->payload, true)['data']['command'] ?? '';
                if (preg_match('/s:9:"flowRunId";i:(\d+);/', $cmd, $m)) {
                    if (! in_array((int) $m[1], $aliveRunIds, true)) {
                        DB::table('jobs')->where('id', $j->id)->delete();
                        $jobsDeleted++;
                    }
                }
            }

            // Sweep ALL job_batches whose flow run is no longer alive.
            $aliveRunIdsForBatches = FlowRun::whereIn('status', ['pending', 'running'])
                ->pluck('id')->all();
            $batchesDeleted = 0;
            $batchRows = DB::table('job_batches')->where('name', 'like', 'flow-run-%-wave-%')->get(['id', 'name']);
            foreach ($batchRows as $b) {
                if (preg_match('/^flow-run-(\d+)-wave-\d+$/', $b->name, $m)) {
                    if (! in_array((int) $m[1], $aliveRunIdsForBatches, true)) {
                        DB::table('job_batches')->where('id', $b->id)->delete();
                        $batchesDeleted++;
                    }
                }
            }

            $this->info("Изчистени jobs: {$jobsDeleted}, batches: {$batchesDeleted}.");
        });

        if (empty($runIds)) {
            $this->info('Нямаше pending/running runs за маркиране (само orphan jobs/batches са изчистени).');
        } else {
            $this->info('Маркирани като failed: ' . count($runIds) . ' run(-а): ' . implode(', ', $runIds));
        }

        return Command::SUCCESS;
    }
}
