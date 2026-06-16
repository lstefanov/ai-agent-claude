<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Services\GraphFlowExecutor;
use App\Support\QueueHeartbeat;
use Illuminate\Http\JsonResponse;

class FlowRunController extends Controller
{
    /** Стартира активната версия на Flow-а и връща URL за опростен прогрес. */
    public function run(Flow $flow, GraphFlowExecutor $executor): JsonResponse
    {
        $this->authorizeCompany($flow->company_id);

        if (! QueueHeartbeat::flowsAlive()) {
            return response()->json([
                'message' => 'Системата за изпълнение в момента не е активна. Опитай отново след малко.',
            ], 503);
        }

        $version = $flow->activeVersion;
        if (! $version || $version->nodes()->where('is_active', true)->doesntExist()) {
            return response()->json([
                'message' => 'Този Flow още не е готов за изпълнение.',
            ], 422);
        }

        $run = FlowRun::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
        ]);

        $executor->run($flow, 'manual', $run);

        return response()->json([
            'run_id' => $run->id,
            'poll_url' => route('client.runs.progress', $run),
        ]);
    }

    /** Опростен прогрес — без модели/цени/raw. Брои възли на ПИННАТАТА версия. */
    public function progress(FlowRun $run): JsonResponse
    {
        $this->authorizeCompany($run->flow->company_id);

        // Активни възли на изпълняваната версия, без inline qa_verifier гейтовете
        // (изпълнителят също ги изключва от вълните).
        $nodes = $run->flowVersion->nodes()
            ->where('is_active', true)
            ->where('type', '!=', 'qa_verifier')
            ->get(['node_key', 'name']);

        $total = $nodes->count();

        // Последен статус за всеки node_key (retry-тата правят по няколко записа).
        $latestByKey = $run->nodeRuns()
            ->whereIn('node_key', $nodes->pluck('node_key'))
            ->orderByDesc('id')
            ->get(['node_key', 'status'])
            ->groupBy('node_key')
            ->map(fn ($g) => $g->first()->status);

        $done = $latestByKey->filter(fn ($s) => $s === 'completed')->count();
        $runningKey = $latestByKey->filter(fn ($s) => $s === 'running')->keys()->first();

        $status = $run->status;
        $isDone = $status === 'completed';
        $isFailed = $status === 'failed';
        $underReview = $status === 'waiting_approval';

        $percent = $isDone ? 100 : ($total > 0 ? min(99, (int) round($done / $total * 100)) : 0);
        $stepIndex = min($total, $done + ($isDone ? 0 : 1));

        $stepLabel = $this->stepLabel($nodes, $runningKey, $status);

        return response()->json([
            'status' => $status,
            'percent' => $percent,
            'step_index' => $total > 0 ? $stepIndex : null,
            'step_total' => $total ?: null,
            'step_label' => $stepLabel,
            'done' => $isDone,
            'failed' => $isFailed,
            'under_review' => $underReview,
            'result_url' => $isDone ? route('client.runs.result', $run) : null,
            'error' => $isFailed ? 'Изпълнението не завърши успешно. Опитай пак.' : null,
        ]);
    }

    /** Изчистен изглед на крайния резултат. */
    public function result(FlowRun $run)
    {
        $this->authorizeCompany($run->flow->company_id);
        $run->load('flow');

        return view('client.runs.result', compact('run'));
    }

    /** Приятелски етикет за текущата стъпка (без вътрешна конфигурация). */
    private function stepLabel($nodes, ?string $runningKey, string $status): string
    {
        if ($status === 'completed') {
            return 'Готово';
        }
        if ($status === 'waiting_approval') {
            return 'Изчаква преглед';
        }
        if ($runningKey) {
            $name = (string) optional($nodes->firstWhere('node_key', $runningKey))->name;

            return $name !== '' ? $this->humanize($name) : 'Обработка…';
        }

        return $status === 'pending' ? 'Стартиране…' : 'Обработка…';
    }

    /** slug-подобни имена → четим текст ("deep_researcher" → "Deep researcher"). */
    private function humanize(string $name): string
    {
        if (str_contains($name, ' ') || ! str_contains($name, '_')) {
            return $name;
        }

        return ucfirst(str_replace('_', ' ', $name));
    }

    private function authorizeCompany(?int $companyId): void
    {
        abort_unless($companyId === (int) session('client_company_id'), 403);
    }
}
