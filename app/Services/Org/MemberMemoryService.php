<?php

namespace App\Services\Org;

use App\Models\FlowMemory;
use App\Models\NodeRun;
use App\Models\OrgMember;
use Illuminate\Support\Collection;

/**
 * Памет и рефлексия per ЧЛЕН (§7.2) — owner-scope мост над FlowMemoryService. Паметта виси
 * на стабилния OrgMember (преживява реорганизациите): четем през всичките му задачи→flows,
 * за да се трупа поука per ЧОВЕК, не per flow. Не дублира embedding логиката — преизползва
 * вече distill-нати flow_memories (DistillFlowMemoryJob).
 */
class MemberMemoryService
{
    /** flow_id-тата на задачите на члена (owner scope). */
    private function flowIds(OrgMember $member): Collection
    {
        return $member->tasks()->whereNotNull('flow_id')->pluck('flow_id');
    }

    /** Поуки (lesson) на члена от миналите му runs — за рефлексия/препоръки. */
    public function lessons(OrgMember $member, int $limit = 8): Collection
    {
        $flowIds = $this->flowIds($member);
        if ($flowIds->isEmpty()) {
            return collect();
        }

        return FlowMemory::whereIn('flow_id', $flowIds)
            ->where('kind', 'lesson')
            ->latest('id')->take($limit)->get();
    }

    /** Брой минали успешни runs на члена (KPI вход). */
    public function runStats(OrgMember $member): array
    {
        $flowIds = $this->flowIds($member);
        if ($flowIds->isEmpty()) {
            return ['runs' => 0, 'avg_qa' => null];
        }

        $runs = NodeRun::whereHas('flowRun', fn ($q) => $q->whereIn('flow_id', $flowIds))
            ->whereNotNull('qa_score');

        return [
            'runs' => (clone $runs)->distinct('flow_run_id')->count('flow_run_id'),
            'avg_qa' => round((float) (clone $runs)->avg('qa_score'), 1) ?: null,
        ];
    }

    /**
     * Кратък рефлексивен блок за члена (поуки + KPI) — за препоръки/чат. Празен низ,
     * ако няма история. Чисто текстов синтез (без нов embedding/LLM ход — лек).
     */
    public function reflectionBlock(OrgMember $member): string
    {
        $lessons = $this->lessons($member, 5);
        if ($lessons->isEmpty()) {
            return '';
        }

        $lines = ['[ПОУКИ ОТ МИНАЛИ ИЗПЪЛНЕНИЯ]'];
        foreach ($lessons as $lesson) {
            $lines[] = '- '.mb_substr((string) ($lesson->title ?: $lesson->summary), 0, 200);
        }

        return implode("\n", $lines);
    }
}
