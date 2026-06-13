@extends('layouts.app')

@section('title', 'Eval резултати — ' . $flow->name)

@php
    $cellClass = function ($score) {
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
            <h1 class="text-2xl font-bold text-gray-900">📊 Eval резултати</h1>
        </div>
        <a href="{{ route('flows.eval.index', $flow) }}" class="text-sm text-gray-500 hover:text-indigo-600">← Тестове / пускане</a>
    </div>

    @if(empty($points))
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-5 text-sm">
            Още няма завършени eval резултати. Пусни eval от <a href="{{ route('flows.eval.index', $flow) }}" class="underline">страницата с тестове</a>.
        </div>
    @else

    {{-- Recommendation --}}
    @if($recommendation)
        @php $best = $recommendation['best']; @endphp
        <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-5 mb-6">
            <div class="font-semibold text-indigo-900 mb-1">💡 Препоръка на оптимизатора</div>
            <div class="text-sm text-indigo-800">
                <b>{{ $best['version_name'] }}</b> на ниво <b class="uppercase">{{ $best['level'] }}</b>
                — Score {{ $best['score'] }}/100 · Цена {{ $fmtCost($best['cost']) }}/run
                @if(($best['cost'] ?? 0) > 0)
                    · Ефективност {{ number_format($best['score'] / max($best['cost'], 0.0000001), 0) }} точки/$
                @endif
            </div>
            @foreach($recommendation['comparisons'] as $cmp)
                <div class="text-xs text-indigo-700 mt-1">
                    vs <b>{{ $cmp['label'] }}</b>: +{{ $cmp['delta_score'] }} точки качество@if($cmp['delta_cost_pct'] !== null), но {{ $cmp['delta_cost_pct'] > 0 ? '+' : '' }}{{ $cmp['delta_cost_pct'] }}% цена@endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Matrix --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-gray-500">
                    <th class="text-left px-4 py-3 font-medium">Версия \ Ниво</th>
                    @foreach($levels as $level)
                        <th class="px-4 py-3 font-medium uppercase text-xs">{{ $level }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($versions as $version)
                    <tr class="border-b border-gray-50 last:border-0">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ $version->name }}
                            @if($version->is_active)<span class="text-xs text-green-600">(активна)</span>@endif
                        </td>
                        @foreach($levels as $level)
                            @php $cell = $matrix[$version->id][$level] ?? null; @endphp
                            <td class="px-2 py-2 text-center">
                                @if($cell)
                                    <a href="{{ $cell['run_id'] ? route('flows.eval.run-detail', [$flow, $cell['run_id']]) : '#' }}"
                                       class="inline-block rounded-lg px-3 py-2 {{ $cellClass($cell['score']) }} hover:ring-2 hover:ring-indigo-300 transition">
                                        <span class="font-bold">{{ $cell['score'] }}</span>
                                        <span class="block text-xs opacity-70">{{ $fmtCost($cell['cost']) }}</span>
                                    </a>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-xs text-gray-400 mb-6">Числото = качество (0–100, претеглено по критерии и тестове). Под него = средна цена на един run. 🟢 ≥85 · 🟡 65–84 · 🔴 &lt;65. Клик за детайл.</p>

    {{-- Scatter --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-1">Цена vs качество</h2>
        <p class="text-xs text-gray-400 mb-4">Идеалната точка е горе-вляво (високо качество, ниска цена).</p>
        <div style="height: 380px;"><canvas id="evalScatter"></canvas></div>
    </div>
    @endif
</div>

@if(!empty($points))
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const points = @json($points);
    const colors = { low: '#94a3b8', medium: '#6366f1', high: '#0ea5e9', ultra: '#a855f7', god: '#f59e0b' };
    const byLevel = {};
    points.forEach(p => { (byLevel[p.level] ??= []).push({ x: p.cost, y: p.score, label: p.label }); });

    new Chart(document.getElementById('evalScatter'), {
        type: 'scatter',
        data: {
            datasets: Object.entries(byLevel).map(([level, pts]) => ({
                label: level.toUpperCase(),
                data: pts,
                backgroundColor: colors[level] || '#64748b',
                pointRadius: 7, pointHoverRadius: 9,
            })),
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                x: { title: { display: true, text: 'Цена на run (USD)' }, beginAtZero: true },
                y: { title: { display: true, text: 'Eval score' }, min: 0, max: 100 },
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
