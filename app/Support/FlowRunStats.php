<?php

namespace App\Support;

use App\Models\FlowRun;
use Illuminate\Support\Carbon;

/**
 * Run-статистики per flow (последен run + броячи active/completed/failed + последно пускане)
 * в две групирани заявки — без N+1 при изброяване на много flow-ове наведнъж.
 */
class FlowRunStats
{
    private const ACTIVE = ['pending', 'running', 'waiting_approval'];

    /**
     * @param  array<int>  $flowIds
     * @return array<int, array{latest: ?array, active: int, completed: int, failed: int, last_run_at: ?Carbon}>
     */
    public static function forFlows(array $flowIds): array
    {
        if (empty($flowIds)) {
            return [];
        }

        $byStatus = FlowRun::query()
            ->whereIn('flow_id', $flowIds)
            ->selectRaw('flow_id, status, COUNT(*) as c, MAX(created_at) as last_at')
            ->groupBy('flow_id', 'status')
            ->get();

        // Последният run за статус-badge — max(id) per flow.
        $latestIds = FlowRun::query()
            ->whereIn('flow_id', $flowIds)
            ->selectRaw('MAX(id) as max_id')
            ->groupBy('flow_id')
            ->pluck('max_id');
        $latest = FlowRun::whereIn('id', $latestIds)->get(['id', 'flow_id', 'status'])->keyBy('flow_id');

        $stats = [];
        foreach ($flowIds as $fid) {
            $stats[$fid] = ['latest' => null, 'active' => 0, 'completed' => 0, 'failed' => 0, 'last_run_at' => null];
        }
        foreach ($byStatus as $row) {
            $s = &$stats[$row->flow_id];
            if (in_array($row->status, self::ACTIVE, true)) {
                $s['active'] += (int) $row->c;
            } elseif ($row->status === 'completed') {
                $s['completed'] += (int) $row->c;
            } elseif ($row->status === 'failed') {
                $s['failed'] += (int) $row->c;
            }
            $at = $row->last_at ? Carbon::parse($row->last_at) : null;
            if ($at && (! $s['last_run_at'] || $at->gt($s['last_run_at']))) {
                $s['last_run_at'] = $at;
            }
            unset($s);
        }
        foreach ($latest as $fid => $run) {
            $stats[$fid]['latest'] = ['id' => $run->id, 'status' => $run->status];
        }

        return $stats;
    }
}
