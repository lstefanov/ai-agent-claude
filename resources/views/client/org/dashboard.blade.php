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
            <p class="text-muted">{{ $company->name }} — преглед на екипа и текущата работа.</p>
        </div>
        <div class="flex items-center gap-2">
            @include('client.org._wizard-reset')
            <x-button :href="route('client.org.tasks.new')" icon="plus">Нова задача</x-button>
        </div>
    </div>

    {{-- Бързи числа --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <a href="{{ route('client.org.tasks.index') }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
            <p class="text-xs text-muted">Предложени</p>
            <p class="text-2xl font-semibold text-ink tabular-nums" x-text="state.task_counts.pending_approval">{{ $counts['pending_approval'] }}</p>
        </a>
        <a href="{{ route('client.org.tasks.index') }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
            <p class="text-xs text-muted">За изпълнение</p>
            <p class="text-2xl font-semibold text-ink tabular-nums" x-text="state.task_counts.ready">{{ $counts['ready'] }}</p>
        </a>
        <a href="{{ route('client.org.decisions') }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
            <p class="text-xs text-muted">Чакат решение</p>
            <p class="text-2xl font-semibold text-ink tabular-nums">{{ $pending->count() }}</p>
        </a>
        <a href="{{ route('client.org.billing') }}" class="rounded-xl border border-line bg-surface p-4 hover:border-line-strong transition">
            <p class="text-xs text-muted">Кредити</p>
            <p class="text-2xl font-semibold text-ink tabular-nums" x-text="state.credits.available">{{ $credits['available'] }}</p>
            <p class="text-xs text-subtle tabular-nums" x-show="state.credits.reserved > 0">резервирани: <span x-text="state.credits.reserved"></span></p>
        </a>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        {{-- Текущ поток (живо) --}}
        <div class="lg:col-span-2 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-ink">Текущ поток</h2>
                <span class="text-xs text-subtle" role="status" aria-live="polite" x-text="polling ? 'обновява се…' : ''"></span>
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
            {{-- Чакащи решения --}}
            <div class="rounded-xl border border-line bg-surface p-4">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-sm font-semibold text-ink">Чакащи решения</h2>
                    <a href="{{ route('client.org.decisions') }}" class="text-xs text-primary hover:text-primary-hover">Виж всички →</a>
                </div>
                @forelse ($pending->take(4) as $d)
                    <div class="py-2 border-t border-line first:border-t-0 text-sm">
                        <p class="text-ink truncate">{{ $d['title'] ?? ($d['type'] ?? ($d['node_name'] ?? 'Решение')) }}</p>
                        <p class="text-xs text-subtle">{{ $d['kind'] === 'assistant_task' ? 'Предложена задача' : ($d['kind'] === 'run_approval' ? 'Одобрение на изпълнение' : 'Структурно предложение') }}</p>
                    </div>
                @empty
                    <p class="text-sm text-muted">Няма чакащи решения.</p>
                @endforelse
            </div>

            {{-- Екип --}}
            <div class="rounded-xl border border-line bg-surface p-4">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-sm font-semibold text-ink">Екип</h2>
                    <a href="{{ route('client.org.skill-tree') }}" class="text-xs text-primary hover:text-primary-hover">Карта на уменията →</a>
                </div>
                <div class="space-y-2">
                    @foreach ($members->take(6) as $m)
                        @php $c = $m->functionColor(); @endphp
                        <a href="{{ route('client.org.member', $m->id) }}" class="flex items-center gap-2 text-sm hover:text-ink">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong text-xs font-semibold">{{ mb_strtoupper(mb_substr($m->fullName(), 0, 1)) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="text-ink truncate block leading-tight">{{ $m->fullName() }}</span>
                                <span class="text-xs text-subtle truncate block leading-tight">{{ $m->roleTitle() }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
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
