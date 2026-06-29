@extends('admin.layouts.admin')

@section('title', 'Бюджет — ' . $company->name)
@section('container-class', 'max-w-6xl')

@section('content')
@php
    $p = $preview;
    $historyDays = $history['days'];
    $totals = $history['totals'];
    $dayCount = count($historyDays);
@endphp

<div class="mb-4">
    <a href="{{ route('admin.budgets.index') }}" class="text-sm text-muted hover:text-ink inline-flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        Обратно към всички фирми
    </a>
</div>

<div class="flex items-start justify-between mb-6 flex-wrap gap-3">
    <div>
        <h1 class="text-2xl font-bold text-ink">{{ $company->name }}</h1>
        <p class="text-sm text-muted mt-1">{{ $company->industry ?? '—' }} · ID #{{ $company->id }}</p>
    </div>
    <div class="flex gap-2">
        <button type="button" onclick="openBudgetModal({{ $company->id }}, {{ $company->auton_daily_credits }}, {{ $company->auton_daily_percent }})"
                class="bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg text-sm font-semibold transition">Смени тавана</button>
    </div>
</div>

{{-- Current state cards --}}
<div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3 mb-6">
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide">Текущ баланс</p>
        <p class="text-2xl font-bold text-ink mt-1 font-mono">{{ number_format($p['balance']) }}</p>
        <p class="text-xs text-muted mt-1">кредити в портфейла</p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide">Ефективен таван/ден</p>
        <p class="text-2xl font-bold text-ink mt-1 font-mono">{{ $p['effective_cap'] !== null ? number_format($p['effective_cap']) : '∞' }}</p>
        <p class="text-xs mt-1">
            @if($p['source'] === 'company')<span class="text-blue-600 font-medium">per-company</span>@else<span class="text-muted">наследява глобален</span>@endif
        </p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide">Похарчено днес</p>
        @php($pct = $p['effective_cap'] ? min(100, (int) round($p['spent_today'] / $p['effective_cap'] * 100)) : 0)
        <p class="text-2xl font-bold mt-1 font-mono {{ $pct >= 90 ? 'text-coral' : ($pct >= 70 ? 'text-amber-600' : 'text-ink') }}">{{ number_format($p['spent_today']) }}</p>
        <div class="w-full h-1.5 bg-surface-subtle rounded mt-2 overflow-hidden">
            <div class="h-full {{ $pct >= 90 ? 'bg-coral' : ($pct >= 70 ? 'bg-amber-400' : 'bg-emerald-400') }}" style="width: {{ $pct }}%"></div>
        </div>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide">Override кредити</p>
        <p class="text-2xl font-bold text-ink mt-1 font-mono">
            @if($company->auton_daily_credits === -1)<span class="text-muted text-base">наследява</span>@elseif($company->auton_daily_credits === 0)∞@else{{ number_format($company->auton_daily_credits) }}@endif
        </p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide">Override %</p>
        <p class="text-2xl font-bold text-ink mt-1 font-mono">
            @if($company->auton_daily_percent === -1)<span class="text-muted text-base">наследява</span>@elseif($company->auton_daily_percent === 0)0%@else{{ $company->auton_daily_percent }}%@endif
        </p>
    </div>
</div>

{{-- Period summary + selector --}}
<div class="bg-surface border border-line rounded-xl p-4 mb-4 flex items-center justify-between flex-wrap gap-3">
    <div class="flex items-center gap-4 text-sm">
        <div><span class="text-muted">Период:</span> <span class="font-semibold text-ink">{{ $dayCount }} дни</span></div>
        <div class="text-muted">·</div>
        <div><span class="text-muted">Автономно похарчено:</span> <span class="font-semibold text-ink font-mono">{{ number_format($totals['autonomous_spent']) }}</span> кредита</div>
        <div class="text-muted">·</div>
        <div><span class="text-muted">Общ дебит:</span> <span class="font-mono text-coral">−{{ number_format($totals['total_debit']) }}</span></div>
        <div><span class="text-muted">Общ кредит:</span> <span class="font-mono text-emerald-600">+{{ number_format($totals['total_credit']) }}</span></div>
        <div class="text-muted">·</div>
        <div><span class="text-muted">Нетна промяна:</span> <span class="font-mono font-semibold {{ ($totals['total_credit'] - $totals['total_debit']) >= 0 ? 'text-emerald-600' : 'text-coral' }}">{{ ($totals['total_credit'] - $totals['total_debit']) >= 0 ? '+' : '' }}{{ number_format($totals['total_credit'] - $totals['total_debit']) }}</span></div>
    </div>
    <form method="GET" class="flex items-center gap-2 text-sm">
        <label class="text-muted">Покажи:</label>
        <select name="days" onchange="this.form.submit()" class="border border-line rounded-lg px-2 py-1.5 text-sm">
            @foreach([7, 14, 30, 60, 90, 120] as $d)
                <option value="{{ $d }}" @selected((int) request('days', 30) === $d)>{{ $d }} дни</option>
            @endforeach
        </select>
    </form>
</div>

{{-- Daily history chart --}}
<div class="bg-surface border border-line rounded-xl p-4 mb-4">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-ink">Автономен разход по дни (кредити)</h3>
        <div class="flex items-center gap-3 text-xs">
            <span class="inline-flex items-center gap-1"><span class="w-3 h-3 bg-blue-500 rounded-sm"></span> автономно похарчено</span>
            <span class="inline-flex items-center gap-1"><span class="w-3 h-3 bg-emerald-400 rounded-sm"></span> + кредит (topup/grant/refund)</span>
            <span class="inline-flex items-center gap-1"><span class="w-3 h-3 bg-coral rounded-sm"></span> − дебит (всички)</span>
        </div>
    </div>
    <div class="relative h-64"><canvas id="chartHistory"></canvas></div>
</div>

{{-- Daily history table --}}
<div class="bg-surface border border-line rounded-xl overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-muted">
            <tr>
                <th class="text-left px-4 py-3 font-medium">Дата</th>
                <th class="text-right px-4 py-3 font-medium">Автономно</th>
                <th class="text-left px-4 py-3 font-medium">Разбивка по контекст</th>
                <th class="text-right px-4 py-3 font-medium">Дебит (всички)</th>
                <th class="text-right px-4 py-3 font-medium">Кредит (всички)</th>
                <th class="text-right px-4 py-3 font-medium">Нетна промяна</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @foreach(array_reverse($historyDays) as $d)
                @php($change = $d['balance_change'])
                <tr class="hover:bg-surface-subtle/50 {{ $d['autonomous_spent'] > 0 ? '' : 'opacity-60' }}">
                    <td class="px-4 py-3 font-mono">{{ $d['date'] }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold {{ $d['autonomous_spent'] > 0 ? 'text-blue-600' : 'text-muted' }}">{{ number_format($d['autonomous_spent']) }}</td>
                    <td class="px-4 py-3 text-xs">
                        @if(empty($d['by_context']))
                            <span class="text-muted">—</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach($d['by_context'] as $ctx => $amt)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-surface-subtle border border-line">
                                        <span class="text-muted">{{ $ctx }}</span>
                                        <span class="ml-1 font-mono font-semibold">{{ number_format($amt) }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right font-mono {{ $d['total_debit'] > 0 ? 'text-coral' : 'text-muted' }}">−{{ number_format($d['total_debit']) }}</td>
                    <td class="px-4 py-3 text-right font-mono {{ $d['total_credit'] > 0 ? 'text-emerald-600' : 'text-muted' }}">+{{ number_format($d['total_credit']) }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold {{ $change >= 0 ? 'text-emerald-600' : 'text-coral' }}">{{ $change >= 0 ? '+' : '' }}{{ number_format($change) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<p class="text-xs text-muted mt-3 leading-relaxed">
    <strong>Автономно</strong> = разход от origin=autonomous (директорски ticks, ревюта, scheduled, ignition). <strong>Дебит/Кредит (всички)</strong> = целият балансов поток за деня — включва ръчни runs, topup, grant, refund.
    Ръчните харчения никога не се таванират; само автономните спират при достигнат таван.
</p>

{{-- Chart.js (inline — само за тази страница) --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const days = @json(array_map(fn($d) => $d['date'], $historyDays));
const autoSpent = @json(array_map(fn($d) => $d['autonomous_spent'], $historyDays));
const debit = @json(array_map(fn($d) => $d['total_debit'], $historyDays));
const credit = @json(array_map(fn($d) => $d['total_credit'], $historyDays));

new Chart(document.getElementById('chartHistory'), {
    type: 'bar',
    data: {
        labels: days,
        datasets: [
            { label: 'Автономно похарчено', data: autoSpent, backgroundColor: 'rgb(59, 130, 246)', borderRadius: 3, order: 2 },
            { label: 'Кредит (+)', data: credit.map(v => v > 0 ? v : null), backgroundColor: 'rgb(52, 211, 153)', borderRadius: 3, order: 3, yAxisID: 'y1' },
            { label: 'Дебит (−)', data: debit.map(v => v > 0 ? -v : null), backgroundColor: 'rgb(248, 113, 113)', borderRadius: 3, order: 4, yAxisID: 'y1' },
        ],
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 14 } },
            y: { beginAtZero: true, title: { display: true, text: 'Автономни кредити', font: { size: 11 } } },
            y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Балансов поток', font: { size: 11 } } },
        },
    },
});
</script>

{{-- Edit modal (copy from index) --}}
<div id="budgetModal" class="fixed inset-0 bg-ink/40 z-50 hidden items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-line shadow-xl max-w-md w-full p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-ink">Автономен таван — {{ $company->name }}</h3>
            <button type="button" onclick="closeBudgetModal()" class="text-muted hover:text-ink">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <p class="text-xs text-muted mb-4"><strong>-1 = наследява глобалния</strong>, <strong>0 = без таван</strong>, <strong>&gt;0 = конкретен лимит</strong>.</p>
        <form id="budgetForm" class="space-y-4" data-company-id="{{ $company->id }}">
            @csrf
            <div>
                <label class="block text-sm font-medium text-ink mb-1">Кредити / ден</label>
                <input type="number" id="bm-credits" min="-1" step="1" value="{{ $company->auton_daily_credits }}" class="w-full text-sm border border-line rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-ink mb-1">% от баланса / ден</label>
                <input type="number" id="bm-percent" min="-1" max="100" step="1" value="{{ $company->auton_daily_percent }}" class="w-full text-sm border border-line rounded-lg px-3 py-2">
            </div>
            <div id="bm-error" class="text-xs text-coral hidden"></div>
            <div class="flex gap-2 pt-2">
                <button type="submit" class="flex-1 bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg text-sm font-semibold transition">Запази</button>
                <button type="button" onclick="closeBudgetModal()" class="px-4 py-2 rounded-lg text-sm border border-line text-muted hover:bg-surface-subtle transition">Отказ</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBudgetModal(id, credits, percent) {
    document.getElementById('bm-credits').value = credits;
    document.getElementById('bm-percent').value = percent;
    document.getElementById('bm-error').classList.add('hidden');
    document.getElementById('budgetModal').classList.remove('hidden');
    document.getElementById('budgetModal').classList.add('flex');
}
function closeBudgetModal() {
    document.getElementById('budgetModal').classList.add('hidden');
    document.getElementById('budgetModal').classList.remove('flex');
}
document.getElementById('budgetForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = e.target.dataset.companyId;
    const err = document.getElementById('bm-error');
    err.classList.add('hidden');
    const res = await fetch(`{{ route('admin.budgets.update', '__ID__') }}`.replace('__ID__', id), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
        body: JSON.stringify({ auton_daily_credits: parseInt(document.getElementById('bm-credits').value), auton_daily_percent: parseInt(document.getElementById('bm-percent').value) }),
    });
    const data = await res.json();
    if (res.ok && data.ok) { location.reload(); }
    else {
        err.textContent = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : 'Грешка при запис');
        err.classList.remove('hidden');
    }
});
document.getElementById('budgetModal').addEventListener('click', (e) => { if (e.target.id === 'budgetModal') closeBudgetModal(); });
</script>
@endsection
