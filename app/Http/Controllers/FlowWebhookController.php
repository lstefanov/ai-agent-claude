<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowWebhookController extends Controller
{
    public function trigger(Request $request, Flow $flow): JsonResponse
    {
        if (! $flow->webhook_secret) {
            return response()->json(['error' => 'Webhook не е активиран за този flow.'], 403);
        }

        $token = $request->query('token');

        if (! hash_equals($flow->webhook_secret, (string) $token)) {
            return response()->json(['error' => 'Невалиден токен.'], 401);
        }

        $activeAgents = $flow->agents()->where('is_active', true)->count();

        if ($activeAgents === 0) {
            return response()->json(['error' => 'Флоуът няма активни агенти.'], 422);
        }

        $payload = $request->json()->all();

        $flowRun = FlowRun::create([
            'flow_id'      => $flow->id,
            'status'       => 'pending',
            'triggered_by' => 'webhook',
            'context'      => array_merge(
                ['webhook_payload' => $payload],
                ['step_qa_policies' => []],
            ),
            'started_at' => null,
        ]);

        $php     = env('PHP_CLI_BINARY', '/opt/homebrew/bin/php');
        $artisan = base_path('artisan');
        $logFile = storage_path("logs/run-{$flowRun->id}.log");

        exec("{$php} {$artisan} flows:execute {$flowRun->id} >> {$logFile} 2>&1 &");

        return response()->json([
            'success'     => true,
            'flow_run_id' => $flowRun->id,
            'status_url'  => route('flow-runs.poll', $flowRun),
            'view_url'    => route('flow-runs.show', $flowRun),
        ], 202);
    }
}
