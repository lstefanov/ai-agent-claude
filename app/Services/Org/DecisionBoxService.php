<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\Director;
use App\Models\FlowRun;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Models\User;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\PlanLibraryService;
use App\Support\ModelLevel;
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
                    'created_at' => $run->created_at,
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
     * Презентационен модел за Кутията: обогатени карти-решения, ГРУПИРАНИ по департамент
     * (същите цветни кутии като design-review). Всеки item носи предложил + изпълнител
     * (член-карти), type-метадата (цвят/име), дата и максимум детайли. Структурните
     * предложения без домейн → „Ръководство"; паузираните runs → отделна група. Чисто
     * четене — не пипа решения (одобри/отхвърли остават непокътнати).
     *
     * @return array{groups: array<int, array<string, mixed>>, total: int, counts: array{structural: int, tasks: int, runs: int}}
     */
    public function deck(Company $company): array
    {
        $items = $this->pending($company);
        if ($items->isEmpty()) {
            return ['groups' => [], 'total' => 0, 'counts' => ['structural' => 0, 'tasks' => 0, 'runs' => 0]];
        }

        // Предзареждане за разрешаване на членове/отдели без N+1.
        $members = $company->members()->with('persona')->get()->keyBy('id');
        $version = $company->activeOrgVersion;
        $directors = $version ? $version->directors()->with('orgMember.persona')->get() : collect();
        $assistants = $version ? $version->assistants()->with('director')->get() : collect();

        // domain(lower) → Director плейсмънт (заглавка + цвят на групата).
        $dirByDomain = $directors->keyBy(fn (Director $d) => mb_strtolower((string) $d->domain));
        // member_id → неговия функционален домейн (директор: свой; асистент: на директора му).
        $domainByMember = [];
        foreach ($directors as $d) {
            if ($d->org_member_id && $d->domain) {
                $domainByMember[$d->org_member_id] = mb_strtolower((string) $d->domain);
            }
        }
        foreach ($assistants as $a) {
            if ($a->org_member_id && $a->director?->domain) {
                $domainByMember[$a->org_member_id] = mb_strtolower((string) $a->director->domain);
            }
        }

        $presented = $items->map(fn (array $it) => $this->presentDecision($it, $members, $domainByMember))->all();

        // Групиране по ключ.
        $buckets = [];
        foreach ($presented as $p) {
            $buckets[$p['group']]['items'][] = $p['vm'];
        }

        // Метадата на групите + подредба на items (най-нови първо, null последни).
        $deptOrder = array_flip(array_keys((array) config('organization.department_catalog', [])));
        $real = [];
        $leadership = null;
        $runs = null;
        foreach ($buckets as $key => $bucket) {
            $list = $bucket['items'];
            usort($list, fn ($a, $b) => ($b['created_at']?->getTimestamp() ?? -1) <=> ($a['created_at']?->getTimestamp() ?? -1));
            $meta = $this->groupMeta($key, $dirByDomain);
            $meta['items'] = $list;
            $meta['count'] = count($list);

            if ($key === '__runs__') {
                $runs = $meta;
            } elseif ($key === '__leadership__') {
                $leadership = $meta;
            } else {
                $real[] = $meta;
            }
        }

        // Департаменти: по брой (desc), после ред от каталога. „Ръководство" и „Изпълнения" — накрая.
        usort($real, function ($a, $b) use ($deptOrder) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return ($deptOrder[$a['domain']] ?? 999) <=> ($deptOrder[$b['domain']] ?? 999);
        });

        $groups = $real;
        if ($leadership) {
            $groups[] = $leadership;
        }
        if ($runs) {
            $groups[] = $runs;
        }

        // Резюме по категория (за лентата най-горе).
        $counts = ['structural' => 0, 'tasks' => 0, 'runs' => 0];
        foreach ($presented as $p) {
            $cat = $p['vm']['type_meta']['category'] ?? '';
            $counts[match ($cat) {
                'Изпълнение' => 'runs',
                'Структурно' => 'structural',
                default => 'tasks',
            }]++;
        }

        return ['groups' => $groups, 'total' => $items->count(), 'counts' => $counts];
    }

    /**
     * Обогатява един суров item (proposal | assistant_task | run_approval) до карта-решение
     * + ключ на групата. Връща ['group' => key, 'domain' => ?string, 'vm' => array].
     */
    private function presentDecision(array $it, Collection $members, array $domainByMember): array
    {
        $kind = $it['kind'];

        if ($kind === 'assistant_task') {
            $member = $it['member'] ?? null;
            $brief = (array) ($it['proposal'] ?? []);
            $domain = $member ? ($domainByMember[$member->id] ?? null) : null;
            $card = $this->memberCard($member);

            $vm = [
                'uid' => 'assistant_task-'.$it['id'],
                'kind' => 'assistant_task',
                'type_meta' => $this->typeMeta('assistant_task'),
                'title' => (string) ($it['title'] ?? ''),
                'rationale' => $brief['rationale'] ?? null,
                'description' => $it['description'] ?? null,
                'expected_impact' => $brief['expected_impact'] ?? null,
                'steps' => array_values((array) ($brief['steps'] ?? [])),
                'tools' => array_values((array) ($brief['tools'] ?? [])),
                'est_credits' => $brief['estimated_cost']['credits'] ?? null,
                'act_mode' => $brief['act_mode'] ?? ($it['act_mode'] ?? null),
                'tier' => null,
                'created_at' => $it['created_at'] ?? null,
                'proposer' => $card,
                'assignee' => $card,
                'assignee_label' => 'Възложено на',
                'assignee_role_label' => null,
                'same_person' => $card !== null,
                'flow_id' => $it['flow_id'] ?? null,
                'action' => ['kind' => 'assistant_task', 'id' => $it['id']],
            ];

            return ['group' => $domain ?? '__leadership__', 'domain' => $domain, 'vm' => $vm];
        }

        if ($kind === 'proposal') {
            $payload = (array) ($it['payload'] ?? []);
            $type = (string) $it['type'];
            $proposer = $this->resolveProposer($payload, $members);

            // Изпълнител/субект според типа: task → собственик („Възложено на"); fire/mandate/
            // tier_change → засегнатия член („Засяга"); hire → НЯМА съществуващ член (нова роля).
            $assignee = null;
            $assigneeLabel = null;
            $roleLabel = null;
            if ($type === 'hire') {
                $roleLabel = (string) ($payload['title'] ?? 'Нова роля');
            } else {
                $assigneeId = $payload['org_member_id'] ?? $payload['target_member_id'] ?? null;
                $assignee = $assigneeId ? $members->get((int) $assigneeId) : null;
                $assigneeLabel = in_array($type, ['fire', 'mandate', 'tier_change'], true) ? 'Засяга' : 'Възложено на';
            }

            $proposerCard = $this->memberCard($proposer);
            $assigneeCard = $this->memberCard($assignee);

            $domain = ($assignee ? ($domainByMember[$assignee->id] ?? null) : null)
                ?? ($proposer ? ($domainByMember[$proposer->id] ?? null) : null);

            $vm = [
                'uid' => 'proposal-'.$it['id'],
                'kind' => 'proposal',
                'type_meta' => $this->typeMeta($type),
                'title' => (string) ($payload['title'] ?? ucfirst($type)),
                'rationale' => $payload['rationale'] ?? null,
                'description' => $payload['description'] ?? null,
                'expected_impact' => $payload['expected_impact'] ?? null,
                'steps' => [],
                'tools' => [],
                'est_credits' => null,
                'act_mode' => $payload['act_mode'] ?? null,
                'tier' => $payload['tier'] ?? null,
                'created_at' => $it['created_at'] ?? null,
                'proposer' => $proposerCard,
                'assignee' => $assigneeCard,
                'assignee_label' => $assigneeLabel,
                'assignee_role_label' => $roleLabel,
                'same_person' => $proposerCard && $assigneeCard && $proposerCard['id'] === $assigneeCard['id'],
                'flow_id' => null,
                'action' => ['kind' => 'proposal', 'id' => $it['id']],
            ];

            return ['group' => $domain ?? '__leadership__', 'domain' => $domain, 'vm' => $vm];
        }

        // run_approval — паузиран human_approval; без член/департамент.
        $vm = [
            'uid' => 'run_approval-'.$it['flow_run_id'].'-'.$it['node_key'],
            'kind' => 'run_approval',
            'type_meta' => $this->typeMeta('run_approval'),
            'title' => (string) ($it['node_name'] ?? $it['node_key']),
            'rationale' => null,
            'description' => null,
            'expected_impact' => null,
            'steps' => [],
            'tools' => [],
            'est_credits' => null,
            'act_mode' => null,
            'tier' => null,
            'created_at' => $it['created_at'] ?? null,
            'proposer' => null,
            'assignee' => null,
            'assignee_label' => null,
            'assignee_role_label' => null,
            'same_person' => false,
            'flow_run_id' => $it['flow_run_id'],
            'node_key' => $it['node_key'],
            'node_name' => $it['node_name'] ?? null,
            'action' => ['kind' => 'run_approval', 'flow_run_id' => $it['flow_run_id'], 'node_key' => $it['node_key']],
        ];

        return ['group' => '__runs__', 'domain' => null, 'vm' => $vm];
    }

    /** Разрешава предложилия член: по id (надеждно) → по име (fallback за стари записи). */
    private function resolveProposer(array $payload, Collection $members): ?OrgMember
    {
        $id = $payload['proposed_by_member_id'] ?? null;
        if ($id && ($m = $members->get((int) $id))) {
            return $m;
        }

        $name = trim((string) ($payload['proposed_by'] ?? ''));
        if ($name === '') {
            return null;
        }

        return $members->first(fn (OrgMember $m) => $m->fullName() === $name || $m->display_name === $name);
    }

    /** Метадата на групата (заглавна лента): етикет, цвят, директор-аватар, неутралност. */
    private function groupMeta(string $key, Collection $dirByDomain): array
    {
        if ($key === '__runs__') {
            return ['key' => $key, 'domain' => null, 'label' => 'Изпълнения, чакащи одобрение',
                'color' => null, 'is_neutral' => true, 'director' => null, 'icon' => 'play-circle'];
        }

        if ($key === '__leadership__') {
            return ['key' => $key, 'domain' => null, 'label' => 'Ръководство',
                'color' => null, 'is_neutral' => true, 'director' => null, 'icon' => 'building-office-2'];
        }

        $director = $dirByDomain->get($key);
        $label = $director?->title
            ?? config("organization.department_catalog.$key.title")
            ?? mb_convert_case($key, MB_CASE_TITLE);

        return [
            'key' => $key,
            'domain' => $key,
            'label' => $label,
            'color' => $this->colorForDomain($key),
            'is_neutral' => false,
            'director' => $this->memberCard($director?->orgMember),
            'icon' => null,
        ];
    }

    /** Стабилен char цвят за домейн (§10.1) — огледало на OrgMember::functionColor за безчленна група. */
    private function colorForDomain(string $domain): string
    {
        $domain = mb_strtolower($domain);
        foreach ((array) config('organization.function_colors', []) as $needle => $color) {
            if ($domain !== '' && str_contains($domain, mb_strtolower((string) $needle))) {
                return (string) $color;
            }
        }

        return (string) config('organization.default_function_color', 'blue');
    }

    /** Type-метадата (цвят/име/категория/икона) от config; непознат тип → fallback. */
    private function typeMeta(string $key): array
    {
        $types = (array) config('organization.proposal_types', []);

        return (array) ($types[$key] ?? config('organization.proposal_type_fallback', [
            'label' => 'Предложение', 'category' => 'Структурно', 'color' => 'blue', 'icon' => 'document',
        ]));
    }

    /**
     * Член-карта за чиповете „Предложил"/„Възложено на" + профилния modal (огледало на
     * OrgGraphController::memberCard). Носи пълния профил (био/произход/черти/умения) и линк
     * към самата страница на члена — чиповете го отварят в modal без нова заявка.
     */
    private function memberCard(?OrgMember $m): ?array
    {
        if (! $m) {
            return null;
        }

        $persona = $m->persona;
        $tier = ModelLevel::tryFrom($m->default_star_tier) ?? ModelLevel::Medium;

        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'name' => $m->fullName(),
            'role' => $m->roleTitle(),
            'color' => $m->functionColor(),
            'age' => $persona->age ?? null,
            'tone' => $persona->tone ?? null,
            'bio' => $persona->bio ?? null,
            'background' => $persona->background ?? null,
            'education' => $persona->education ?? null,
            'traits' => (array) ($persona->traits ?? []),
            'skills' => array_values((array) ($persona->skills ?? [])),
            'tier' => $m->default_star_tier,
            'tier_label' => $tier->label(),
            'stars' => $tier->rank() + 1,
            'avatar_url' => ($persona && $persona->hasReadyAvatar()) ? $persona->avatar_url : null,
            'initial' => mb_strtoupper(mb_substr($m->fullName(), 0, 1)),
            'retired' => $m->retired_at !== null,
            'profile_url' => route('client.org.member', $m->id),
        ];
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
