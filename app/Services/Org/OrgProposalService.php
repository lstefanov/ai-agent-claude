<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Models\OrgMember;
use App\Models\OrgProposal;

/**
 * Единствената точка за създаване на OrgProposal (§fire-guard funnel). Тънка е нарочно: не поема
 * owner-selection/embeddings/dedup — извикващият подава готов payload. Единственият твърд
 * инвариант тук е автономното съкращение: `fire` предложение НЕ се създава за член в изпитателен
 * срок / без реален шанс / с активна работа (MemberLifecycle::fireBlockReason). За другите типове
 * guard-ът е no-op. Плюс само-корекция: възлагането на задача оттегля висящо съкращение на същия
 * член — точно това разтваря противоречивия сценарий „уволни го и му дай задача едновременно".
 * Не зависи от DecisionBoxService/OrgMutationService (за да остане ацикличен).
 */
class OrgProposalService
{
    public function __construct(private MemberLifecycleService $lifecycle) {}

    /**
     * Създава durable OrgProposal(pending) за Кутията, или null ако guard-ът го блокира.
     */
    public function create(Company $company, string $type, array $payload, ?string $rationale = null): ?OrgProposal
    {
        $target = $this->resolveTarget($company, $payload);

        if ($type === 'fire') {
            $reason = $target ? $this->lifecycle->fireBlockReason($target) : 'no_target';
            if ($reason !== null) {
                $this->logBlockedFire($company, $target, $reason, $payload);

                return null;
            }
        }

        // Само-корекция: нова задача за член → оттегли висящо съкращение за него (даден е шанс).
        if ($type === 'task' && $target) {
            $this->supersedePendingFires($company, $target);
        }

        return OrgProposal::create([
            'company_id' => $company->id,
            'type' => $type,
            'payload' => $rationale !== null ? $payload + ['rationale' => $rationale] : $payload,
            'base_org_version_id' => $company->active_org_version_id,
        ]);
    }

    /** LLM target_member_id/org_member_id → валиден OrgMember на компанията или null. */
    private function resolveTarget(Company $company, array $payload): ?OrgMember
    {
        $id = $payload['target_member_id'] ?? $payload['org_member_id'] ?? null;
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }

        return $company->members()->find((int) $id);
    }

    /** Одит за пропуснато автономно съкращение (за хрониката/дебъг). */
    private function logBlockedFire(Company $company, ?OrgMember $target, string $reason, array $payload): void
    {
        $name = $target?->fullName() ?? ($payload['title'] ?? 'член');

        $company->orgEvents()->create([
            'type' => 'fire_blocked',
            'org_version_id' => $company->active_org_version_id,
            'org_member_id' => $target?->id,
            'summary' => 'Пропуснато съкращение на '.$name.': '.$this->lifecycle->fireBlockLabel($reason),
            'meta' => ['reason' => $reason, 'proposed_by' => $payload['proposed_by'] ?? null],
            'actor' => 'manager',
        ]);
    }

    /** Маркира висящите fire-предложения за члена като superseded (възложена е работа → шанс). */
    private function supersedePendingFires(Company $company, OrgMember $member): void
    {
        $fires = OrgProposal::where('company_id', $company->id)
            ->where('type', 'fire')
            ->where('status', 'pending')
            ->get()
            ->filter(fn (OrgProposal $p) => (int) (($p->payload['target_member_id'] ?? $p->payload['org_member_id'] ?? 0)) === $member->id);

        foreach ($fires as $fire) {
            $fire->update(['status' => 'superseded']);
        }

        if ($fires->isNotEmpty()) {
            $company->orgEvents()->create([
                'type' => 'fire_blocked',
                'org_version_id' => $company->active_org_version_id,
                'org_member_id' => $member->id,
                'summary' => 'Оттеглено съкращение на '.$member->fullName().': възложена е нова задача.',
                'meta' => ['reason' => 'task_assigned', 'superseded' => $fires->pluck('id')->all()],
                'actor' => 'manager',
            ]);
        }
    }
}
