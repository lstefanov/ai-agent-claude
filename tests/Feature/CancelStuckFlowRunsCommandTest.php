<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FlowRun;
use App\Models\NodeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CancelStuckFlowRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_pending_running_runs_failed_and_purges_their_queue_rows(): void
    {
        $flow = $this->makeFlow();
        $node = $flow->nodes()->create([
            'node_key' => 'A', 'name' => 'A', 'type' => 'writer',
            'model' => 'test', 'prompt_template' => 'x',
        ]);

        $running = FlowRun::create(['flow_id' => $flow->id, 'status' => 'running']);
        $pending = FlowRun::create(['flow_id' => $flow->id, 'status' => 'pending']);
        $alreadyDone = FlowRun::create(['flow_id' => $flow->id, 'status' => 'completed']);

        // Running NodeRun for the running flow run — should be flipped to failed.
        $runningNr = NodeRun::create([
            'flow_run_id' => $running->id, 'flow_node_id' => $node->id,
            'node_key' => 'A', 'status' => 'running',
        ]);

        // Fake queue rows: a wave batch + a job whose serialized payload references
        // the running flow run id, plus an orphaned job referencing a never-known run.
        DB::table('job_batches')->insert([
            'id' => 'batch-1', 'name' => 'flow-run-'.$running->id.'-wave-0',
            'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]',
            'options' => '', 'created_at' => time(),
        ]);

        $payload = json_encode([
            'data' => [
                'command' => 'O:23:"App\\Jobs\\ExecuteNodeJob":3:{s:9:"flowRunId";i:'.$running->id.';s:10:"flowNodeId";i:'.$node->id.';s:7:"batchId";s:7:"batch-1";}',
            ],
        ]);
        DB::table('jobs')->insert([
            'queue' => 'flows', 'payload' => $payload,
            'attempts' => 0, 'available_at' => time(), 'created_at' => time(),
        ]);

        // Orphan job: references a flow run id that doesn't exist anywhere.
        $orphanPayload = json_encode([
            'data' => [
                'command' => 'O:23:"App\\Jobs\\ExecuteNodeJob":3:{s:9:"flowRunId";i:9999;s:10:"flowNodeId";i:1;s:7:"batchId";s:7:"batch-9";}',
            ],
        ]);
        DB::table('jobs')->insert([
            'queue' => 'flows', 'payload' => $orphanPayload,
            'attempts' => 0, 'available_at' => time(), 'created_at' => time(),
        ]);

        $this->artisan('flows:cancel-stuck')->assertExitCode(0);

        $this->assertSame('failed', $running->fresh()->status);
        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertSame('completed', $alreadyDone->fresh()->status, 'already-finished runs are left alone');

        $this->assertSame('failed', $runningNr->fresh()->status);

        $this->assertSame(0, DB::table('jobs')->count(), 'all flows-queue jobs (incl. orphans) are gone');
        $this->assertSame(0, DB::table('job_batches')->count(), 'orphaned batches are gone too');
    }

    private function makeFlow()
    {
        $company = Company::create([
            'name' => 'Co', 'description' => 'd', 'industry' => 'x', 'language' => 'bg',
        ]);

        return $company->flows()->create([
            'name' => 'F', 'description' => 'desc', 'status' => 'active',
        ]);
    }
}
