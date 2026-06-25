<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Models\FlowRun;
use App\Models\OrgProposal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Кутията за решения е АДАПТЕР/АГРЕГАТОР (§0.5.7), не нова одобрителна машина. Обединява
 * два съществуващи източника: (а) durable org_proposals(pending) и (б) паузирани
 * human_approval runs. Паузираните runs минават през ApprovalService (единния boundary);
 * org предложенията носят mutable решението, а org_events остава append-only одитът.
 */
class DecisionBoxService
{
    public function __construct(private ApprovalService $approvals) {}

    /** Обединен списък „чакащи решения": org предложения + паузирани одобрения. */
    public function pending(Company $company): Collection
    {
        // (а) org предложения — durable mutable решение.
        $proposals = OrgProposal::where('company_id', $company->id)->pending()
            ->latest()->get()
            ->map(fn (OrgProposal $p) => [
                'kind' => 'proposal',
                'id' => $p->id,
                'type' => $p->type,
                'payload' => $p->payload,
                'created_at' => $p->created_at,
            ]);

        // (б) паузирани human_approval runs — преизползва съществуващия pause/resume.
        $runs = FlowRun::where('status', 'waiting_approval')
            ->whereHas('flow', fn ($q) => $q->where('company_id', $company->id))
            ->latest()->get()
            ->flatMap(fn (FlowRun $run) => collect($run->context['approvals'] ?? [])
                ->filter(fn ($a) => ($a['status'] ?? null) === 'pending')
                ->map(fn ($a, $nodeKey) => [
                    'kind' => 'run_approval',
                    'flow_run_id' => $run->id,
                    'node_key' => $nodeKey,
                    'node_name' => $a['node_name'] ?? $nodeKey,
                    'requested_at' => $a['requested_at'] ?? null,
                ])->values());

        return $proposals->concat($runs);
    }

    /**
     * Одобрение на org предложение с optimistic concurrency (§A7): остаряла
     * base_org_version_id → superseded + ре-ревю (НЕ материализира върху остаряла версия).
     * Само при съвпадаща база продължава към материализация (връща `materialize` тип за
     * по-горните фази 2/5/7). org_events записва взетото решение.
     *
     * @return array{ok: bool, superseded?: bool, materialize?: string, error?: string}
     */
    public function approveProposal(OrgProposal $proposal, ?User $user = null): array
    {
        if ($proposal->status !== 'pending') {
            return ['ok' => false, 'error' => 'Предложението вече е решено.'];
        }

        $company = $proposal->company;

        if ($proposal->base_org_version_id
            && $proposal->base_org_version_id !== $company->active_org_version_id) {
            $proposal->update(['status' => 'superseded']);

            return ['ok' => false, 'superseded' => true, 'error' => 'Организацията се промени междувременно — нужно е ре-ревю.'];
        }

        $proposal->update(['status' => 'approved', 'decided_by' => $user?->id, 'decided_at' => now()]);

        $company->orgEvents()->create([
            'type' => 'approval',
            'org_version_id' => $company->active_org_version_id,
            'summary' => "Одобрено предложение: {$proposal->type}",
            'meta' => ['proposal_id' => $proposal->id],
            'actor' => 'human',
        ]);

        return ['ok' => true, 'materialize' => $proposal->type];
    }

    /** Отхвърляне на org предложение (mutable решение + append-only одит). */
    public function rejectProposal(OrgProposal $proposal, ?User $user = null, ?string $comment = null): array
    {
        if ($proposal->status !== 'pending') {
            return ['ok' => false, 'error' => 'Предложението вече е решено.'];
        }

        $proposal->update(['status' => 'rejected', 'decided_by' => $user?->id, 'decided_at' => now()]);

        $proposal->company->orgEvents()->create([
            'type' => 'approval',
            'summary' => "Отхвърлено предложение: {$proposal->type}".($comment ? " ({$comment})" : ''),
            'meta' => ['proposal_id' => $proposal->id, 'rejected' => true],
            'actor' => 'human',
        ]);

        return ['ok' => true];
    }

    /** Паузиран run → единния resume-after-approval boundary (никакъв controller→controller call). */
    public function settleRunApproval(FlowRun $run, string $nodeKey, bool $approved, ?string $comment = null): array
    {
        return $this->approvals->settle($run, $nodeKey, $approved, $comment);
    }
}
