@extends('layouts.client')

@section('title', 'Профил на служителя')

@section('content')
@php
    $persona = $member->persona;
    $c = $member->functionColor();   // цвят = функция/домейн (§10.1), не id % 7
    $role = $member->roleTitle();
    $levels = ['low' => '★', 'medium' => '★★', 'high' => '★★★', 'ultra' => '★★★★', 'god' => '★★★★★'];
    $levelLabels = ['low' => 'ниско', 'medium' => 'средно', 'high' => 'високо', 'ultra' => 'много високо', 'god' => 'най-високо'];
    $actLabels = ['draft' => 'чернова', 'act' => 'реално действие', 'mixed' => 'смесен режим'];
    // Начални данни за редактора (всичките 5 черти гарантирани) + роля за помощника.
    $personaSeed = $persona ? [
        'name' => $persona->name,
        'age' => $persona->age,
        'gender' => $persona->gender,
        'ethnicity' => $persona->ethnicity,
        'background' => $persona->background,
        'tone' => $persona->tone,
        'bio' => $persona->bio,
        'skills' => (array) $persona->skills,
        'traits' => array_merge(['risk' => 50, 'creativity' => 50, 'precision' => 50, 'autonomy' => 60, 'tempo' => 55], (array) $persona->traits),
    ] : null;
@endphp
<div x-data="memberCard({
        tierUrl: '{{ route('client.org.member.tier', $member->id) }}',
        deptUrl: '{{ route('client.org.member.promote-dept', $member->id) }}',
        avatarUrl: '{{ route('client.org.member.avatar', $member->id) }}',
        taskTierTpl: '{{ route('client.org.tasks.tier', ['task' => '__TASK__']) }}',
        genTpl: '{{ route('client.org.tasks.generate', ['task' => '__TASK__']) }}',
        genStatusTpl: '{{ route('client.org.tasks.gen-status', ['task' => '__TASK__', 'token' => '__TOKEN__']) }}',
        runTpl: '{{ route('client.org.tasks.run', ['task' => '__TASK__']) }}',
        resultTpl: '{{ route('client.runs.result', ['run' => '__RUN__']) }}',
        tickUrl: '{{ route('client.org.member.tick-now', $member->id) }}',
        isDirector: {{ $member->kind === 'director' ? 'true' : 'false' }},
        suggestUrl: '{{ route('client.org.personas.suggest-field') }}',
        csrf: '{{ csrf_token() }}',
        role: @js($role),
        updateUrl: '{{ $persona ? route('client.org.personas.update', $persona->id) : '' }}',
        persona: @js($personaSeed),
     })">
    <a href="{{ route('client.org.roster') }}" class="text-sm text-muted hover:text-ink">← Към екипа</a>

    <div class="mt-4 grid lg:grid-cols-[1fr_1.4fr] gap-6">
        {{-- Профил --}}
        <div class="rounded-xl border border-line bg-surface p-5">
            <div class="flex flex-col items-center text-center">
                @if ($persona?->hasReadyAvatar())
                    <img src="{{ $persona->avatar_url }}" alt="{{ $persona->name }}" class="h-24 w-24 rounded-full object-cover ring-4 ring-char-{{ $c }}-soft">
                @else
                    <span class="flex h-24 w-24 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong text-3xl font-semibold ring-4 ring-char-{{ $c }}-soft">
                        {{ mb_substr($persona->name ?? $member->display_name, 0, 1) }}</span>
                @endif
                <h1 class="mt-3 text-xl font-semibold text-ink">{{ $member->fullName() }}</h1>
                <p class="text-sm text-muted">{{ $role }}@if ($persona?->age) · {{ $persona->age }}г.@endif</p>
                @if ($persona?->tone)<p class="text-sm text-subtle mt-1"><x-prose :text="$persona->tone" inline /></p>@endif
            </div>

            @if ($persona?->bio)<x-prose :text="$persona->bio" class="mt-4 text-sm text-ink leading-relaxed" />@endif

            {{-- Умения (стабилни компетентности, ≠ задачи) --}}
            @if ($persona && ! empty($persona->skills))
                <div class="mt-4">
                    <p class="text-xs font-semibold text-muted mb-1.5">Умения</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($persona->skills as $skill)
                            <span class="px-2 py-0.5 rounded-md text-xs bg-char-{{ $c }}-soft text-char-{{ $c }}-strong">{{ $skill }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Статове --}}
            @if ($persona && $persona->traits)
                <div class="mt-4 space-y-2">
                    @foreach (['risk' => 'Риск', 'creativity' => 'Креативност', 'precision' => 'Прецизност', 'autonomy' => 'Автономност', 'tempo' => 'Темпо'] as $k => $label)
                        @if (isset($persona->traits[$k]))
                            <div>
                                <div class="flex justify-between text-xs"><span class="text-ink">{{ $label }}</span><span class="tabular-nums text-muted">{{ (int) $persona->traits[$k] }}</span></div>
                                <div class="h-1.5 rounded-full bg-surface-subtle overflow-hidden"><div class="h-full rounded-full bg-char-{{ $c }}" style="width: {{ (int) $persona->traits[$k] }}%"></div></div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="mt-5 flex flex-wrap gap-2">
                <x-button size="sm" :href="route('client.org.chat', $member->id)">Чат с {{ $persona->name ?? 'члена' }}</x-button>
                <x-button size="sm" variant="secondary" x-on:click="regenAvatar()" x-bind:disabled="busy">Регенерирай аватар</x-button>
                @if ($member->kind === 'director')
                    <x-button size="sm" variant="secondary" x-on:click="tickNow()" x-bind:disabled="busy">Пусни преглед (tick)</x-button>
                @endif
            </div>
        </div>

        {{-- Контроли + задачи --}}
        <div class="space-y-5">
            {{-- Редактирай персонажа (помощ/черти) --}}
            @if ($persona)
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-ink">Редактирай профила</h2>
                        <x-button size="sm" variant="secondary" x-on:click="editing = !editing"
                                  x-text="editing ? 'Скрий' : 'Редактирай'"></x-button>
                    </div>
                    <div x-show="editing" x-cloak class="mt-4 space-y-4">
                        @include('client.org._persona-fields', ['modelPrefix' => 'persona', 'color' => $c])
                        <div class="flex items-center gap-3 pt-1">
                            <x-button size="sm" x-on:click="savePersona()" x-bind:disabled="busy">Запази профила</x-button>
                            <p x-show="saveMsg" x-text="saveMsg" class="text-xs text-success-strong"></p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Ниво на члена --}}
            <div class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-sm font-semibold text-ink mb-1">Ниво на члена</h2>
                <p class="text-xs text-muted mb-3">Повишение/понижение реценообразува всичките му задачи без явен override.</p>
                <div class="flex items-center gap-2">
                    <x-select x-model="tier" class="w-44">
                        @foreach ($levels as $val => $stars)
                            <option value="{{ $val }}" @selected($member->default_star_tier === $val)>{{ $stars }} {{ $levelLabels[$val] ?? $val }}</option>
                        @endforeach
                    </x-select>
                    <x-button size="sm" x-on:click="saveTier()" x-bind:disabled="busy">Запази</x-button>
                    <template x-if="isDirector">
                        <x-button size="sm" variant="secondary" x-on:click="promoteDept()" x-bind:disabled="busy">Повиши целия отдел</x-button>
                    </template>
                </div>
                <p x-show="msg" x-text="msg" class="text-xs text-success-strong mt-2"></p>
            </div>

            {{-- Задачи --}}
            @if ($member->kind === 'assistant')
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-ink">Задачи</h2>
                        <a href="{{ route('client.org.tasks.new', ['assistant' => $member->id]) }}"
                           class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:text-primary-hover">＋ Нова задача</a>
                    </div>
                    @forelse ($member->tasks as $task)
                        <div class="flex items-center justify-between gap-3 py-2 border-b border-line last:border-0"
                             x-data="{ st: '{{ $task->status }}', stage: '', running: false }">
                            <div class="min-w-0">
                                <p class="text-sm text-ink truncate">{{ $task->title }}</p>
                                <p class="text-xs text-subtle">{{ $actLabels[$task->act_mode] ?? $task->act_mode }} · <span x-text="({ proposed: 'предложена', generating: 'създава се', pending_approval: 'чака одобрение', ready: 'готова', disabled: 'изключена', failed: 'провалена' })[st] || st"></span>
                                    · <span class="tabular-nums">{{ $levels[$task->effectiveStarTier()->value] ?? '★' }}</span>
                                    @if ($task->inheritsTier())<span class="text-subtle">(наследява)</span>@else<span class="text-primary">(ръчно зададено)</span>@endif
                                    <span x-show="stage" class="inline-flex items-center gap-1 text-accent align-middle">·<x-org.bolt-spinner size="14" /><span x-text="stage"></span></span>
                                </p>
                                @php($rinfo = $task->flow_id ? ($taskRuns[$task->flow_id] ?? null) : null)
                                @if ($rinfo && ($rinfo['active'] || $rinfo['completed'] || $rinfo['failed'] || $rinfo['latest']))
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-muted">
                                        @if ($rinfo['active'])<span class="inline-flex items-center gap-1 text-accent"><span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>{{ $rinfo['active'] }} активни</span>@endif
                                        @if ($rinfo['completed'])<span class="text-success-strong tabular-nums">{{ $rinfo['completed'] }} ✓</span>@endif
                                        @if ($rinfo['failed'])<span class="text-danger tabular-nums">{{ $rinfo['failed'] }} ✗</span>@endif
                                        @if (! empty($rinfo['last_run_at']))<span class="text-subtle">· {{ $rinfo['last_run_at']->diffForHumans() }}</span>@endif
                                        @if ($rinfo['latest'])<a href="{{ route('client.runs.result', $rinfo['latest']['id']) }}" class="text-primary hover:text-primary-hover">Резултат →</a>@endif
                                    </p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <select class="text-xs rounded-md border border-line bg-surface px-2 py-1"
                                        @change="setTaskTier({{ $task->id }}, $event.target.value)">
                                    <option value="inherit" @selected($task->inheritsTier())>наследява</option>
                                    @foreach ($levels as $val => $stars)
                                        <option value="{{ $val }}" @selected(! $task->inheritsTier() && $task->star_tier === $val)>{{ $levelLabels[$val] ?? $val }}</option>
                                    @endforeach
                                </select>
                                <template x-if="st === 'pending_approval'">
                                    <a href="{{ route('client.org.decisions') }}" class="text-xs font-medium text-warning-strong hover:underline">Чака одобрение →</a>
                                </template>
                                <template x-if="st === 'proposed' || st === 'failed'">
                                    <x-button size="sm" variant="secondary" x-on:click="generateTask({{ $task->id }}, $el => st = $el)" x-bind:disabled="running">Създай предложение</x-button>
                                </template>
                                <template x-if="st === 'ready'">
                                    <x-button size="sm" x-on:click="runTask({{ $task->id }}, s => stage = s, s => st = s)" x-bind:disabled="running">Изпълни</x-button>
                                </template>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-subtle">Няма задачи още.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
function memberCard(cfg) {
    return {
        ...window.personaFormBase(cfg),
        tier: '{{ $member->default_star_tier }}', busy: false, msg: '', isDirector: cfg.isDirector,
        // Персона-редактор (помощ/черти):
        persona: cfg.persona || { traits: {} },
        editing: false, saveMsg: '',
        aiRole() { return cfg.role; },
        aiContext() { return this.persona; },
        aiApply(field, value) { this.persona[field] = value; },
        savePersona() {
            this.busy = true; this.saveMsg = '';
            fetch(cfg.updateUrl, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify(this.persona),
            }).then(r => r.json()).then(d => { this.saveMsg = d.ok ? 'Профилът е запазен.' : (d.message || 'Грешка при запис.'); })
              .catch(() => { this.saveMsg = 'Грешка при запис.'; })
              .finally(() => { this.busy = false; });
        },
        post(url, body) {
            this.busy = true; this.msg = '';
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }, body: JSON.stringify(body || {}) })
                .then(r => r.json()).finally(() => this.busy = false);
        },
        saveTier() { this.post(cfg.tierUrl, { tier: this.tier }).then(d => { if (d.ok) this.msg = 'Нивото е запазено. Задачите без override са реценообразувани.'; }); },
        promoteDept() { this.post(cfg.deptUrl, { tier: this.tier }).then(d => { if (d.ok) this.msg = 'Отделът е повишен.'; }); },
        regenAvatar() { this.post(cfg.avatarUrl).then(d => { if (d.ok) this.msg = 'Аватарът се регенерира…'; }); },
        tickNow() { this.post(cfg.tickUrl).then(d => { this.msg = d.ok ? (d.message || 'Тикът е пуснат.') : (d.error || 'Грешка.'); }); },
        setTaskTier(taskId, value) { this.post(cfg.taskTierTpl.replace('__TASK__', taskId), { tier: value }).then(d => { if (d.ok) this.msg = 'Нивото на задачата е обновено.'; }); },

        // „Генерирай" — материализира задачата във Flow (поллинг до ready).
        generateTask(taskId, setStatus) {
            this.busy = true;
            this.post(cfg.genTpl.replace('__TASK__', taskId)).then(d => {
                if (d.status === 'ready') { setStatus('ready'); this.busy = false; }
                else if (d.token) { this.pollGen(taskId, d.token, setStatus); }
                else { this.busy = false; }
            });
        },
        pollGen(taskId, token, setStatus) {
            const url = cfg.genStatusTpl.replace('__TASK__', taskId).replace('__TOKEN__', token);
            const t = async () => {
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (d.stage) this.msg = d.stage;   // покажи текущия етап (§5.4)
                    // Ревизиран lifecycle: генерацията спира на pending_approval (за преглед), не ready.
                    if (['pending_approval', 'ready'].includes(d.task_status)) {
                        clearInterval(this._g); this.busy = false; setStatus(d.task_status);
                        this.msg = d.task_status === 'pending_approval' ? 'Готово за преглед в Решения.' : 'Готово.';
                    } else if (d.status === 'failed' || d.task_status === 'failed') {
                        clearInterval(this._g); this.busy = false; this.msg = 'Генерацията се провали.';
                    }
                } catch (e) {}
            };
            t(); this._g = setInterval(t, 2500);
        },

        // „Изпълни" — wallet гейт + run + поллинг → Резултат екран.
        runTask(taskId, setStage, setStatus) {
            this.busy = true; setStatus('running'); setStage('пускам…');
            this.post(cfg.runTpl.replace('__TASK__', taskId)).then(d => {
                if (d.status === 'running' && d.poll_url) { this.pollRun(d.run_id, d.poll_url, setStage); }
                else if (d.status === 'generating') { setStage('генерира се…'); this.pollGen(taskId, d.token, () => setStatus('ready')); this.busy = false; }
                else { this.busy = false; setStatus('ready'); this.msg = d.message || 'Грешка при пускане.'; }
            }).catch(() => { this.busy = false; setStatus('ready'); });
        },
        pollRun(runId, pollUrl, setStage) {
            const t = async () => {
                try {
                    const d = await (await fetch(pollUrl, { headers: { 'Accept': 'application/json' } })).json();
                    setStage(d.label || d.step || d.status || 'тече…');
                    if (['completed', 'failed', 'waiting_approval'].includes(d.status)) {
                        clearInterval(this._r);
                        window.location = cfg.resultTpl.replace('__RUN__', runId);
                    }
                } catch (e) {}
            };
            t(); this._r = setInterval(t, 2000);
        },
    };
}
</script>
@endpush
@endsection
