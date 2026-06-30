{{-- Контролно табло: KPI лента + 30-дневна хистограма на активността.
     Рендира се при първа страница (без cursor) и се сменя при смяна на период. --}}
@php
    $k = $stats['kpis'];
    $hist = $stats['histogram'];
    $max = collect($hist)->max('count') ?: 1;
@endphp
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
    <div class="rounded-xl border border-line bg-surface p-4">
        <p class="text-xs text-muted">Събития днес</p>
        <p class="mt-0.5 text-2xl font-semibold tabular-nums text-ink">{{ number_format($k['events_today']) }}</p>
    </div>
    <div class="rounded-xl border border-line bg-surface p-4">
        <p class="text-xs text-muted">Активни изпълнения</p>
        <p class="mt-0.5 flex items-center gap-2 text-2xl font-semibold tabular-nums text-ink">
            {{ number_format($k['active_runs']) }}
            @if ($k['active_runs'] > 0)
                <span class="h-2 w-2 rounded-full bg-success animate-pulse"></span>
            @endif
        </p>
    </div>
    <div class="rounded-xl border border-line bg-surface p-4">
        <p class="text-xs text-muted">Кредити (период)</p>
        <p class="mt-0.5 text-2xl font-semibold tabular-nums text-ink">{{ number_format($k['credits_spent']) }}</p>
    </div>
    <div class="rounded-xl border border-line bg-surface p-4">
        <p class="text-xs text-muted">Изпълнени задачи</p>
        <p class="mt-0.5 text-2xl font-semibold tabular-nums text-ink">{{ number_format($k['tasks_completed']) }}</p>
    </div>
    <div class="rounded-xl border border-line bg-surface p-4">
        <p class="text-xs text-muted">Ново знание</p>
        <p class="mt-0.5 text-2xl font-semibold tabular-nums text-ink">{{ number_format($k['knowledge_added']) }}</p>
    </div>
</div>

<div class="mt-3 rounded-xl border border-line bg-surface p-4">
    <div class="mb-2 flex items-center justify-between">
        <p class="text-xs text-muted">Активност · последни 30 дни</p>
        <p class="text-xs tabular-nums text-subtle">пик: {{ number_format($max) }}</p>
    </div>
    <div class="flex h-12 items-end gap-[3px]">
        @foreach ($hist as $bar)
            @php
                $h = $bar['count'] > 0 ? max(8, (int) round($bar['count'] / $max * 100)) : 4;
                $cls = $bar['count'] > 0 ? 'bg-primary/70 hover:bg-primary' : 'bg-line';
            @endphp
            <div class="flex-1 rounded-t transition-all {{ $cls }}" style="height: {{ $h }}%"
                 title="{{ \Illuminate\Support\Carbon::parse($bar['date'])->isoFormat('D MMM') }}: {{ $bar['count'] }}"></div>
        @endforeach
    </div>
</div>
