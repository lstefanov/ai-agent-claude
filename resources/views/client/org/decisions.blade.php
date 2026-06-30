@extends('layouts.client')

@section('title', 'Предложения')

@section('content')
<div x-data="decisions({
        approveUrl: '{{ route('client.org.decisions.approve') }}',
        rejectUrl: '{{ route('client.org.decisions.reject') }}',
        reviewUrl: '{{ route('client.org.review') }}',
        csrf: '{{ csrf_token() }}',
     })" class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Предложения</h1>
            <p class="text-muted">Чакащи предложения от екипа, подредени по департаменти. Едно място за всичко.</p>
        </div>
        <x-org.busy-button busy="reviewing" loading-text="Пускам ревю…" size="sm" variant="secondary" icon="arrow-path" x-on:click="runReview()">
            Пусни ревю сега
        </x-org.busy-button>
    </div>

    @if ($deck['total'] === 0)
        <x-empty-state title="Няма чакащи предложения" description="Когато екип предложи задача, директор предложи промяна или изпълнение спре за одобрение, ще се появи тук." />
    @else
        {{-- Резюме: общ брой + разбивка по категория --}}
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 font-medium text-primary">
                <x-icon name="inbox-stack" size="4" />{{ $deck['total'] }} {{ $deck['total'] === 1 ? 'чакащо предложение' : 'чакащи предложения' }}
            </span>
            @if ($deck['counts']['structural'] > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1 text-muted">Структурни: <span class="font-semibold text-ink tabular-nums">{{ $deck['counts']['structural'] }}</span></span>
            @endif
            @if ($deck['counts']['tasks'] > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1 text-muted">Задачи: <span class="font-semibold text-ink tabular-nums">{{ $deck['counts']['tasks'] }}</span></span>
            @endif
            @if ($deck['counts']['runs'] > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1 text-muted">Изпълнения: <span class="font-semibold text-ink tabular-nums">{{ $deck['counts']['runs'] }}</span></span>
            @endif
        </div>

        @foreach ($deck['groups'] as $group)
            <section class="overflow-hidden rounded-2xl border border-line bg-surface-subtle/40">
                {{-- Заглавна лента на департамента (стил като design-review) --}}
                @if ($group['is_neutral'])
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line bg-surface px-5 py-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-surface-subtle text-subtle"><x-icon :name="$group['icon']" size="4" /></span>
                        <h2 class="text-base font-semibold text-ink">{{ $group['label'] }}</h2>
                        <span class="ml-auto inline-flex items-center rounded-full border border-line bg-surface px-2.5 py-0.5 text-xs text-muted tabular-nums">{{ $group['count'] }}</span>
                    </div>
                @else
                    @php $gc = $group['color']; @endphp
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-5 py-3" style="background: var(--color-char-{{ $gc }}-soft)">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: var(--color-char-{{ $gc }})"></span>
                        <span class="font-mono text-[11px] font-semibold uppercase tracking-wider" style="color: var(--color-char-{{ $gc }}-strong)">{{ $group['domain'] }}</span>
                        <h2 class="text-base font-semibold text-ink">{{ $group['label'] }}</h2>
                        <div class="ml-auto flex items-center gap-3">
                            <span class="inline-flex items-center rounded-full bg-surface/70 px-2.5 py-0.5 text-xs text-muted tabular-nums">{{ $group['count'] }} {{ $group['count'] === 1 ? 'предложение' : 'предложения' }}</span>
                            @if ($group['director'])
                                @php $d = $group['director']; $dc = $d['color'] ?? $gc; @endphp
                                <button type="button" x-on:click="openMember({{ \Illuminate\Support\Js::from($d) }})" class="flex items-center gap-2 rounded-full transition hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" title="Виж профила на {{ $d['name'] }}">
                                    @if (! empty($d['avatar_url']))
                                        <img src="{{ $d['avatar_url'] }}" alt="{{ $d['name'] }}" class="h-7 w-7 rounded-full object-cover ring-2 ring-char-{{ $dc }}-soft">
                                    @else
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-char-{{ $dc }}-soft text-char-{{ $dc }}-strong text-xs font-semibold ring-2 ring-char-{{ $dc }}-soft">{{ $d['initial'] }}</span>
                                    @endif
                                    <span class="hidden text-xs text-muted sm:inline">{{ $d['name'] }}</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Карти-решения --}}
                <div class="space-y-3 p-4 sm:p-5">
                    @foreach ($group['items'] as $item)
                        @php
                            $tm = $item['type_meta'];
                            $body = $item['rationale'] ?: $item['description'];
                            $long = $body && mb_strlen(strip_tags($body)) > 280;
                        @endphp
                        <article x-show="!done.includes('{{ $item['uid'] }}')" x-data="{ open: false }" class="rounded-xl border border-line bg-surface p-4 transition sm:p-5">
                            {{-- Бейджове за тип + дата --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full border border-line bg-surface-subtle px-2 py-0.5 text-[11px] font-medium text-muted">{{ $tm['category'] }}</span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold bg-char-{{ $tm['color'] }}-soft text-char-{{ $tm['color'] }}-strong">
                                    @if (! empty($tm['icon']))<x-icon :name="$tm['icon']" size="4" />@endif
                                    {{ $tm['label'] }}
                                </span>
                                @if ($item['created_at'])
                                    <span class="ml-auto text-xs text-subtle" title="{{ $item['created_at']->translatedFormat('j F Y, H:i') }}">{{ $item['created_at']->diffForHumans() }}</span>
                                @endif
                            </div>

                            {{-- Заглавие --}}
                            <p class="mt-2.5 text-base font-semibold text-ink"><x-prose :text="$item['title']" inline /></p>

                            {{-- Контекст за изпълнение (run_approval) --}}
                            @if ($item['kind'] === 'run_approval')
                                <p class="mt-1 text-sm text-muted">Изпълнение #{{ $item['flow_run_id'] }} спря на тази стъпка и чака одобрение.</p>
                            @endif

                            {{-- Обосновка / описание (свиваемо при дълъг текст) --}}
                            @if ($body)
                                <div class="mt-2.5 rounded-lg bg-surface-subtle p-3 text-sm text-ink">
                                    <div @if ($long) :class="open ? '' : 'line-clamp-3'" @endif><x-prose :text="$body" /></div>
                                    @if ($long)
                                        <button type="button" x-on:click="open = !open" class="mt-1.5 text-xs font-medium text-primary hover:text-primary-hover" x-text="open ? 'Скрий' : 'Покажи още'"></button>
                                    @endif
                                </div>
                            @endif
                            @if ($item['expected_impact'])
                                <p class="mt-2 text-xs text-muted"><span class="text-subtle">Очакван ефект:</span> {{ $item['expected_impact'] }}</p>
                            @endif

                            {{-- Стъпки (предложена задача) --}}
                            @if (! empty($item['steps']))
                                <ol class="mt-3 space-y-1 text-sm text-muted">
                                    @foreach (array_slice($item['steps'], 0, 4) as $i => $s)
                                        <li class="flex gap-2"><span class="text-subtle tabular-nums">{{ $s['order'] ?? $i + 1 }}.</span><span>{{ $s['summary'] ?? $s['node_name'] ?? '' }}</span></li>
                                    @endforeach
                                    @if (count($item['steps']) > 4)<li class="text-xs text-subtle">+ още {{ count($item['steps']) - 4 }} {{ count($item['steps']) - 4 === 1 ? 'стъпка' : 'стъпки' }}</li>@endif
                                </ol>
                            @endif

                            {{-- Инструменти / кредити / режим / ниво --}}
                            @if (! empty($item['tools']) || $item['est_credits'] !== null || ($item['act_mode'] && $item['act_mode'] !== 'draft') || $item['tier'])
                                <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                                    @foreach (array_slice($item['tools'], 0, 5) as $t)<span class="rounded bg-surface-subtle px-1.5 py-0.5 text-subtle">{{ $t }}</span>@endforeach
                                    @if ($item['act_mode'] && $item['act_mode'] !== 'draft')<span class="rounded bg-warning-soft px-1.5 py-0.5 text-warning-strong">режим: {{ $item['act_mode'] }}</span>@endif
                                    @if ($item['tier'])<span class="rounded bg-surface-subtle px-1.5 py-0.5 text-subtle">ниво: {{ $item['tier'] }}</span>@endif
                                    @if ($item['est_credits'] !== null)<span class="ml-auto text-subtle tabular-nums">~{{ $item['est_credits'] }} кредита</span>@endif
                                </div>
                            @endif

                            {{-- Предложил + Възложено на --}}
                            @if ($item['proposer'] || $item['assignee'] || $item['assignee_role_label'])
                                <div class="mt-4 grid gap-3 @if (! $item['same_person']) sm:grid-cols-2 @endif">
                                    @if ($item['same_person'] && $item['proposer'])
                                        @include('client.org._decision-member', ['m' => $item['proposer'], 'label' => 'Предложил и изпълнител', 'detailed' => true])
                                    @else
                                        @if ($item['proposer'])
                                            @include('client.org._decision-member', ['m' => $item['proposer'], 'label' => 'Предложил'])
                                        @endif
                                        @if ($item['assignee'])
                                            @include('client.org._decision-member', ['m' => $item['assignee'], 'label' => $item['assignee_label'] ?? 'Възложено на', 'detailed' => true])
                                        @elseif ($item['assignee_role_label'])
                                            <div class="rounded-lg border border-dashed border-line-strong bg-surface-subtle/40 p-3">
                                                <p class="mb-2 text-[10px] font-mono uppercase tracking-wider text-subtle">Нова роля</p>
                                                <div class="flex items-center gap-3">
                                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-dashed border-line-strong text-subtle"><x-icon name="user-plus" size="5" /></span>
                                                    <p class="min-w-0 truncate font-medium text-ink">{{ $item['assignee_role_label'] }}</p>
                                                </div>
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endif

                            {{-- Действия --}}
                            <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                                @if ($item['kind'] === 'assistant_task')
                                    @if ($item['flow_id'])<a href="{{ route('client.flows.show', $item['flow_id']) }}" class="mr-auto text-xs text-primary hover:text-primary-hover">Прегледай flow-а →</a>@endif
                                    <button type="button" x-on:click="rejectTask({{ $item['action']['id'] }})" class="text-sm font-medium text-danger hover:text-danger-strong">Отхвърли</button>
                                    <x-button size="sm" variant="secondary" x-on:click="approveTask({{ $item['action']['id'] }}, false)">Одобри</x-button>
                                    <x-button size="sm" x-on:click="approveTask({{ $item['action']['id'] }}, true)">Одобри и пусни</x-button>
                                @elseif ($item['kind'] === 'assistant_task_knowledge')
                                    <p class="mr-auto text-xs text-muted">Задачата чака да въведеш информация, преди да тръгне.</p>
                                    <x-button size="sm" variant="secondary" icon="book-open"
                                              x-on:click="$dispatch('knowledge-open', { taskId: {{ $item['action']['id'] }}, requirements: {{ \Illuminate\Support\Js::from($item['requirements'] ?? []) }} })">Добави знания</x-button>
                                @else
                                    <x-button size="sm" variant="secondary" x-on:click="act('reject', {{ \Illuminate\Support\Js::from($item['action']) }})">Отхвърли</x-button>
                                    <x-button size="sm" x-on:click="act('approve', {{ \Illuminate\Support\Js::from($item['action']) }})">Одобри</x-button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    {{-- Профил на член (отваря се при клик на чип/аватар) --}}
    <div x-show="member" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" x-on:keydown.escape.window="member = null">
        <div x-show="member" x-transition class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl border border-line bg-surface shadow-popover sm:max-w-lg sm:rounded-2xl" x-on:click.outside="member = null">
            <template x-if="member">
                <div>
                    {{-- Заглавна част --}}
                    <div class="flex items-start gap-4 border-b border-line p-5" :style="'background: var(--color-char-'+member.color+'-soft)'">
                        <template x-if="member.avatar_url">
                            <img :src="member.avatar_url" :alt="member.name" class="h-16 w-16 shrink-0 rounded-full object-cover ring-2" :class="'ring-char-'+member.color+'-soft'">
                        </template>
                        <template x-if="!member.avatar_url">
                            <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full text-xl font-semibold ring-2" :class="'bg-char-'+member.color+'-soft text-char-'+member.color+'-strong ring-char-'+member.color+'-soft'" x-text="member.initial"></span>
                        </template>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-semibold text-ink" x-text="member.name"></h2>
                                <template x-if="member.retired"><span class="rounded bg-surface/70 px-1.5 py-0.5 text-[10px] text-subtle">пенсиониран</span></template>
                            </div>
                            <p class="text-sm text-muted"><span x-text="member.role"></span><template x-if="member.age"><span> · <span x-text="member.age"></span>г.</span></template></p>
                            <span class="mt-1 inline-block text-xs tabular-nums" :title="'Ниво: '+member.tier_label">
                                <template x-for="i in 5" :key="i"><span :class="i <= member.stars ? 'text-star' : 'text-subtle'">★</span></template>
                            </span>
                        </div>
                        <button type="button" x-on:click="member = null" class="shrink-0 rounded-lg p-1 text-subtle transition hover:bg-surface/60 hover:text-ink" aria-label="Затвори"><x-icon name="x-mark" size="5" /></button>
                    </div>
                    {{-- Пълно описание --}}
                    <div class="space-y-4 p-5">
                        <template x-if="member.tone">
                            <p class="text-sm italic text-muted" x-text="member.tone"></p>
                        </template>
                        <template x-if="member.bio">
                            <div>
                                <p class="mb-1 font-mono text-[11px] uppercase tracking-wider text-subtle">Био</p>
                                <p class="whitespace-pre-line text-sm leading-relaxed text-ink" x-text="member.bio"></p>
                            </div>
                        </template>
                        <template x-if="member.background">
                            <div>
                                <p class="mb-1 font-mono text-[11px] uppercase tracking-wider text-subtle">Професионален опит</p>
                                <p class="text-sm leading-relaxed text-ink" x-text="member.background"></p>
                            </div>
                        </template>
                        <template x-if="member.education">
                            <div>
                                <p class="mb-1 font-mono text-[11px] uppercase tracking-wider text-subtle">Образование</p>
                                <p class="text-sm leading-relaxed text-ink" x-text="member.education"></p>
                            </div>
                        </template>
                        <template x-if="member.skills && member.skills.length">
                            <div>
                                <p class="mb-1.5 font-mono text-[11px] uppercase tracking-wider text-subtle">Умения</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="skill in member.skills" :key="skill">
                                        <span class="rounded-full border border-line bg-surface-subtle px-2.5 py-1 text-xs text-muted" x-text="skill"></span>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <template x-if="member.traits && Object.keys(member.traits).length">
                            <div>
                                <p class="mb-1.5 font-mono text-[11px] uppercase tracking-wider text-subtle">Черти</p>
                                <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                                    <template x-for="[k,label] in Object.entries(traitLabels)" :key="k">
                                        <template x-if="member.traits[k] !== undefined">
                                            <div>
                                                <div class="flex justify-between text-xs"><span class="text-ink" x-text="label"></span><span class="tabular-nums text-muted" x-text="member.traits[k]"></span></div>
                                                <div class="h-1.5 overflow-hidden rounded-full bg-surface-subtle"><div class="h-full rounded-full" :style="'width:'+member.traits[k]+'%; background: var(--color-char-'+member.color+')'"></div></div>
                                            </div>
                                        </template>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    {{-- Линк към пълния профил --}}
                    <div class="flex items-center justify-end gap-2 border-t border-line p-5">
                        <a :href="member.profile_url" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:text-primary-hover">Виж пълния профил <x-icon name="arrow-up-right" size="4" /></a>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Reject reason modal (за предложени задачи) --}}
    <div x-show="rejecting" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-on:keydown.escape.window="rejecting = null">
        <div class="w-full max-w-md rounded-xl bg-surface border border-line shadow-popover p-5 space-y-3" x-on:click.outside="rejecting = null">
            <h2 class="text-base font-semibold text-ink">Защо не одобряваме?</h2>
            <p class="text-sm text-muted">Причината влиза в паметта на служителя — следващите предложения ще я отчитат.</p>
            <textarea x-model="reason" rows="3" class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" placeholder="Кратка причина…"></textarea>
            <div class="flex justify-end gap-2">
                <x-button variant="ghost" size="sm" x-on:click="rejecting = null">Отказ</x-button>
                <x-button variant="danger" size="sm" x-on:click="submitReject()">Отхвърли</x-button>
            </div>
        </div>
    </div>

    <div x-show="error" x-cloak class="fixed bottom-4 right-4 z-50 rounded-lg bg-danger-soft text-danger-strong px-4 py-2 text-sm shadow-card" x-text="error"></div>

    <div x-show="success" x-cloak class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-line bg-surface px-4 py-3 text-sm shadow-popover">
        <p class="font-medium text-ink" x-text="success?.message"></p>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <template x-if="success?.tasks_url">
                <a :href="success.tasks_url" class="text-sm font-medium text-primary hover:text-primary-hover">Към Задачи →</a>
            </template>
            <template x-if="success?.decisions_url">
                <a :href="success.decisions_url" class="text-sm font-medium text-primary hover:text-primary-hover">Остани в Предложения</a>
            </template>
            <button type="button" class="text-xs text-subtle hover:text-ink" x-on:click="success = null">Затвори</button>
        </div>
    </div>

    @include('client.org._knowledge-modal')
</div>

@push('scripts')
<script>
function decisions(cfg) {
    return {
        done: [],
        rejecting: null,
        reason: '',
        error: '',
        success: null,
        member: null,
        reviewing: false,
        traitLabels: { risk: 'Риск', creativity: 'Креативност', precision: 'Прецизност', autonomy: 'Автономност', tempo: 'Темпо' },
        openMember(m) { this.member = m; },
        async runReview() {
            if (this.reviewing) return;
            this.reviewing = true;
            const d = await this._post(cfg.reviewUrl, {});
            this.reviewing = false;
            if (d) this.showSuccess(d);
        },
        async _post(url, body) {
            this.error = '';
            try {
                const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) });
                const d = await r.json().catch(() => ({}));
                if (!r.ok || !d.ok) {
                    if (d.superseded) { alert(d.error || 'Организацията се промени — нужно е ре-ревю.'); location.reload(); return null; }
                    this.error = d.message || d.error || 'Грешка.'; return null;
                }
                return d;
            } catch (e) { this.error = 'Мрежова грешка.'; return null; }
        },
        showSuccess(d) {
            if (d.message) {
                this.success = {
                    message: d.message,
                    tasks_url: d.tasks_url || null,
                    decisions_url: d.decisions_url || null,
                };
            } else if (d.materialize && d.materialize !== 'task') {
                this.success = { message: 'Одобрено.', tasks_url: null, decisions_url: null };
            }
        },
        // Структурни предложения + run approvals.
        async act(decision, item) {
            if (decision === 'reject') {
                const msg = item.kind === 'proposal'
                    ? 'Отхвърляне на предложението? Действието е необратимо.'
                    : 'Отхвърлянето прекратява изпълнението. Сигурен ли си?';
                if (!confirm(msg)) return;
            }
            const url = decision === 'approve' ? cfg.approveUrl : cfg.rejectUrl;
            const body = item.kind === 'proposal'
                ? { kind: 'proposal', id: item.id }
                : { kind: 'run_approval', flow_run_id: item.flow_run_id, node_key: item.node_key };
            const d = await this._post(url, body);
            if (d) {
                this.done.push(item.kind + '-' + (item.id || (item.flow_run_id + '-' + item.node_key)));
                if (decision === 'approve') this.showSuccess(d);
            }
        },
        // Предложени задачи.
        async approveTask(id, run) {
            const d = await this._post(cfg.approveUrl, { kind: 'assistant_task', id, run });
            if (d) {
                this.done.push('assistant_task-' + id);
                this.showSuccess(d);
            }
        },
        rejectTask(id) { this.rejecting = id; this.reason = ''; },
        async submitReject() {
            if (!this.reason.trim()) return;
            const id = this.rejecting;
            const d = await this._post(cfg.rejectUrl, { kind: 'assistant_task', id, reason: this.reason });
            if (d) { this.done.push('assistant_task-' + id); this.rejecting = null; }
        },
    };
}
</script>
@endpush
@endsection
