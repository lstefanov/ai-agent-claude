<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\FlowRun;
use App\Models\KnowledgeEvent;
use App\Models\NodeRun;
use App\Models\OrgEvent;
use App\Models\OrgMember;
use App\Support\ChronicleItem;
use App\Support\ChronicleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Единният поток на Хрониката (§7.4): слива разнородните company-scoped източници в
 * една времева линия, страницира с timestamp+id cursor и смята контролните показатели.
 *
 * org_events е гръбнакът (вече агрегира екип/задачи/решения/знание-резюмета). Добавяме
 * САМО това, което org_events не носи богато — реалните изпълнения (flow_runs), знанието
 * (knowledge_events, по-богато от резюме-събитието) и билинга (credit_ledger). За да няма
 * дубли, изключваме org_event типовете, които тези източници покриват по-добре.
 */
class OrgChronicleService
{
    private const PAGE_SIZE = 25;

    /** Буфер над PAGE_SIZE на източник — поема граничните дубли при cursor пагинацията. */
    private const PER_SOURCE_CAP = self::PAGE_SIZE + 8;

    private const ACTIVE_STATUSES = ['pending', 'running', 'waiting_approval'];

    /** org_event типове, които другите източници покриват по-добре → вън от потока. */
    private const EXCLUDED_ORG_EVENT_TYPES = ['task_completed', 'task_failed', 'knowledge_added'];

    /** Кои org_event типове влизат във всяка филтър-група. */
    private const ORG_EVENT_GROUPS = [
        'team' => ['hire', 'fire', 'reassign', 'mandate_change'],
        'task' => ['task_proposed', 'task_approved', 'task_rejected'],
        'decision' => ['approval', 'review', 'daily_digest'],
        'run' => ['action', 'flow_activated'],
    ];

    /** @var array<int,array<string,mixed>|null> Кеш member карта по id (без N+1 в списъка). */
    private array $memberCache = [];

    public function __construct(private OrgGraphService $graph) {}

    /**
     * Една страница от потока.
     *
     * @param  array{since:Carbon,end:Carbon,groups:array<int,string>,member:?int,q:?string,cursor:?array{ts:int,rank:int,id:int}}  $p
     * @return array{items:array<int,array<string,mixed>>,next_cursor:?string,last_day:?string}
     */
    public function feed(Company $company, array $p): array
    {
        $cursor = $p['cursor'];
        $groups = $p['groups'];
        $member = $p['member'];
        $q = $p['q'];
        $all = $groups === [];
        $wants = fn (string $g): bool => $all || in_array($g, $groups, true);

        /** @var array<int,ChronicleItem> $items */
        $items = [];

        // ── org_events (гръбнакът) ──
        $orgTypes = [];
        foreach (self::ORG_EVENT_GROUPS as $g => $types) {
            if ($wants($g)) {
                $orgTypes = array_merge($orgTypes, $types);
            }
        }
        if ($all || $orgTypes !== []) {
            $query = OrgEvent::where('company_id', $company->id)
                ->whereNotIn('type', self::EXCLUDED_ORG_EVENT_TYPES)
                ->with('orgMember.persona');
            $this->applyWindow($query, $p, 0);
            $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::PER_SOURCE_CAP);
            if (! $all) {
                $query->whereIn('type', $orgTypes);
            }
            if ($member !== null) {
                $query->where('org_member_id', $member);
            }
            if ($q !== null) {
                $query->where('summary', 'like', '%'.$q.'%');
            }
            foreach ($query->get() as $e) {
                if ($item = $this->fromOrgEvent($e)) {
                    $items[] = $item;
                }
            }
        }

        // ── flow_runs (изпълнения) ──
        if ($wants('run')) {
            $runsQuery = FlowRun::whereHas('flow', fn ($f) => $f->where('company_id', $company->id))
                ->whereIn('status', ['completed', 'failed', 'running', 'pending', 'waiting_approval'])
                ->with('flow');
            $this->applyWindow($runsQuery, $p, 1);
            $runs = $runsQuery->orderByDesc('created_at')->orderByDesc('id')->limit(self::PER_SOURCE_CAP)->get();

            $taskIds = $runs->map(fn (FlowRun $r) => $r->context['assistant_task_id'] ?? null)
                ->filter()->unique()->values()->all();
            $taskById = $taskIds === []
                ? collect()
                : AssistantTask::with('orgMember.persona')->whereIn('id', $taskIds)->get()->keyBy('id');
            $nodeAgg = $this->nodeAgg($runs->pluck('id'));

            foreach ($runs as $run) {
                $item = $this->fromFlowRun($run, $taskById, $nodeAgg);
                if ($item === null) {
                    continue;
                }
                if ($member !== null && ($item->member['id'] ?? null) !== $member) {
                    continue;
                }
                $items[] = $item;
            }
        }

        // ── knowledge_events (знание) — няма член → вън при филтър по член ──
        if ($wants('knowledge') && $member === null) {
            $kq = KnowledgeEvent::where('company_id', $company->id);
            $this->applyWindow($kq, $p, 2);
            $kq->orderByDesc('created_at')->orderByDesc('id')->limit(self::PER_SOURCE_CAP);
            if ($q !== null) {
                $kq->where('title', 'like', '%'.$q.'%');
            }
            foreach ($kq->get() as $e) {
                if ($item = $this->fromKnowledge($e)) {
                    $items[] = $item;
                }
            }
        }

        // ── credit_ledger (билинг) — само значимите редове, без reserve шума ──
        if ($wants('billing') && $member === null) {
            $cq = CreditLedgerEntry::where('company_id', $company->id)
                ->whereIn('type', ['settle', 'topup', 'grant', 'refund'])
                ->with('reservation.subject');
            $this->applyWindow($cq, $p, 3);
            $cq->orderByDesc('created_at')->orderByDesc('id')->limit(self::PER_SOURCE_CAP);
            $creditRows = $cq->get();

            // Субект FlowRun → задача/член + node-агрегация (за „за какво" и себестойност).
            $subjectRuns = $creditRows
                ->map(fn (CreditLedgerEntry $e) => $e->reservation?->subject instanceof FlowRun ? $e->reservation->subject : null)
                ->filter();
            $cNodeAgg = $this->nodeAgg($subjectRuns->pluck('id'));
            $cTaskIds = $subjectRuns->map(fn (FlowRun $r) => $r->context['assistant_task_id'] ?? null)
                ->filter()->unique()->values()->all();
            $cTaskById = $cTaskIds === []
                ? collect()
                : AssistantTask::with('orgMember.persona')->whereIn('id', $cTaskIds)->get()->keyBy('id');

            foreach ($creditRows as $e) {
                if ($item = $this->fromCredit($e, $cNodeAgg, $cTaskById)) {
                    $items[] = $item;
                }
            }
        }

        // Строго „след курсора" (развързване на равни секунди по източник+id).
        if ($cursor) {
            $items = array_values(array_filter($items, fn (ChronicleItem $x) => $this->isAfterCursor($cursor, $x)));
        }

        // Свободен текст: PHP fallback (покрива и runs, където q не е избутан до SQL).
        if ($q !== null) {
            $needle = mb_strtolower($q);
            $items = array_values(array_filter($items, function (ChronicleItem $x) use ($needle) {
                return mb_strpos(mb_strtolower($x->title), $needle) !== false
                    || ($x->actorLabel !== null && mb_strpos(mb_strtolower($x->actorLabel), $needle) !== false);
            }));
        }

        usort($items, fn (ChronicleItem $a, ChronicleItem $b) => [$b->occurredAt->getTimestamp(), $a->sourceRank, $b->dbId]
            <=> [$a->occurredAt->getTimestamp(), $b->sourceRank, $a->dbId]);

        $hasMore = count($items) > self::PAGE_SIZE;
        $items = array_slice($items, 0, self::PAGE_SIZE);

        $last = $items === [] ? null : $items[array_key_last($items)];

        return [
            'items' => array_map(fn (ChronicleItem $i) => $i->toArray(), $items),
            'next_cursor' => ($hasMore && $last) ? $this->encodeCursor($last) : null,
            'last_day' => $last?->occurredAt->format('Y-m-d'),
        ];
    }

    /**
     * Контролни показатели (за избрания период) + 30-дневна хистограма на активността.
     *
     * @return array{kpis:array<string,int>,histogram:array<int,array{date:string,count:int}>}
     */
    public function stats(Company $company, Carbon $since): array
    {
        $kpis = [
            'events_today' => OrgEvent::where('company_id', $company->id)
                ->whereDate('created_at', Carbon::today())->count(),
            'active_runs' => FlowRun::whereHas('flow', fn ($f) => $f->where('company_id', $company->id))
                ->whereIn('status', self::ACTIVE_STATUSES)->count(),
            'credits_spent' => (int) CreditLedgerEntry::where('company_id', $company->id)
                ->where('type', 'settle')->where('created_at', '>=', $since)->sum('amount'),
            'tasks_completed' => FlowRun::whereHas('flow', fn ($f) => $f->where('company_id', $company->id))
                ->where('status', 'completed')->where('created_at', '>=', $since)->count(),
            'knowledge_added' => KnowledgeEvent::where('company_id', $company->id)
                ->where('action', 'added')->where('created_at', '>=', $since)->count(),
        ];

        return ['kpis' => $kpis, 'histogram' => $this->histogram($company)];
    }

    /** Активност по дни за последните 30 дни (събрана адитивно от всички източници). */
    private function histogram(Company $company): array
    {
        $since = Carbon::today()->subDays(29)->startOfDay();
        $counts = [];
        $merge = function (array $map) use (&$counts) {
            foreach ($map as $d => $n) {
                $counts[$d] = ($counts[$d] ?? 0) + (int) $n;
            }
        };

        $merge(OrgEvent::where('company_id', $company->id)->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) d, count(*) n')->groupBy('d')->pluck('n', 'd')->all());
        $merge(FlowRun::whereHas('flow', fn ($f) => $f->where('company_id', $company->id))->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) d, count(*) n')->groupBy('d')->pluck('n', 'd')->all());
        $merge(KnowledgeEvent::where('company_id', $company->id)->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) d, count(*) n')->groupBy('d')->pluck('n', 'd')->all());
        $merge(CreditLedgerEntry::where('company_id', $company->id)->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) d, count(*) n')->groupBy('d')->pluck('n', 'd')->all());

        $out = [];
        $cursor = $since->copy();
        $today = Carbon::today();
        while ($cursor->lte($today)) {
            $key = $cursor->format('Y-m-d');
            $out[] = ['date' => $key, 'count' => (int) ($counts[$key] ?? 0)];
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * „Разбивка за периода" за десния панел: кредити по контекст, активност по тип, топ консуматори.
     *
     * @return array{credits_by_context:array<int,array{label:string,credits:int}>,activity_by_type:array<int,array{group:string,label:string,count:int}>,top_consumers:array<int,array{member:array<string,mixed>,credits:int}>}
     */
    public function breakdown(Company $company, Carbon $since): array
    {
        $byCtx = CreditLedgerEntry::query()
            ->where('credit_ledger.company_id', $company->id)
            ->where('credit_ledger.type', 'settle')
            ->where('credit_ledger.created_at', '>=', $since)
            ->leftJoin('credit_reservations', 'credit_ledger.reservation_id', '=', 'credit_reservations.id')
            ->selectRaw('credit_reservations.context_type ctx, sum(credit_ledger.amount) total')
            ->groupBy('credit_reservations.context_type')
            ->get()
            ->map(fn ($r) => ['label' => $this->contextTypeLabel($r->ctx), 'credits' => (int) $r->total])
            ->filter(fn ($r) => $r['credits'] > 0)
            ->sortByDesc('credits')->values()->all();

        return [
            'credits_by_context' => $byCtx,
            'activity_by_type' => $this->activityByGroup($company, $since),
            'top_consumers' => $this->topConsumers($company, $since),
        ];
    }

    /** Брой събития по филтър-група за периода. */
    private function activityByGroup(Company $company, Carbon $since): array
    {
        $counts = ['task' => 0, 'team' => 0, 'knowledge' => 0, 'run' => 0, 'billing' => 0, 'decision' => 0];

        $orgByType = OrgEvent::where('company_id', $company->id)
            ->whereNotIn('type', self::EXCLUDED_ORG_EVENT_TYPES)
            ->where('created_at', '>=', $since)
            ->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type');
        foreach ($orgByType as $type => $n) {
            $g = ChronicleType::presentation((string) $type)['group'];
            if (isset($counts[$g])) {
                $counts[$g] += (int) $n;
            }
        }
        $counts['run'] += FlowRun::whereHas('flow', fn ($f) => $f->where('company_id', $company->id))
            ->where('created_at', '>=', $since)->count();
        $counts['knowledge'] += KnowledgeEvent::where('company_id', $company->id)
            ->where('created_at', '>=', $since)->count();
        $counts['billing'] += CreditLedgerEntry::where('company_id', $company->id)
            ->whereIn('type', ['settle', 'topup', 'grant', 'refund'])->where('created_at', '>=', $since)->count();

        $out = [];
        foreach (ChronicleType::GROUPS as $g => $label) {
            $out[] = ['group' => $g, 'label' => $label, 'count' => $counts[$g]];
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /** Топ 5 членове по изхарчени кредити (settle → резервационен субект → член). */
    private function topConsumers(Company $company, Carbon $since): array
    {
        $settles = CreditLedgerEntry::where('company_id', $company->id)
            ->where('type', 'settle')->where('created_at', '>=', $since)
            ->with('reservation.subject')
            ->orderByDesc('id')->limit(400)->get(['id', 'amount', 'reservation_id']);

        $runTaskIds = $settles->map(function (CreditLedgerEntry $e) {
            $s = $e->reservation?->subject;

            return $s instanceof FlowRun ? ($s->context['assistant_task_id'] ?? null) : null;
        })->filter()->unique()->values()->all();
        $tasks = $runTaskIds === []
            ? collect()
            : AssistantTask::with('orgMember.persona')->whereIn('id', $runTaskIds)->get()->keyBy('id');

        $byMember = [];
        foreach ($settles as $e) {
            $m = $this->memberForSubject($e->reservation?->subject, $tasks);
            if (! $m) {
                continue;
            }
            $byMember[$m['id']] ??= ['member' => $m, 'credits' => 0];
            $byMember[$m['id']]['credits'] += (int) $e->amount;
        }

        $rows = array_values($byMember);
        usort($rows, fn ($a, $b) => $b['credits'] <=> $a['credits']);

        return array_slice($rows, 0, 5);
    }

    /** Член зад резервационен субект: FlowRun→задача→член, AssistantTask→член, OrgMember→член. */
    private function memberForSubject(mixed $subject, Collection $tasks): ?array
    {
        if ($subject instanceof FlowRun) {
            $task = $tasks->get($subject->context['assistant_task_id'] ?? null);

            return $task ? $this->member($task->orgMember) : null;
        }
        if ($subject instanceof AssistantTask) {
            return $this->member($subject->orgMember);
        }
        if ($subject instanceof OrgMember) {
            return $this->member($subject);
        }

        return null;
    }

    /** Брой възли + сума cost_usd на изпълнение, в една групирана заявка (без N+1). */
    private function nodeAgg(Collection $runIds): array
    {
        $ids = $runIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return NodeRun::whereIn('flow_run_id', $ids->all())
            ->selectRaw('flow_run_id, count(*) nodes, sum(cost_usd) cost')
            ->groupBy('flow_run_id')->get()
            ->keyBy('flow_run_id')
            ->map(fn ($r) => ['nodes' => (int) $r->nodes, 'cost' => (float) $r->cost])
            ->all();
    }

    // ── Нормализация на източниците ───────────────────────────────────────────

    private function fromOrgEvent(OrgEvent $e): ?ChronicleItem
    {
        $at = $e->created_at;
        if (! $at) {
            return null;
        }

        $meta = (array) $e->meta;
        $member = $this->member($e->orgMember);

        // Контекст ред: роля на члена + актьор (+ причина при отхвърляне).
        $ctx = [];
        if ($member && ! empty($member['role'])) {
            $ctx[] = $member['role'];
        }
        if ($actor = $this->actorLabel($e->actor)) {
            $ctx[] = $actor;
        }
        if (! empty($meta['reason'])) {
            $ctx[] = '«'.Str::limit((string) $meta['reason'], 60).'»';
        }

        $links = [];
        if (! empty($meta['proposal_id'])) {
            $links[] = ['label' => 'Кутията', 'href' => route('client.org.decisions'), 'icon' => 'inbox-arrow-down'];
        }
        if ($e->org_member_id) {
            $links[] = ['label' => 'Служителя', 'href' => route('client.org.member', $e->org_member_id), 'icon' => 'user'];
        }

        $href = ! empty($meta['proposal_id'])
            ? route('client.org.decisions')
            : ($e->org_member_id ? route('client.org.member', $e->org_member_id) : null);

        return new ChronicleItem(
            source: 'org_event',
            dbId: $e->id,
            sourceRank: 0,
            type: (string) $e->type,
            occurredAt: $at,
            title: (string) $e->summary,
            actorLabel: $this->actorLabel($e->actor),
            member: $member,
            href: $href,
            detail: ['story' => (string) $e->summary, 'links' => $links],
            context: $ctx === [] ? null : implode(' · ', $ctx),
        );
    }

    private function fromFlowRun(FlowRun $run, Collection $taskById, array $nodeAgg): ?ChronicleItem
    {
        // occurredAt = created_at за ВСИЧКИ източници → сортиране, прозорец и cursor са
        // на едно поле (иначе пагинацията се разминава). Завършването е в детайла.
        $at = $run->created_at ?? $run->started_at ?? $run->completed_at;
        if (! $at) {
            return null;
        }

        $type = match ($run->status) {
            'completed' => 'flow_run_completed',
            'failed' => 'flow_run_failed',
            default => 'flow_run_active',
        };

        $task = $taskById->get($run->context['assistant_task_id'] ?? null);
        $member = $task ? $this->member($task->orgMember) : null;
        $flowName = $run->flow?->name;
        $title = $task?->title ?: ($flowName ?: 'Изпълнение');
        $agg = $nodeAgg[$run->id] ?? null;
        $duration = ($run->started_at && $run->completed_at)
            ? $run->started_at->diffForHumans($run->completed_at, true)
            : null;

        // Контекст ред: член/тригер · поток · възли · времетраене.
        $ctx = [];
        if ($member) {
            $ctx[] = $member['name'];
        } elseif ($t = $this->triggerLabel($run->triggered_by)) {
            $ctx[] = $t;
        }
        if ($flowName && $flowName !== $title) {
            $ctx[] = $flowName;
        }
        if ($agg && $agg['nodes'] > 0) {
            $ctx[] = $agg['nodes'].' възела';
        }
        if ($duration) {
            $ctx[] = $duration;
        }

        $rows = [];
        if ($run->model_level) {
            $rows[] = ['label' => 'Ниво', 'value' => $run->model_level];
        }
        if ($run->final_output_model) {
            $rows[] = ['label' => 'Модел', 'value' => $run->final_output_model];
        }
        if ($agg && $agg['nodes'] > 0) {
            $rows[] = ['label' => 'Възли', 'value' => (string) $agg['nodes']];
        }
        if ($duration) {
            $rows[] = ['label' => 'Времетраене', 'value' => $duration];
        }
        if ($agg && $agg['cost'] > 0) {
            $rows[] = ['label' => 'Себестойност', 'value' => '$'.number_format($agg['cost'], 4)];
        }

        $amount = ($agg && $agg['cost'] > 0) ? '$'.number_format($agg['cost'], 3) : null;
        $story = ($member['name'] ?? 'Системата').' изпълни «'.($flowName ?: $title).'» — '.$this->runStatusLabel($run->status).'.';

        return new ChronicleItem(
            source: 'flow_run',
            dbId: $run->id,
            sourceRank: 1,
            type: $type,
            occurredAt: $at,
            title: (string) $title,
            actorLabel: $member ? null : $this->triggerLabel($run->triggered_by),
            member: $member,
            href: route('client.runs.result', $run->id),
            detail: ['story' => $story, 'rows' => $rows, 'links' => [
                ['label' => 'Виж резултата', 'href' => route('client.runs.result', $run->id), 'icon' => 'document-text'],
            ]],
            context: $ctx === [] ? null : implode(' · ', $ctx),
            amount: $amount,
            amountTone: $amount ? 'muted' : null,
        );
    }

    private function fromKnowledge(KnowledgeEvent $e): ?ChronicleItem
    {
        $at = $e->created_at;
        if (! $at) {
            return null;
        }

        $type = 'knowledge_'.$e->action;
        if (! in_array($type, ['knowledge_added', 'knowledge_updated', 'knowledge_deleted'], true)) {
            $type = 'knowledge_added';
        }

        $meta = (array) $e->meta;
        $ctx = [];
        if ($e->source) {
            $ctx[] = Str::limit((string) $e->source, 60);
        }
        if (! empty($meta['category'])) {
            $ctx[] = (string) $meta['category'];
        }
        if (! empty($meta['location'])) {
            $ctx[] = (string) $meta['location'];
        }

        $f = $meta['facts'] ?? null;
        $facts = is_array($f) ? (int) ($f['added'] ?? 0) : (int) $f;
        $amount = $facts > 0 ? '+'.$facts.' '.$this->plural($facts, 'факт', 'факта') : null;

        return new ChronicleItem(
            source: 'knowledge',
            dbId: $e->id,
            sourceRank: 2,
            type: $type,
            occurredAt: $at,
            title: (string) $e->title,
            actorLabel: $e->source ? Str::limit((string) $e->source, 48) : 'База знания',
            member: null,
            href: null,
            detail: [
                'story' => $e->source ? 'Източник: '.$e->source : null,
                'note' => $e->snippet ? Str::limit((string) $e->snippet, 400) : null,
            ],
            context: $ctx === [] ? null : implode(' · ', $ctx),
            amount: $amount,
            amountTone: $amount ? 'success' : null,
        );
    }

    private function fromCredit(CreditLedgerEntry $e, array $nodeAgg, Collection $taskById): ?ChronicleItem
    {
        $at = $e->created_at;
        if (! $at) {
            return null;
        }

        $amount = (int) $e->amount;
        $isSpend = $e->type === 'settle';
        $kr = $this->plural($amount, 'кредит', 'кредита');
        $title = match ($e->type) {
            'settle' => "Разход · {$amount} {$kr}",
            'topup' => "Зареждане · +{$amount} {$kr}",
            'grant' => "Кредитен пакет · +{$amount} {$kr}",
            'refund' => "Възстановени · +{$amount} {$kr}",
            default => "{$amount} {$kr}",
        };

        $res = $e->reservation;
        $meta = (array) $e->meta;
        $subject = $res?->subject;
        $ctxLabel = $res ? $this->contextTypeLabel($res->context_type) : null;

        // Разрешаване на субекта „за какво / откъде".
        $subjName = null;
        $run = null;
        $links = [];
        if ($subject instanceof FlowRun) {
            $run = $subject;
            $task = $taskById->get($run->context['assistant_task_id'] ?? null);
            $subjName = $task?->title ?: $run->flow?->name;
            $links[] = ['label' => 'Изпълнението', 'href' => route('client.runs.result', $run->id), 'icon' => 'document-text'];
        } elseif ($subject instanceof AssistantTask) {
            $subjName = $subject->title;
        } elseif ($subject instanceof OrgMember) {
            $subjName = $subject->fullName();
            $links[] = ['label' => 'Служителя', 'href' => route('client.org.member', $subject->id), 'icon' => 'user'];
        }

        [$context, $story] = $this->creditContextStory($e->type, $ctxLabel, $subjName, $meta);

        $rows = [];
        if ($ctxLabel) {
            $rows[] = ['label' => 'Контекст', 'value' => $ctxLabel];
        }
        if ($subjName) {
            $subjKind = $subject instanceof OrgMember ? 'Служител' : ($subject instanceof AssistantTask ? 'Задача' : 'Изпълнение');
            $rows[] = ['label' => $subjKind, 'value' => Str::limit($subjName, 60)];
        }
        if ($run) {
            $rows[] = ['label' => 'Изпълнение', 'value' => '#'.$run->id.' · '.$this->runStatusLabel($run->status)];
        }
        if ($res) {
            $rows[] = ['label' => 'Резервирани → похарчени', 'value' => $res->estimated_credits.' → '.$res->spent_credits.' кр.'];
        }
        if ($run) {
            $agg = $nodeAgg[$run->id] ?? null;
            if ($run->final_output_model || $run->model_level || ($agg && $agg['nodes'] > 0)) {
                $nodes = $agg['nodes'] ?? 0;
                $rows[] = ['label' => 'Възли · модел', 'value' => $nodes.' · '.($run->final_output_model ?: $run->model_level ?: '—')];
            }
            if ($agg && $agg['cost'] > 0) {
                $rows[] = ['label' => 'Себестойност', 'value' => '$'.number_format($agg['cost'], 4)];
            }
        }

        return new ChronicleItem(
            source: 'credit',
            dbId: $e->id,
            sourceRank: 3,
            type: 'credit_'.$e->type,
            occurredAt: $at,
            title: $title,
            actorLabel: 'Билинг',
            member: null,
            href: $run ? route('client.runs.result', $run->id) : null,
            detail: ['story' => $story, 'rows' => $rows, 'links' => $links],
            context: $context,
            amount: ($isSpend ? '−' : '+').$amount.' кр.',
            amountTone: $isSpend ? 'danger' : 'success',
        );
    }

    /** Контекст-ред + изречение „история" за билинг събитие. @return array{0:?string,1:?string} */
    private function creditContextStory(string $type, ?string $ctxLabel, ?string $subjName, array $meta): array
    {
        $subj = $subjName ? '«'.Str::limit($subjName, 48).'»' : null;

        return match ($type) {
            'settle' => [
                $subj ? trim(($ctxLabel ?? '').' '.$subj) : $ctxLabel,
                $subj ? 'Похарчени за '.($ctxLabel ?? 'операция').' '.$subj.'.' : 'Похарчени за '.($ctxLabel ?? 'операция').'.',
            ],
            'refund' => [
                $subj ? 'неизползвани от '.$subj : 'неизползван резерв'.($ctxLabel ? ' ('.$ctxLabel.')' : ''),
                'Върнати неизползвани кредити'.($subj ? ' от резервация за '.$subj.'.' : ($ctxLabel ? ' ('.$ctxLabel.').' : '.')),
            ],
            'grant' => [
                'Месечен пакет'.(! empty($meta['plan']) ? ' (план '.$meta['plan'].')' : ''),
                'Месечни кредити от абонамента.',
            ],
            'topup' => [
                ($meta['source'] ?? null) === 'admin' ? 'Ръчно зареждане' : 'Покупка на кредити',
                'Добавени кредити към баланса.',
            ],
            default => [null, null],
        };
    }

    // ── Прозорец + cursor ──────────────────────────────────────────────────────

    /**
     * Прилага времевия прозорец и cursor-предиката на ниво заявка. Предикатът зависи
     * от ранга на източника, защото при споделена секунда подредбата е [created_at DESC,
     * rank ASC, id DESC] — иначе follow-up страница, чийто граничен ден има много събития
     * в една секунда, връща вече показаните (най-високи id) и излиза празна.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  array{since:Carbon,end:Carbon,cursor:?array{ts:int,rank:int,id:int}}  $p
     */
    private function applyWindow(Builder $query, array $p, int $rank): void
    {
        $query->where('created_at', '>=', $p['since']);
        $cursor = $p['cursor'];

        if (! $cursor) {
            $query->where('created_at', '<=', $p['end']);

            return;
        }

        // app timezone (НЕ UTC) — за да съвпада с as-stored datetime низа при сравнение.
        $ts = Carbon::createFromTimestamp($cursor['ts'], config('app.timezone'));

        if ($rank > $cursor['rank']) {
            // По-голям ранг → на граничната секунда всичко е „след" курсора.
            $query->where('created_at', '<=', $ts);
        } elseif ($rank < $cursor['rank']) {
            // По-малък ранг → граничната секунда вече е показана.
            $query->where('created_at', '<', $ts);
        } else {
            // Същият източник → развързваме по id (по-малък id = по-старо = след курсора).
            $query->where(function ($w) use ($ts, $cursor) {
                $w->where('created_at', '<', $ts)
                    ->orWhere(function ($x) use ($ts, $cursor) {
                        $x->where('created_at', $ts)->where('id', '<', $cursor['id']);
                    });
            });
        }
    }

    // ── Cursor ────────────────────────────────────────────────────────────────

    private function isAfterCursor(array $cur, ChronicleItem $x): bool
    {
        $xt = $x->occurredAt->getTimestamp();
        if ($xt !== $cur['ts']) {
            return $xt < $cur['ts'];
        }
        if ($x->sourceRank !== $cur['rank']) {
            return $x->sourceRank > $cur['rank'];
        }

        return $x->dbId < $cur['id'];
    }

    private function encodeCursor(ChronicleItem $i): string
    {
        return base64_encode((string) json_encode([
            'ts' => $i->occurredAt->getTimestamp(),
            'rank' => $i->sourceRank,
            'id' => $i->dbId,
        ]));
    }

    /** @return ?array{ts:int,rank:int,id:int} */
    public static function decodeCursor(?string $s): ?array
    {
        if (! $s) {
            return null;
        }
        $decoded = json_decode((string) base64_decode($s, true), true);
        if (! is_array($decoded) || ! isset($decoded['ts'], $decoded['rank'], $decoded['id'])) {
            return null;
        }

        return ['ts' => (int) $decoded['ts'], 'rank' => (int) $decoded['rank'], 'id' => (int) $decoded['id']];
    }

    // ── Етикети ────────────────────────────────────────────────────────────────

    private function member(?OrgMember $m): ?array
    {
        if (! $m) {
            return null;
        }

        return $this->memberCache[$m->id] ??= $this->graph->memberCard($m);
    }

    private function actorLabel(?string $actor): ?string
    {
        return match ($actor) {
            'manager' => 'Управител',
            'director' => 'Директор',
            'human' => 'Човек',
            'autonomous' => 'Автономно',
            null, '' => null,
            default => $actor,
        };
    }

    private function triggerLabel(?string $t): ?string
    {
        return match ($t) {
            'manual' => 'Ръчно',
            'scheduled' => 'По график',
            'webhook' => 'Webhook',
            'event' => 'Събитие',
            default => $t ?: null,
        };
    }

    private function contextTypeLabel(?string $type): string
    {
        return match ($type) {
            'task_run' => 'Изпълнение на задача',
            'director_tick' => 'Директорски анализ',
            'generation' => 'Генериране на flow',
            'avatar' => 'Аватар',
            'embedding' => 'Вграждане',
            'member_chat' => 'Чат със служител',
            'research' => 'Проучване',
            'interview' => 'Интервю',
            'org_planning' => 'Планиране на екип',
            'ignition' => 'Старт на екип',
            'org_digest' => 'Дневен преглед',
            'org_review' => 'Ревю на екипа',
            null, '' => 'Билинг',
            default => Str::ucfirst(str_replace('_', ' ', (string) $type)),
        };
    }

    private function runStatusLabel(?string $s): string
    {
        return match ($s) {
            'completed' => 'завършено',
            'failed' => 'провалено',
            'running' => 'тече',
            'pending' => 'чака',
            'waiting_approval' => 'чака одобрение',
            default => (string) $s,
        };
    }

    /** Българско множествено число: 1 → $one, иначе $many. */
    private function plural(int $n, string $one, string $many): string
    {
        return $n === 1 ? $one : $many;
    }
}
