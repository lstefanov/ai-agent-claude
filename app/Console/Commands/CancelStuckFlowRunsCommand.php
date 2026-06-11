<?php

namespace App\Console\Commands;

use App\Models\FlowRun;
use App\Models\NodeRun;
use App\Support\FlowsQueueInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark every pending/running FlowRun as failed and purge the queue entries that
 * back it. Used as a one-shot cleanup when workers were down (or after pivoting
 * the run-execution architecture) and old jobs would otherwise flood the queue
 * the moment workers come back online.
 *
 * Run history is preserved — only status/timestamps, the Redis 'flows' queue and
 * the job_batches table change.
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
        });

        // Purge the Redis 'flows' queue: every payload for a run we just failed,
        // plus a full orphan sweep (stale jobs whose run is no longer alive —
        // e.g. workers were down for a long stretch). The inspector parses each
        // payload's serialized command to read its flowRunId.
        $queue = new FlowsQueueInspector('flows');
        $aliveRunIds = FlowRun::whereIn('status', ['pending', 'running'])->pluck('id')->all();
        $jobsDeleted = $queue->removeOrphans($aliveRunIds);

        // Sweep ALL job_batches whose flow run is no longer alive (still in MySQL).
        $batchesDeleted = 0;
        $batchRows = DB::table('job_batches')->where('name', 'like', 'flow-run-%-wave-%')->get(['id', 'name']);
        foreach ($batchRows as $b) {
            if (preg_match('/^flow-run-(\d+)-wave-\d+$/', $b->name, $m)) {
                if (! in_array((int) $m[1], $aliveRunIds, true)) {
                    DB::table('job_batches')->where('id', $b->id)->delete();
                    $batchesDeleted++;
                }
            }
        }

        $this->info("Изчистени jobs: {$jobsDeleted}, batches: {$batchesDeleted}.");

        if (empty($runIds)) {
            $this->info('Нямаше pending/running runs за маркиране (само orphan jobs/batches са изчистени).');
        } else {
            $this->info('Маркирани като failed: '.count($runIds).' run(-а): '.implode(', ', $runIds));
        }

        return Command::SUCCESS;
    }
}
