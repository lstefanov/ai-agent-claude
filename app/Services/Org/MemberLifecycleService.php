<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\OrgMember;
use App\Models\OrgProposal;

/**
 * Жизнен цикъл на член (§fire-guard) — детерминистичният „мозък" за това КОГА автономно
 * съкращение е допустимо. Промптовете са меки (LLM ги пренебрегва); реалната предпазна мрежа
 * е този код. Член става „подлежащ на оценка за съкращение" само при И стаж, И реален шанс:
 * минал изпитателен срок (стаж), натрупани изпълнения с QA данни и без активна работа/задачи.
 * Ползва се от OrgProposalService (create-time guard), OrgReviewService (обогатяване на LLM
 * състоянието) и DecisionController (approve-time backstop). Гейтът важи САМО за автономните
 * предложения — ръчното човешко съкращение е явен override и не минава оттук.
 */
class MemberLifecycleService
{
    /** Активните (нетерминални) статуси на задача — „член има работа сега". */
    private const ACTIVE_TASK_STATUSES = ['proposed', 'generating', 'pending_approval', 'ready', 'running'];

    public function __construct(private MemberMemoryService $memory) {}

    /** Стаж в дни (floored). null created_at → 0 (третира се като изпитателен, безопасно). */
    public function tenureDays(OrgMember $member): int
    {
        return (int) ($member->created_at ?? now())->diffInDays(now());
    }

    /** В изпитателен срок → под минималния стаж за оценка. */
    public function isProbation(OrgMember $member): bool
    {
        return $this->tenureDays($member) < (int) config('organization.lifecycle.fire_grace_days', 14);
    }

    /** Брой активни (нетерминални) задачи на члена — каноничното „зает сега". */
    public function activeTaskCount(OrgMember $member): int
    {
        return AssistantTask::where('org_member_id', $member->id)
            ->whereIn('status', self::ACTIVE_TASK_STATUSES)
            ->count();
    }

    /**
     * Има ли висящо task-предложение в Кутията към този член. ВАЖНО: точният бъг е тук —
     * задачата на директора от екрана е още ПРЕДЛОЖЕНИЕ (OrgProposal), не материализирана
     * AssistantTask, така че проверка само по AssistantTask би пропуснала противоречието.
     */
    public function hasPendingTaskProposal(OrgMember $member): bool
    {
        return OrgProposal::where('company_id', $member->company_id)
            ->where('type', 'task')
            ->where('status', 'pending')
            ->get()
            ->contains(fn (OrgProposal $p) => $this->payloadTargetsMember($p, $member->id));
    }

    /** Членът е с активна работа (задачи или чакащо task-предложение) → съкращението е противоречиво. */
    public function hasActiveWork(OrgMember $member): bool
    {
        return $this->activeTaskCount($member) > 0 || $this->hasPendingTaskProposal($member);
    }

    /**
     * Контекст за LLM състоянието (OrgReviewService) — стаж/изпитателен/задачи + KPI. Един
     * runStats вътре. avg_qa остава null при липса на изпълнения (humanize → „няма данни",
     * НЕ „лош") — промптът изрично казва, че липсата на данни за нов член не е слабост.
     *
     * @return array{tenure_days: int, is_probation: bool, tasks: int, runs: int, avg_qa: float|null}
     */
    public function context(OrgMember $member): array
    {
        $stats = $this->memory->runStats($member);

        return [
            'tenure_days' => $this->tenureDays($member),
            'is_probation' => $this->isProbation($member),
            'tasks' => $this->activeTaskCount($member),
            'runs' => $stats['runs'],
            'avg_qa' => $stats['avg_qa'],
        ];
    }

    /**
     * Причината автономно съкращение да е блокирано, или null ако е допустимо. Редът е по
     * евтиност и покритие: изпитателен срок (хваща докладвания случай сам) → недостатъчно
     * доказателства (< fire_min_runs завършени изпълнения) → активна работа (противоречие).
     */
    public function fireBlockReason(OrgMember $member): ?string
    {
        if ($this->isProbation($member)) {
            return 'probation';
        }

        if ($this->memory->runStats($member)['runs'] < (int) config('organization.lifecycle.fire_min_runs', 3)) {
            return 'insufficient_evidence';
        }

        if ($this->hasActiveWork($member)) {
            return 'has_active_work';
        }

        return null;
    }

    /** Човешко (BG) обяснение на причината за блокиране — за одит/UI. */
    public function fireBlockLabel(string $reason): string
    {
        return match ($reason) {
            'no_target' => 'няма посочен член',
            'probation' => 'в изпитателен срок',
            'insufficient_evidence' => 'няма достатъчно данни за представянето',
            'has_active_work' => 'има активна работа/задачи',
            default => $reason,
        };
    }

    /** Payload-ът на предложението сочи ли този член (по org_member_id или target_member_id). */
    private function payloadTargetsMember(OrgProposal $proposal, int $memberId): bool
    {
        $payload = (array) $proposal->payload;
        $target = $payload['org_member_id'] ?? $payload['target_member_id'] ?? null;

        return $target !== null && (int) $target === $memberId;
    }
}
