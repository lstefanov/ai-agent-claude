@extends('layouts.app')

@section('title', 'Eval резултати — ' . $flow->name)

@php
    $scoreBg = function ($score) {
        if ($score === null) return 'text-gray-300';
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
            <div class="text-sm text-gray-400 mb-1">
                <a href="{{ route('companies.show', $flow->company) }}" class="hover:text-indigo-600">{{ $flow->company->name }}</a>
                <span class="mx-1">/</span>
                <a href="{{ route('flows.show', $flow) }}" class="hover:text-indigo-600">{{ $flow->name }}</a>
                <span class="mx-1">/</span>
                <a href="{{ route('flows.eval.index', $flow) }}" class="hover:text-indigo-600">Eval</a>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">📊 Eval резултати — тестове × нива</h1>
        </div>
        <div class="flex items-center gap-3">
            @if($versions->count() > 1)
                <form method="GET" class="flex items-center gap-2 text-sm">
                    <label class="text-gray-500">Версия:</label>
                    <select name="version" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        @foreach($versions as $v)
                            <option value="{{ $v->id }}" @selected($version && $v->id === $version->id)>{{ $v->name }}@if($v->is_active) (активна)@endif</option>
                        @endforeach
                    </select>
                </form>
            @endif
            <a href="{{ route('flows.eval.index', $flow) }}" class="text-sm text-gray-500 hover:text-indigo-600">← Тестове / пускане</a>
        </div>
    </div>

    @if(empty($points) && collect($matrix)->flatten(1)->filter()->isEmpty())
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-5 text-sm">
            Още няма eval резултати за <b>{{ $version->name ?? 'тази версия' }}</b>. Пусни eval от <a href="{{ route('flows.eval.index', $flow) }}" class="underline">страницата с тестове</a>.
        </div>
    @else

    {{-- Recommendation (по ниво) --}}
    @if($recommendation)
        @php $best = $recommendation['best']; @endphp
        <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-5 mb-6">
            <div class="font-semibold text-indigo-900 mb-1">💡 Препоръка на оптимизатора (за {{ $version->name ?? '' }})</div>
            <div class="text-sm text-indigo-800">
                Ниво <b class="uppercase">{{ $best['level'] }}</b> — среден Score {{ $best['score'] }}/100 · Цена {{ $fmtCost($best['cost']) }}/run
                @if(($best['cost'] ?? 0) > 0)
                    · Ефективност {{ number_format($best['score'] / max($best['cost'], 0.0000001), 0) }} точки/$
                @endif
            </div>
            @foreach($recommendation['comparisons'] as $cmp)
                <div class="text-xs text-indigo-700 mt-1">
                    vs <b class="uppercase">{{ $cmp['label'] }}</b>: +{{ $cmp['delta_score'] }} точки качество@if($cmp['delta_cost_pct'] !== null), но {{ $cmp['delta_cost_pct'] > 0 ? '+' : '' }}{{ $cmp['delta_cost_pct'] }}% цена@endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Matrix: тестове (редове) × нива (колони) --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto mb-3">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-gray-500">
                    <th class="text-left px-4 py-3 font-medium">Тест \ Ниво</th>
                    @foreach($levels as $level)
                        <th class="px-4 py-3 font-medium uppercase text-xs">{{ $level }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($cases as $case)
                    <tr class="border-b border-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-800">{{ $case->name }}</div>
                            <div class="text-xs text-gray-400">тежест {{ rtrim(rtrim(number_format($case->weight, 1), '0'), '.') }}@unless($case->is_active) · неактивен @endunless</div>
                        </td>
                        @foreach($levels as $level)
                            @php $cell = $matrix[$case->id][$level] ?? null; @endphp
                            <td class="px-2 py-2 text-center">
                                @if(!$cell)
                                    <span class="text-gray-300">—</span>
                                @elseif($cell['status'] === 'completed')
                                    <a href="{{ route('flows.eval.run-detail', [$flow, $cell['run_id']]) }}"
                                       class="inline-block rounded-lg px-3 py-2 {{ $scoreBg($cell['score']) }} hover:ring-2 hover:ring-indigo-300 transition">
                                        <span class="font-bold">{{ round($cell['score']) }}</span>
                                        <span class="block text-xs opacity-70">{{ $fmtCost($cell['cost']) }}</span>
                                    </a>
                                @elseif($cell['status'] === 'failed')
                                    <a href="{{ route('flows.eval.run-detail', [$flow, $cell['run_id']]) }}"
                                       class="inline-block rounded-lg px-3 py-2 bg-red-50 text-red-600 hover:ring-2 hover:ring-red-300 transition"
                                       title="{{ $cell['error'] }}">✗</a>
                                @else
                                    <span class="text-gray-400 animate-pulse" title="{{ $cell['status'] }}">⏳</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                {{-- Aggregate row --}}
                @if(!empty($aggregate))
                    <tr class="border-t-2 border-gray-200 bg-gray-50/60">
                        <td class="px-4 py-3 font-semibold text-gray-700">Среднопретеглено</td>
                        @foreach($levels as $level)
                            @php $a = $aggregate[$level] ?? null; @endphp
                            <td class="px-2 py-2 text-center">
                                @if($a)
                                    <span class="inline-block rounded-lg px-3 py-2 {{ $scoreBg($a['score']) }}">
                                        <span class="font-bold">{{ round($a['score']) }}</span>
                                        <span class="block text-[11px] opacity-70">{{ $fmtCost($a['cost']) }} · {{ $a['done'] }}/{{ $a['total'] }}</span>
                                    </span>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
    <p class="text-xs text-gray-400 mb-6">
        Всяка клетка = качество (0–100) / средна цена на run за този тест на това ниво. Долният ред е среднопретегленото по тестове (с покритие done/total).
        🟢 ≥85 · 🟡 65–84 · 🔴 &lt;65 · ✗ провал (клик за грешката) · ⏳ тече · — няма. Клик за детайл.
    </p>

    {{-- Scatter (агрегат per ниво) --}}
    @if(!empty($points))
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-1">Цена vs качество (среднопретеглено по ниво)</h2>
        <p class="text-xs text-gray-400 mb-4">Идеалната точка е горе-вляво (високо качество, ниска цена).</p>
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
