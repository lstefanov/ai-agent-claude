<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\Director;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Services\GeneratorService;
use App\Services\Org\Billing\InsufficientCreditsException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Директорът като реален разсъждаващ supervisor-агент (§8). Персоната му ОБАГРЯ
 * преценката (тон/подход), НЕ компетентността (ревюто остава persona-неутрално).
 */
class DirectorAgentService
{
    public function __construct(
        private PersonaService $personas,
        private GeneratorService $generator,
        private TaskRunService $runner,
    ) {}

    /**
     * Цикълът от §8: чете състояние → планира през персоната → пуска одобрени задачи
     * (lazy-gen при липса на flow) → ревюира → отчита към Управителя (org_event review).
     *
     * @return array{ran: array<int>, proposals: array<int>, report: string}
     */
    public function tick(Director $director, string $trigger = 'scheduled'): array
    {
        $version = $director->orgVersion;
        $company = $version->company;
        $directorMember = $director->orgMember;
        $directorMember?->loadMissing('persona');
        $policy = $directorMember ? $this->personas->runtimePolicy($directorMember) : [];
        $taskLimit = max(1, (int) ($policy['director_task_limit'] ?? 1));

        $assistantMembers = $this->assistantMembers($director);

        // Пуска САМО одобрените задачи (ready + active flow) на отдела. Предложените
        // (pending_approval) чакат човешко одобрение — директорът не ги пуска (ревизиран
        // lifecycle). Scheduled задачите минават през ScheduledTaskJob (cron).
        $ran = [];
        $tasks = AssistantTask::whereIn('org_member_id', $assistantMembers->pluck('id'))
            ->where('status', 'ready')
            ->where('trigger', '!=', 'scheduled')
            ->oldest('id')
            ->limit($taskLimit)
            ->get();

        foreach ($tasks as $task) {
            try {
                $result = $this->runner->requestRun($task, runAfterGenerate: true);
                if (($result['status'] ?? null) === 'running') {
                    $ran[] = $task->id;
                }
            } catch (InsufficientCreditsException) {
                $company->orgEvents()->create([
                    'type' => 'review',
                    'org_member_id' => $directorMember->id,
                    'summary' => "Пропуснато (недостатъчно кредити): {$task->title}",
                    'actor' => 'director',
                ]);
            } catch (\Throwable $e) {
                Log::warning('[DirectorTick] task '.$task->id.' failed: '.$e->getMessage());
            }
        }

        // Отчет към Управителя — през персоната (тон), но фактологичен.
        $report = $this->report($directorMember, $assistantMembers, $ran, $trigger);

        // No-op guard + dedup (§11.3): пиши събитие в хрониката САМО при реална работа
        // (нещо е пуснато) И когато текстът се различава от последния отчет на този директор.
        $lastReview = $company->orgEvents()->where('type', 'review')
            ->where('org_member_id', $directorMember->id)->latest('id')->value('summary');
        if ($ran !== [] && trim((string) $lastReview) !== trim($report)) {
            $company->orgEvents()->create([
                'type' => 'review',
                'org_version_id' => $version->id,
                'org_member_id' => $directorMember->id,
                'summary' => $report,
                'actor' => 'director',
            ]);
        }

        return ['ran' => $ran, 'proposals' => [], 'report' => $report];
    }

    /** Структурно предложение → durable org_proposal(pending) за Кутията (§A7). */
    public function proposeDecision(Company $company, string $type, array $payload, string $rationale): OrgProposal
    {
        return OrgProposal::create([
            'company_id' => $company->id,
            'type' => $type,
            'payload' => $payload + ['rationale' => $rationale],
            'base_org_version_id' => $company->active_org_version_id,
        ]);
    }

    /** @return Collection<int, OrgMember> */
    private function assistantMembers(Director $director): Collection
    {
        return $director->assistants()->with('orgMember')->get()
            ->pluck('orgMember')->filter()->values();
    }

    /** Кратък отчет през персоната на директора (тон), фактологичен. */
    private function report(OrgMember $directorMember, Collection $assistantMembers, array $ran, string $trigger): string
    {
        $persona = $this->personas->compileSystemPrompt($directorMember);
        $policy = $this->personas->runtimePolicy($directorMember);
        $system = trim($persona."\n\n".'Ти си Директор. Напиши КРАТЪК отчет (2–3 изречения) към Управителя '
            .'на български, в своя тон: какво пусна, как върви отделът, и една препоръка. Без вода.');
        $user = "Тригер: {$trigger}. Асистенти: ".$assistantMembers->count()
            .'. Пуснати задачи този тик: '.count($ran).'.';

        try {
            return trim($this->generator->chat($system, $user, ['temperature' => (float) ($policy['temperature'] ?? 0.5), 'num_predict' => 400]));
        } catch (\Throwable $e) {
            Log::info('[DirectorTick] report LLM failed: '.$e->getMessage());

            return 'Отдел прегледан. Пуснати задачи: '.count($ran).'.';
        }
    }
}
