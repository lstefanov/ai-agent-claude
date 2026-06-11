<?php

namespace App\Console\Commands;

use App\Models\FlowRun;
use App\Models\NodeRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WatchStuckFlowRunsCommand extends Command
{
    protected $signature = 'flows:watchdog {--minutes=10 : Minutes without run/node activity before a run is considered stuck}';

    protected $description = 'Fail stuck flow runs whose queue jobs are orphaned or whose flows worker heartbeat is missing.';

    private const FLOWS_QUEUE_HEARTBEAT_KEY = 'queue.heartbeat.flows';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);
        $heartbeatAlive = Cache::has(self::FLOWS_QUEUE_HEARTBEAT_KEY);
        $failed = 0;

        FlowRun::whereIn('status', ['pending', 'running'])
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('updated_at')
                    ->orWhere('updated_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->chunkById(50, function ($runs) use ($heartbeatAlive, $cutoff, &$failed) {
                foreach ($runs as $run) {
                    $flowRun = $run instanceof FlowRun ? $run : FlowRun::find($run->id);
                    if (! $flowRun) {
                        continue;
                    }

                    $queuedJobIds = $this->queuedJobIdsForRun($flowRun->id);
                    $latestNodeRun = NodeRun::where('flow_run_id', $flowRun->id)->latest('updated_at')->first();

                    if ($latestNodeRun && $latestNodeRun->updated_at?->gt($cutoff)) {
                        continue;
                    }

                    if ($latestNodeRun?->status === 'running' && ! $this->runningNodeTimedOut($latestNodeRun)) {
                        continue;
                    }

                    if ($heartbeatAlive && $queuedJobIds !== []) {
                        continue;
                    }

                    $reason = $heartbeatAlive
                        ? 'Изпълнението е прекъснато: няма чакащи queue jobs за този run.'
                        : 'Изпълнението е прекъснато: няма активен flows queue worker.';

                    $this->failRun($flowRun, $reason, $queuedJobIds);
                    $failed++;
                }
            });

        $this->info("Watchdog marked {$failed} stuck flow run(s) as failed.");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function queuedJobIdsForRun(int $flowRunId): array
    {
        $needle = 's:9:"flowRunId";i:'.$flowRunId.';';

        return DB::table('jobs')
            ->where('queue', 'flows')
            ->where('payload', 'like', '%'.$needle.'%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function runningNodeTimedOut(NodeRun $nodeRun): bool
    {
        $timeout = 1200;
        $buffer = 300;

        return $nodeRun->started_at !== null
            && $nodeRun->started_at->lte(now()->subSeconds($timeout + $buffer));
    }

    /**
     * @param  array<int, int>  $queuedJobIds
     */
    private function failRun(FlowRun $run, string $reason, array $queuedJobIds): void
    {
        DB::transaction(function () use ($run, $reason, $queuedJobIds) {
            $context = (array) ($run->context ?? []);
            $context['failure_message'] = $reason;

            $run->update([
                'status' => 'failed',
                'context' => $context,
                'completed_at' => now(),
            ]);

            NodeRun::where('flow_run_id', $run->id)
                ->whereIn('status', ['pending', 'running'])
                ->update([
                    'status' => 'failed',
                    'error' => $reason,
                    'completed_at' => now(),
                ]);

            if ($queuedJobIds !== []) {
                DB::table('jobs')->whereIn('id', $queuedJobIds)->delete();
            }

            DB::table('job_batches')
                ->where('name', 'like', 'flow-run-'.$run->id.'-wave-%')
                ->delete();
        });
    }
}
