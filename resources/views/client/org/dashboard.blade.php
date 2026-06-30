@extends('layouts.client')

@section('title', 'Табло')

@section('content')
@php
    $runStatusLabels = ['pending' => 'Чака', 'running' => 'Изпълнява се', 'waiting_approval' => 'Чака одобрение', 'completed' => 'Завършен', 'failed' => 'Провален'];
@endphp
<div x-data="dashboardLive(@js($state))" class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Табло</h1>
            <p class="text-muted">{{ $company->name }} — преглед на текущата работа.</p>
        </div>
        <div class="flex items-center gap-2">
            @include('client.org._wizard-reset')
            <x-button :href="route('client.org.tasks.new')" icon="plus">Нова задача</x-button>
        </div>
    </div>

    {{-- Банер: задачи, чакащи знание (§2-етапни задачи) --}}
    <a href="{{ route('client.org.decisions') }}" x-show="(state.task_counts.needs_knowledge || 0) > 0" x-cloak
       class="flex items-center gap-3 rounded-xl border border-char-amber-soft bg-char-amber-soft/40 px-4 py-3 transition hover:border-char-amber">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-char-amber-soft text-char-amber-strong"><x-icon name="book-open" size="4" /></span>
        <p class="text-sm text-ink">
            <span class="font-semibold tabular-nums" x-text="state.task_counts.needs_knowledge"></span>
            <span x-text="(state.task_counts.needs_knowledge === 1) ? 'задача чака да въведеш информация' : 'задачи чакат да въведеш информация'"></span>,
            преди да могат да се изпълнят.
        </p>
        <span class="ml-auto text-xs font-medium text-char-amber-strong">Виж →</span>
    </a>

    {{-- Бързи числа --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <a href="{{ route('client.org.tasks.index', ['tab' => 'ready']) }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
            <p class="text-xs text-muted">За изпълнение</p>
            <p class="text-2xl font-semibold text-ink tabular-nums" x-text="(state.task_counts.ready || 0) + (state.task_counts.generating || 0)">{{ $counts['ready'] + $counts['generating'] }}</p>
            <p class="text-xs text-subtle" x-show="(state.task_counts.generating || 0) > 0">
                <span x-text="state.task_counts.generating"></span> генерират flow
            </p>
        </a>
        @if ($counts['pending_approval'] > 0)
            <a href="{{ route('client.org.tasks.index', ['tab' => 'proposed']) }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
                <p class="text-xs text-muted">Чака преглед на flow</p>
                <p class="text-2xl font-semibold text-ink tabular-nums" x-text="state.task_counts.pending_approval">{{ $counts['pending_approval'] }}</p>
                <p class="text-xs text-subtle">Одобри дизайна в Предложения</p>
            </a>
        @endif
        <a href="{{ route('client.org.decisions') }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
            <p class="text-xs text-muted">Предложения</p>
            <p class="text-2xl font-semibold text-ink tabular-nums">{{ $decisionsPreview['total'] }}</p>
        </a>
        <a href="{{ route('client.org.billing') }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
            <p class="text-xs text-muted">Кредити</p>
            <p class="text-2xl font-semibold text-ink tabular-nums" x-text="state.credits.available">{{ $credits['available'] }}</p>
            <p class="text-xs text-subtle tabular-nums" x-show="state.credits.reserved > 0">резервирани: <span x-text="state.credits.reserved"></span></p>
        </a>
    </div>

    {{-- Пулс на деня — дневният „standup" дайджест на Управителя (OrgDigestJob) --}}
    @if ($digest)
        <div class="rounded-xl border border-line bg-surface p-4">
            <div class="flex items-center justify-between gap-3 mb-1.5">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-ink">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                    Пулс на деня
                </h2>
                <a href="{{ route('client.org.chronicle') }}" class="text-xs text-primary hover:text-primary-hover shrink-0">Хроника →</a>
            </div>
            <div class="text-sm text-muted leading-relaxed">
                <x-prose :text="$digest->summary" />
            </div>
            <p class="text-xs text-subtle tabular-nums mt-2">{{ $digest->created_at?->isoFormat('D MMMM, HH:mm') }}</p>
        </div>
    @endif

    <div class="grid lg:grid-cols-3 gap-6">
        {{-- Текущ поток (живо) --}}
        <div class="lg:col-span-2 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-ink">Текущ поток</h2>
                <div class="flex items-center gap-3 shrink-0">
                    <span class="text-xs text-subtle" role="status" aria-live="polite" x-text="polling ? 'обновява се…' : ''"></span>
                    <a href="{{ route('client.org.live') }}" class="text-xs text-primary hover:text-primary-hover">Детайли →</a>
                </div>
            </div>

            <template x-if="state.active_runs.length === 0 && state.recent_runs.length === 0">
                <x-empty-state title="Тихо е" description="Няма активни или скорошни изпълнения. Пусни задача от Задачи или Профил на служителя." />
            </template>

            {{-- Активни --}}
            <template x-for="r in state.active_runs" :key="r.id">
                <div class="rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-ink truncate" x-text="r.task_title || r.flow"></p>
                            <p class="text-xs text-muted truncate">
                                <span x-show="r.member" x-text="r.member ? (r.member.name + ' · ' + r.member.role) : ''"></span>
                            </p>
                        </div>
                        <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-md bg-info-soft text-info-strong">
                            <span class="w-1.5 h-1.5 rounded-full bg-info-strong animate-pulse"></span>
                            <span x-text="@js($runStatusLabels)[r.status] || r.status"></span>
                        </span>
                    </div>
                    <div class="mt-3" x-show="r.percent !== null">
                        <div class="h-1.5 rounded-full bg-surface-subtle overflow-hidden" role="progressbar" :aria-valuenow="r.percent" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full rounded-full bg-primary transition-all" :style="`width: ${r.percent}%`"></div>
                        </div>
                        <p class="text-xs text-subtle tabular-nums mt-1" x-text="`${r.percent}%`"></p>
                    </div>
                </div>
            </template>

            {{-- Скорошни --}}
            <template x-for="r in state.recent_runs" :key="'recent-' + r.id">
                <a :href="r.result_url" class="block rounded-xl border border-line bg-surface p-3 hover:border-line-strong transition">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm text-ink truncate" x-text="r.task_title || r.flow"></p>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-md"
                              :class="r.status === 'completed' ? 'bg-success-soft text-success-strong' : 'bg-danger-soft text-danger-strong'"
                              x-text="@js($runStatusLabels)[r.status] || r.status"></span>
                    </div>
                </a>
            </template>
        </div>

        {{-- Странична колона --}}
        <div class="space-y-6">
            {{-- Чакащи предложения --}}
            <div class="rounded-xl border border-line bg-surface p-4">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-sm font-semibold text-ink">Чакащи предложения</h2>
                    <a href="{{ route('client.org.decisions') }}" class="text-xs text-primary hover:text-primary-hover">Виж всички →</a>
                </div>
                @if ($decisionsPreview['total'] > 0)
                    <div class="flex flex-wrap items-center gap-1.5 mb-2 text-[10px] text-muted">
                        @if ($decisionsPreview['counts']['structural'] > 0)
                            <span class="rounded-full border border-line bg-surface-subtle px-2 py-0.5">Структурни: {{ $decisionsPreview['counts']['structural'] }}</span>
                        @endif
                        @if ($decisionsPreview['counts']['tasks'] > 0)
                            <span class="rounded-full border border-line bg-surface-subtle px-2 py-0.5">Задачи: {{ $decisionsPreview['counts']['tasks'] }}</span>
                        @endif
                        @if ($decisionsPreview['counts']['runs'] > 0)
                            <span class="rounded-full border border-line bg-surface-subtle px-2 py-0.5">Изпълнения: {{ $decisionsPreview['counts']['runs'] }}</span>
                        @endif
                    </div>
                @endif
                @forelse ($decisionsPreview['items'] as $item)
                    @include('client.org._dashboard-decision-row', ['item' => $item])
                @empty
                    <p class="text-sm text-muted py-2">Няма чакащи предложения.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function dashboardLive(initial) {
    return {
        state: initial,
        polling: false,
        timer: null,
        init() { this.schedule(); document.addEventListener('visibilitychange', () => this.schedule()); },
        schedule() {
            clearTimeout(this.timer);
            if (document.hidden) return;
            const hasActive = this.state.active_runs && this.state.active_runs.length > 0;
            this.timer = setTimeout(() => this.poll(), hasActive ? 2500 : 8000);
        },
        async poll() {
            this.polling = true;
            try {
                const r = await fetch(@js(route('client.org.dashboard.state')), { headers: { 'Accept': 'application/json' } });
                if (r.ok) this.state = await r.json();
            } catch (e) { /* тих inline провал — без reload */ }
            this.polling = false;
            this.schedule();
        },
    };
}
</script>
@endpush
@endsection
