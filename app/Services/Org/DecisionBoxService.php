<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\FlowRun;
use App\Models\OrgProposal;
use App\Models\User;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\PlanLibraryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Кутията за решения е АДАПТЕР/АГРЕГАТОР (§0.5.7), не нова одобрителна машина. Обединява
 * два съществуващи източника: (а) durable org_proposals(pending) и (б) паузирани
 * human_approval runs. Паузираните runs минават през ApprovalService (единния boundary);
 * org предложенията носят mutable решението, а org_events остава append-only одитът.
 */
class DecisionBoxService
{
    public function __construct(
        private ApprovalService $approvals,
        private PlanLibraryService $planLibrary,
        private MemberMemoryService $memory,
    ) {}

    /** Обединен списък „чакащи решения": предложени задачи + org предложения + паузирани одобрения. */
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

        // (в) предложени задачи (pending_approval) с draft flow — носят brief за картата.
        $taskProposals = AssistantTask::where('status', 'pending_approval')
            ->whereHas('orgMember', fn ($q) => $q->where('company_id', $company->id))
            ->with('orgMember.persona', 'flow')
            ->latest()->get()
            ->map(fn (AssistantTask $t) => [
                'kind' => 'assistant_task',
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'proposal' => $t->proposal,
                'member' => $t->orgMember,
                'flow_id' => $t->flow_id,
                'created_at' => $t->created_at,
            ]);

        return $taskProposals->concat($proposals)->concat($runs);
    }

    /**
     * Одобрение на предложена задача (АТОМАРНО): flow draft→active, task pending_approval→
     * ready, decision полета, org_event. След commit: plan-library capture (преместен от
     * генерацията) + optional „Одобри и пусни" (минава централния run gate).
     *
     * @return array{ok: bool, error?: string, run_id?: int, run_skipped?: string}
     */
    public function approveTask(AssistantTask $task, ?User $user = null, bool $run = false): array
    {
        $version = null;
        $result = DB::transaction(function () use ($task, $user, &$version) {
            $fresh = AssistantTask::whereKey($task->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'pending_approval') {
                return ['ok' => false, 'error' => 'Предложението вече е решено.'];
            }

            $flow = $fresh->flow()->lockForUpdate()->first();
            if (! $flow) {
                return ['ok' => false, 'error' => 'Задачата няма flow за активиране.'];
            }

            $flow->update(['status' => 'active']);
            $fresh->update([
                'status' => 'ready',
                'approval_policy' => $fresh->approval_policy === 'approve_first_then_auto' ? 'auto' : $fresh->approval_policy,
                'approved_at' => now(),
                'approved_by' => $user?->id,
            ]);
            $version = $flow->activeVersion;

            $company = $fresh->orgMember?->company;
            $company?->orgEvents()->create([
                'type' => 'task_approved',
                'org_version_id' => $company->active_org_version_id,
                'org_member_id' => $fresh->org_member_id,
                'subject_type' => $fresh->getMorphClass(),
                'subject_id' => $fresh->id,
                'summary' => 'Одобрена задача: '.$fresh->title,
                'meta' => ['task_id' => $fresh->id, 'flow_id' => $flow->id],
                'actor' => 'human',
            ]);

            return ['ok' => true];
        });

        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        // Plan-library capture чак СЕГА (реално одобрен план).
        if ($version) {
            try {
                $this->planLibrary->captureApprovedPlan($version->fresh());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // „Одобри и пусни" — стартира run след активиране (active flow → минава gate).
        if ($run) {
            try {
                $runModel = app(TaskRunService::class)->launchReadyRun($task->fresh());
                $result['run_id'] = $runModel->id;
            } catch (InsufficientCreditsException) {
                $result['run_skipped'] = 'no_credits';
            } catch (\Throwable $e) {
                report($e);
                $result['run_skipped'] = 'error';
            }
        }

        return $result;
    }

    /**
     * Отказ на предложена задача (АТОМАРНО): flow draft→inactive, task pending_approval→
     * rejected, причина (задължителна), org_event. След commit: поука в паметта на служителя.
     *
     * @return array{ok: bool, error?: string}
     */
    public function rejectTask(AssistantTask $task, ?User $user = null, ?string $reason = null): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'Нужна е причина за отказа.'];
        }

        $member = null;
        $result = DB::transaction(function () use ($task, $user, $reason, &$member) {
            $fresh = AssistantTask::whereKey($task->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'pending_approval') {
                return ['ok' => false, 'error' => 'Предложението вече е решено.'];
            }

            $fresh->flow()->lockForUpdate()->first()?->update(['status' => 'inactive']);
            $fresh->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $user?->id,
                'rejection_reason' => $reason,
            ]);

            $member = $fresh->orgMember;
            $company = $member?->company;
            $company?->orgEvents()->create([
                'type' => 'task_rejected',
                'org_version_id' => $company->active_org_version_id,
                'org_member_id' => $fresh->org_member_id,
                'subject_type' => $fresh->getMorphClass(),
                'subject_id' => $fresh->id,
                'summary' => 'Отхвърлена задача: '.$fresh->title,
                'meta' => ['task_id' => $fresh->id, 'reason' => $reason],
                'actor' => 'human',
            ]);

            return ['ok' => true];
        });

        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        // Поука от отказа → паметта на служителя (инжектира се в следващото предложение).
        if ($member) {
            try {
                $this->memory->recordRejectionLesson($member, $task->fresh(), $reason);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $result;
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
