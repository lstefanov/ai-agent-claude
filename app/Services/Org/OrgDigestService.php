<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\FlowRun;
use App\Models\OrgEvent;
use App\Models\OrgMember;
use App\Services\GeneratorService;
use App\Support\PromptData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Дневен „standup" дайджест (слой живост §3): Управителят разказва деня на екипа —
 * предложени/одобрени/пуснати/завършени задачи, чакащи решения, кой служител се отличи.
 * Данните идват от хрониката (org_events), статусите на задачите и днешните изпълнения.
 * Един евтин LLM разказ, записан като org_event(daily_digest) → пулс на Таблото + Хроника.
 */
class OrgDigestService
{
    public function __construct(
        private GeneratorService $generator,
        private PersonaService $personas,
    ) {}

    /** Вече има дайджест за днес? Пази от двойно харчене при повторен dispatch. */
    public function hasDigestToday(Company $company): bool
    {
        return $company->orgEvents()
            ->where('type', 'daily_digest')
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
    }

    /** Сглобява и записва дневния дайджест като org_event(daily_digest). */
    public function generate(Company $company): ?OrgEvent
    {
        $manager = $company->manager;
        $version = $company->activeOrgVersion;
        if (! $manager || ! $version) {
            return null;
        }

        $data = $this->collect($company);
        $summary = $this->narrate($manager, $data);

        return $company->orgEvents()->create([
            'type' => 'daily_digest',
            'org_version_id' => $version->id,
            'org_member_id' => $manager->id,
            'summary' => $summary,
            'actor' => 'manager',
            'meta' => $data,
        ]);
    }

    /**
     * Сурова картина на деня от трите източника: хроника, статуси на задачите, изпълнения.
     *
     * @return array<string, mixed>
     */
    private function collect(Company $company): array
    {
        $start = now()->startOfDay();

        // 1) Днешните събития от хрониката (без самите дайджести).
        $events = $company->orgEvents()
            ->where('created_at', '>=', $start)
            ->where('type', '!=', 'daily_digest')
            ->latest('id')->take(80)->get();

        // 2) Текуща снимка на задачите по статус (чакащи решение/за изпълнение).
        $assistantIds = $company->members()->where('kind', 'assistant')->pluck('id');
        $taskStatus = AssistantTask::whereIn('org_member_id', $assistantIds)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        // 3) Днешните изпълнения + активност per член (по собственика на задачата).
        $flowIds = $company->flows()->pluck('id');
        $runs = FlowRun::whereIn('flow_id', $flowIds)
            ->where('created_at', '>=', $start)
            ->get(['id', 'status', 'context']);

        $taskIds = $runs->pluck('context.assistant_task_id')->filter()->unique()->values();
        $tasks = AssistantTask::with('orgMember.persona')->whereIn('id', $taskIds)->get()->keyBy('id');

        $perMember = [];
        foreach ($runs as $run) {
            $member = $this->memberForRun($run, $tasks);
            if (! $member) {
                continue;
            }
            $name = $member->fullName();
            $perMember[$name] ??= ['completed' => 0, 'failed' => 0, 'active' => 0];
            $bucket = match ($run->status) {
                'completed' => 'completed',
                'failed' => 'failed',
                default => 'active',
            };
            $perMember[$name][$bucket]++;
        }

        return [
            'date' => $start->toDateString(),
            'event_counts' => $events->groupBy('type')->map->count()->all(),
            'events' => $events->take(30)->map(fn (OrgEvent $e) => [
                'type' => $e->type,
                'actor' => $e->actor,
                'summary' => Str::limit((string) $e->summary, 160),
            ])->all(),
            'task_status' => $taskStatus->all(),
            'run_counts' => $runs->groupBy('status')->map->count()->all(),
            'per_member' => $perMember,
        ];
    }

    /** Членът-собственик на задачата зад едно изпълнение (или null). */
    private function memberForRun(FlowRun $run, $tasks): ?OrgMember
    {
        $taskId = $run->context['assistant_task_id'] ?? null;

        return $taskId ? $tasks->get($taskId)?->orgMember : null;
    }

    /** Управителят разказва деня (евтин assist call); детерминистичен fallback при провал. */
    private function narrate(OrgMember $manager, array $data): string
    {
        $persona = $this->personas->compileSystemPrompt($manager);
        $policy = $this->personas->runtimePolicy($manager);

        $system = trim($persona."\n\n".'Ти си Управителят. Напиши КРАТЪК дневен преглед на работата на '
            .'екипа днес — какво се случи, какво чака решение и кои служители се отличиха. Един абзац, '
            .'3–5 изречения, на български, в първо лице, разказвателно и човешки. Без таблици, без '
            .'списъци, без числови справки заради самите числа. '.PromptData::NO_TECH_TERMS);
        $user = 'Данни за деня: '.json_encode(PromptData::humanize($data), JSON_UNESCAPED_UNICODE);

        try {
            $text = trim($this->generator->assist($system, $user, [
                'temperature' => (float) ($policy['planner_temperature'] ?? 0.5),
                'num_predict' => 600,
            ]));

            if ($text !== '') {
                return $text;
            }
        } catch (\Throwable $e) {
            Log::info('[OrgDigest] LLM failed, deterministic fallback: '.$e->getMessage());
        }

        return $this->fallback($data);
    }

    /** Прост наративен fallback от числата — никога не оставяме празен дайджест. */
    private function fallback(array $data): string
    {
        $runs = $data['run_counts'] ?? [];
        $tasks = $data['task_status'] ?? [];

        $completed = (int) ($runs['completed'] ?? 0);
        $failed = (int) ($runs['failed'] ?? 0);
        $pending = (int) ($tasks['pending_approval'] ?? 0);
        $ready = (int) ($tasks['ready'] ?? 0);

        $parts = [];
        if ($completed || $failed) {
            $parts[] = $completed.' завършени изпълнения днес'.($failed ? " и {$failed} провалени" : '');
        }
        if ($ready) {
            $parts[] = "{$ready} задачи чакат да бъдат пуснати";
        }
        if ($pending) {
            $parts[] = "{$pending} предложения чакат твоето решение";
        }

        if ($parts === []) {
            return 'Спокоен ден — екипът няма значима активност за отбелязване.';
        }

        return 'Днес: '.implode('; ', $parts).'.';
    }
}
