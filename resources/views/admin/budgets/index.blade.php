@extends('admin.layouts.admin')

@section('title', 'Бюджети')
@section('container-class', 'max-w-7xl')

@section('content')
<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <div>
        <h1 class="text-2xl font-bold text-ink">Бюджети по фирма</h1>
        <p class="text-sm text-muted mt-1">Дневен автономен таван за всяка компания + пълна дневна история на харчените кредити.</p>
    </div>
    <div class="bg-surface border border-line rounded-xl px-4 py-3 text-xs text-muted">
        <span class="font-semibold text-ink">Глобален cap:</span>
        {{ $globalCredits > 0 ? "{$globalCredits} кредита" : 'изключен' }}
        ·
        {{ $globalPercent > 0 ? "{$globalPercent}% от баланса" : '0% от баланса' }}
    </div>
</div>

{{-- Search bar --}}
<form method="GET" class="bg-surface border border-line rounded-xl p-3 mb-4 flex gap-2 items-center">
    <input type="text" name="search" value="{{ $search }}" placeholder="Търси фирма по име…"
           class="flex-1 text-sm border border-line rounded-lg px-3 py-2">
    <button type="submit" class="bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg text-sm font-semibold transition">Търси</button>
    @if($search)<a href="{{ route('admin.budgets.index') }}" class="px-4 py-2 rounded-lg text-sm border border-line text-muted hover:bg-surface-subtle transition">Изчисти</a>@endif
</form>

{{-- Companies table --}}
<div class="bg-surface border border-line rounded-xl overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-muted">
            <tr>
                <th class="text-left px-4 py-3 font-medium">Фирма</th>
                <th class="text-right px-4 py-3 font-medium">Баланс</th>
                <th class="text-right px-4 py-3 font-medium">Директори</th>
                <th class="text-right px-4 py-3 font-medium">Чакащи предл.</th>
                <th class="text-left px-4 py-3 font-medium">Автономен таван / ден</th>
                <th class="text-right px-4 py-3 font-medium">Похарчено днес</th>
                <th class="text-right px-4 py-3 font-medium">Статус</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse($companies as $c)
                @php($p = $c->_budget_preview)
                <tr class="hover:bg-surface-subtle/50">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-ink">{{ $c->name }}</div>
                        <div class="text-xs text-muted">{{ $c->industry ?? '—' }}</div>
                    </td>
                    <td class="px-4 py-3 text-right font-mono">{{ number_format($c->creditWallet?->balance ?? 0) }}</td>
                    <td class="px-4 py-3 text-right">{{ $c->_directors ?? 0 }}</td>
                    <td class="px-4 py-3 text-right">
                        @if(($c->_pending_proposals ?? 0) > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-50 text-amber-700 border border-amber-200">{{ $c->_pending_proposals }}</span>
                        @else
                            <span class="text-muted">0</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs">
                        @if($p['source'] === 'company')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200 font-medium">per-company</span>
                        @else
                            <span class="text-muted">наследява глобален</span>
                        @endif
                        <div class="text-muted mt-0.5">
                            @if($p['credits'] !== null){{ number_format($p['credits']) }} кредита @endif
                            @if($p['percent'] !== null){{ ', ' }}{{ $p['percent'] }}% @endif
                            @if($p['credits'] === null && $p['percent'] === null)<span class="text-emerald-600 font-medium">без таван</span>@endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right font-mono">
                        @php($pct = $p['effective_cap'] ? min(100, (int) round($p['spent_today'] / $p['effective_cap'] * 100)) : 0)
                        <div class="font-semibold {{ $pct >= 90 ? 'text-coral' : ($pct >= 70 ? 'text-amber-600' : 'text-ink') }}">{{ number_format($p['spent_today']) }}</div>
                        @if($p['effective_cap'])
                            <div class="w-24 h-1 bg-surface-subtle rounded mt-1 ml-auto overflow-hidden">
                                <div class="h-full {{ $pct >= 90 ? 'bg-coral' : ($pct >= 70 ? 'bg-amber-400' : 'bg-emerald-400') }}" style="width: {{ $pct }}%"></div>
                            </div>
                            <div class="text-xs text-muted mt-0.5">/ {{ number_format($p['effective_cap']) }}</div>
                        @else
                            <div class="text-xs text-muted">—</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($p['effective_cap'] === null)
                            <span class="text-xs text-emerald-600">отворена</span>
                        @elseif($p['spent_today'] >= $p['effective_cap'])
                            <span class="text-xs text-coral font-semibold">блокирана</span>
                        @else
                            <span class="text-xs text-emerald-600">активна</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('admin.budgets.show', $c) }}" class="text-primary hover:underline text-xs font-medium mr-3">Детайл</a>
                        <button type="button" onclick="openBudgetModal({{ $c->id }}, {{ $c->auton_daily_credits }}, {{ $c->auton_daily_percent }})" class="text-primary hover:underline text-xs font-medium">Смени</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-10 text-center text-muted">Няма намерени фирми.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $companies->withQueryString()->links() }}</div>

{{-- Edit modal --}}
<div id="budgetModal" class="fixed inset-0 bg-ink/40 z-50 hidden items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-line shadow-xl max-w-md w-full p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-ink">Автономен таван</h3>
            <button type="button" onclick="closeBudgetModal()" class="text-muted hover:text-ink">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <p class="text-xs text-muted mb-4">Задайте стойност за тази фирма. <strong>-1 = наследява глобалния</strong> ({{ $globalCredits > 0 ? "{$globalCredits} кредити" : 'изключен' }} / {{ $globalPercent }}%), <strong>0 = без таван</strong> (отворена), <strong>&gt;0 = конкретен лимит</strong>.</p>
        <form id="budgetForm" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-ink mb-1">Кредити / ден</label>
                <input type="number" id="bm-credits" min="-1" step="1" class="w-full text-sm border border-line rounded-lg px-3 py-2">
                <p class="text-xs text-muted mt-1">Абсолютен брой кредити на ден.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-ink mb-1">% от баланса / ден</label>
                <input type="number" id="bm-percent" min="-1" max="100" step="1" class="w-full text-sm border border-line rounded-lg px-3 py-2">
                <p class="text-xs text-muted mt-1">Допълнителен таван като % от текущия баланс (взема се по-малкият).</p>
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
    document.getElementById('budgetForm').dataset.companyId = id;
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
    if (res.ok && data.ok) { closeBudgetModal(); location.reload(); }
    else {
        err.textContent = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : 'Грешка при запис');
        err.classList.remove('hidden');
    }
});
document.getElementById('budgetModal').addEventListener('click', (e) => { if (e.target.id === 'budgetModal') closeBudgetModal(); });
</script>
@endsection
