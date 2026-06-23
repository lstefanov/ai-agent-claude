@extends('layouts.client')

@section('title', 'Карта на героя')

@section('content')
@php
    $persona = $member->persona;
    $charColors = ['purple', 'teal', 'coral', 'blue', 'amber', 'pink', 'green'];
    $c = $charColors[$member->id % count($charColors)];
    $levels = ['low' => '★', 'medium' => '★★', 'high' => '★★★', 'ultra' => '★★★★', 'god' => '★★★★★'];
@endphp
<div class="max-w-4xl mx-auto px-6 py-8"
     x-data="memberCard({
        tierUrl: '{{ route('client.org.member.tier', $member->id) }}',
        deptUrl: '{{ route('client.org.member.promote-dept', $member->id) }}',
        avatarUrl: '{{ route('client.org.member.avatar', $member->id) }}',
        taskTierTpl: '{{ route('client.org.tasks.tier', ['task' => '__TASK__']) }}',
        isDirector: {{ $member->kind === 'director' ? 'true' : 'false' }},
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
                <h1 class="mt-3 text-xl font-semibold text-ink">{{ $persona->name ?? $member->display_name }}</h1>
                <p class="text-sm text-muted">{{ $member->display_name }}@if ($persona?->age) · {{ $persona->age }}г.@endif</p>
                @if ($persona?->tone)<p class="text-sm text-subtle mt-1">{{ $persona->tone }}</p>@endif
            </div>

            @if ($persona?->bio)<p class="mt-4 text-sm text-ink leading-relaxed">{{ $persona->bio }}</p>@endif

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
                <x-button size="sm" variant="secondary" x-on:click="regenAvatar()" x-bind:disabled="busy">Регенерирай аватар</x-button>
            </div>
        </div>

        {{-- Контроли + задачи --}}
        <div class="space-y-5">
            {{-- Ниво на члена --}}
            <div class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-sm font-semibold text-ink mb-1">Ниво на члена</h2>
                <p class="text-xs text-muted mb-3">Повишение/понижение реценообразува всичките му задачи без явен override.</p>
                <div class="flex items-center gap-2">
                    <x-select x-model="tier" class="w-44">
                        @foreach ($levels as $val => $stars)
                            <option value="{{ $val }}" @selected($member->default_star_tier === $val)>{{ $stars }} {{ $val }}</option>
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
                    <h2 class="text-sm font-semibold text-ink mb-3">Задачи</h2>
                    @forelse ($member->tasks as $task)
                        <div class="flex items-center justify-between gap-3 py-2 border-b border-line last:border-0">
                            <div class="min-w-0">
                                <p class="text-sm text-ink truncate">{{ $task->title }}</p>
                                <p class="text-xs text-subtle">{{ $task->act_mode }} · {{ $task->status }}
                                    · <span class="tabular-nums">{{ $levels[$task->effectiveStarTier()->value] ?? '★' }}</span>
                                    @if ($task->inheritsTier())<span class="text-subtle">(наследява)</span>@else<span class="text-primary">(override)</span>@endif
                                </p>
                            </div>
                            <select class="text-xs rounded-md border border-line bg-surface px-2 py-1"
                                    @change="setTaskTier({{ $task->id }}, $event.target.value)">
                                <option value="inherit" @selected($task->inheritsTier())>наследява</option>
                                @foreach ($levels as $val => $stars)
                                    <option value="{{ $val }}" @selected(! $task->inheritsTier() && $task->star_tier === $val)>{{ $val }}</option>
                                @endforeach
                            </select>
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
        tier: '{{ $member->default_star_tier }}', busy: false, msg: '', isDirector: cfg.isDirector,
        post(url, body) {
            this.busy = true; this.msg = '';
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }, body: JSON.stringify(body || {}) })
                .then(r => r.json()).finally(() => this.busy = false);
        },
        saveTier() { this.post(cfg.tierUrl, { tier: this.tier }).then(d => { if (d.ok) this.msg = 'Нивото е запазено. Задачите без override са реценообразувани.'; }); },
        promoteDept() { this.post(cfg.deptUrl, { tier: this.tier }).then(d => { if (d.ok) this.msg = 'Отделът е повишен.'; }); },
        regenAvatar() { this.post(cfg.avatarUrl).then(d => { if (d.ok) this.msg = 'Аватарът се регенерира…'; }); },
        setTaskTier(taskId, value) { this.post(cfg.taskTierTpl.replace('__TASK__', taskId), { tier: value }).then(d => { if (d.ok) this.msg = 'Нивото на задачата е обновено.'; }); },
    };
}
</script>
@endpush
@endsection
