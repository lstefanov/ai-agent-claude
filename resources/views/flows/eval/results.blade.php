@extends('layouts.app')

@section('title', 'Eval резултати — ' . $flow->name)

@php
    $scoreBg = function ($score) {
        if ($score === null) return 'text-subtle';
        if ($score >= 85) return 'bg-green-50 text-green-700';
        if ($score >= 65) return 'bg-amber-50 text-amber-700';
        return 'bg-red-50 text-red-700';
    };
    $fmtCost = fn ($c) => '$' . rtrim(rtrim(number_format((float) $c, 4), '0'), '.');
@endphp

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
        <div>
            <div class="text-sm text-subtle mb-1">
                <a href="{{ route('companies.show', $flow->company) }}" class="hover:text-primary">{{ $flow->company->name }}</a>
                <span class="mx-1">/</span>
                <a href="{{ route('flows.show', $flow) }}" class="hover:text-primary">{{ $flow->name }}</a>
                <span class="mx-1">/</span>
                <a href="{{ route('flows.eval.index', $flow) }}" class="hover:text-primary">Eval</a>
            </div>
            <h1 class="text-2xl font-bold text-ink">Eval резултати — тестове × нива</h1>
        </div>
        <div class="flex items-center gap-3">
            @if($versions->count() > 1)
                <form method="GET" class="flex items-center gap-2 text-sm">
                    <label class="text-muted">Версия:</label>
                    <select name="version" onchange="this.form.submit()" class="border border-line rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
                        @foreach($versions as $v)
                            <option value="{{ $v->id }}" @selected($version && $v->id === $version->id)>{{ $v->name }}@if($v->is_active) (активна)@endif</option>
                        @endforeach
                    </select>
                </form>
            @endif
            <a href="{{ route('flows.eval.index', $flow) }}" class="text-sm text-muted hover:text-primary">← Тестове / пускане</a>
        </div>
    </div>

    @if(empty($points) && collect($matrix)->flatten(1)->filter()->isEmpty())
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-5 text-sm">
            Още няма eval резултати за <b>{{ $version->name ?? 'тази версия' }}</b>. Пусни eval от <a href="{{ route('flows.eval.index', $flow) }}" class="underline">страницата с тестове</a>.
        </div>
    @else

    {{-- Recommendation: качество + стойност (не само LOW) --}}
    @if($recommendation)
        @php $bq = $recommendation['best_quality']; $bv = $recommendation['best_value']; @endphp
        <div class="bg-info-soft border border-info rounded-xl p-5 mb-6">
            <div class="font-semibold text-primary-hover mb-2">Препоръка (за {{ $version->name ?? '' }})</div>
            @if($recommendation['same'])
                <div class="text-sm text-primary-hover">
                    Ниво <b class="uppercase">{{ $bv['level'] }}</b> е едновременно <b>най-качествено</b> и <b>най-изгодно</b> — лесен избор.
                    Среден Score {{ round($bv['score']) }}/100 · {{ $fmtCost($bv['cost']) }}/run.
                </div>
            @else
                <div class="grid sm:grid-cols-2 gap-3 text-sm">
                    <div class="bg-surface rounded-lg p-3 border border-info">
                        <div class="font-medium text-ink">Най-добро качество</div>
                        <div class="text-muted mt-0.5">Ниво <b class="uppercase">{{ $bq['level'] }}</b> — {{ round($bq['score']) }}/100 · {{ $fmtCost($bq['cost']) }}/run</div>
                    </div>
                    <div class="bg-surface rounded-lg p-3 border border-info">
                        <div class="font-medium text-ink">Най-изгодно <span class="text-subtle font-normal">(качество за пара)</span></div>
                        <div class="text-muted mt-0.5">
                            Ниво <b class="uppercase">{{ $bv['level'] }}</b> — {{ round($bv['score']) }}/100 · {{ $fmtCost($bv['cost']) }}/run
                            @if(($bv['cost'] ?? 0) > 0)
                                <span class="block text-xs text-subtle">{{ number_format($recommendation['efficiency'], 0) }} точки/$ = {{ round($bv['score']) }} ÷ {{ $fmtCost($bv['cost']) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="text-xs text-primary mt-2">
                    Най-доброто качество ({{ strtoupper($bq['level']) }}) дава <b>+{{ $recommendation['delta_score'] }} точки</b>@if($recommendation['delta_cost_pct'] !== null) срещу <b>{{ $recommendation['delta_cost_pct'] > 0 ? '+' : '' }}{{ $recommendation['delta_cost_pct'] }}% цена</b>@endif спрямо най-изгодното ({{ strtoupper($bv['level']) }}).
                    За клиентски материали → избери качество; за чернови/вътрешно → изгодно.
                </div>
            @endif
        </div>
    @endif

    {{-- Matrix: тестове (редове) × нива (колони) --}}
    <div class="bg-surface rounded-xl border border-line overflow-x-auto mb-3">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-line text-muted">
                    <th class="text-left px-4 py-3 font-medium">Тест \ Ниво</th>
                    @foreach($levels as $level)
                        <th class="px-4 py-3 font-medium uppercase text-xs">
                            {{ $level }}
                            @if($version && $version->model_level === $level)
                                <span class="block text-[10px] text-primary normal-case font-normal" title="Реалното (родно) ниво на версията — другите колони са хипотетични пре-закачания">★ конфигурирано</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($cases as $case)
                    <tr class="border-b border-line">
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink">{{ $case->name }}</div>
                            <div class="text-xs text-subtle">тежест {{ rtrim(rtrim(number_format($case->weight, 1), '0'), '.') }}@unless($case->is_active) · неактивен @endunless</div>
                        </td>
                        @foreach($levels as $level)
                            @php $cell = $matrix[$case->id][$level] ?? null; @endphp
                            <td class="px-2 py-2 text-center">
                                @if(!$cell)
                                    <span class="text-subtle">—</span>
                                @elseif($cell['status'] === 'completed')
                                    <a href="{{ route('flows.eval.run-detail', [$flow, $cell['run_id']]) }}"
                                       class="inline-block rounded-lg px-3 py-2 {{ $scoreBg($cell['score']) }} hover:ring-2 hover:ring-primary/30 transition">
                                        <span class="font-bold">{{ round($cell['score']) }}</span>
                                        <span class="block text-xs opacity-70">{{ $fmtCost($cell['cost']) }}</span>
                                    </a>
                                @elseif($cell['status'] === 'failed')
                                    <a href="{{ route('flows.eval.run-detail', [$flow, $cell['run_id']]) }}"
                                       class="inline-block rounded-lg px-3 py-2 bg-red-50 text-red-600 hover:ring-2 hover:ring-red-300 transition"
                                       title="{{ $cell['error'] }}">✗</a>
                                @else
                                    <span class="text-subtle animate-pulse" title="{{ $cell['status'] }}">⏳</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                {{-- Aggregate row --}}
                @if(!empty($aggregate))
                    <tr class="border-t-2 border-line bg-surface-subtle/60">
                        <td class="px-4 py-3 font-semibold text-ink">Среднопретеглено</td>
                        @foreach($levels as $level)
                            @php $a = $aggregate[$level] ?? null; @endphp
                            <td class="px-2 py-2 text-center">
                                @if($a)
                                    <span class="inline-block rounded-lg px-3 py-2 {{ $scoreBg($a['score']) }}">
                                        <span class="font-bold">{{ round($a['score']) }}</span>
                                        <span class="block text-[11px] opacity-70">{{ $fmtCost($a['cost']) }} · {{ $a['done'] }}/{{ $a['total'] }}</span>
                                    </span>
                                @else
                                    <span class="text-subtle">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
    <div class="bg-surface-subtle border border-line rounded-lg p-3 text-xs text-muted mb-6 space-y-1">
        <div><b>Как се чете:</b> всяка клетка = <b>Score</b> (качество 0–100, претеглено от критериите) / <b>средна цена</b> на 1 run. Долният ред = среднопретеглено по тестове (с покритие done/total).</div>
        <div><b>Формули:</b> Ефективност = <b>Score ÷ Цена</b> (точки на $) · „+X% цена" = колко по-скъпо е едно ниво спрямо друго.</div>
        <div><b>Цветове:</b> 🟢 ≥85 · 🟡 65–84 · 🔴 &lt;65 · ✗ провал (клик за грешката) · ⏳ тече · — няма. <b>★ конфигурирано</b> = реалното ниво на версията; останалите колони са „какво би било" на друго ниво.</div>
    </div>

    {{-- Scatter (агрегат per ниво) --}}
    @if(!empty($points))
    <div class="bg-surface rounded-xl border border-line p-5">
        <h2 class="font-semibold text-ink mb-1">Цена vs качество (среднопретеглено по ниво)</h2>
        <p class="text-xs text-subtle mb-4">Идеалната точка е горе-вляво (високо качество, ниска цена).</p>
        <div style="height: 360px;"><canvas id="evalScatter"></canvas></div>
    </div>
    @endif
    @endif
</div>

@if(!empty($points))
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const points = @json($points);
    const colors = { low: '#94a3b8', medium: '#6366f1', high: '#0ea5e9', ultra: '#a855f7', god: '#f59e0b' };
    new Chart(document.getElementById('evalScatter'), {
        type: 'scatter',
        data: {
            datasets: points.map(p => ({
                label: p.level.toUpperCase(),
                data: [{ x: p.cost, y: p.score, label: p.level.toUpperCase() }],
                backgroundColor: colors[p.level] || '#64748b',
                pointRadius: 8, pointHoverRadius: 10,
            })),
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                x: { title: { display: true, text: 'Средна цена на run (USD)' }, beginAtZero: true },
                y: { title: { display: true, text: 'Среден eval score' }, min: 0, max: 100 },
            },
            plugins: {
                tooltip: { callbacks: { label: (ctx) => `${ctx.raw.label}: ${ctx.raw.y}/100 · $${ctx.raw.x}` } },
                legend: { position: 'bottom' },
            },
        },
    });
});
</script>
@endpush
@endif
@endsection
