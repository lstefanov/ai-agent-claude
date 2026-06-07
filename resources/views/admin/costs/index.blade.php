@extends('admin.layouts.admin')

@section('title', 'Разходи')

@section('content')
@php
    $fmtUsd = fn ($v) => '$' . number_format((float) $v, 4);
    $fmtUsd6 = fn ($v) => '$' . number_format((float) $v, 6);
    $fmtInt = fn ($v) => number_format((int) $v);
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">💰 Разходи</h1>
        <p class="text-sm text-gray-500 mt-1">Детайлен одит на платените заявки към OpenAI и Anthropic — токени, цена, промпт и отговор за всяка заявка.</p>
    </div>
</div>

{{-- ── Summary cards ─────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Общо разход</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtUsd($summary['total_cost']) }}</p>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Този месец</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtUsd($summary['month_cost']) }}</p>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Днес</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtUsd($summary['today_cost']) }}</p>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Средно / заявка</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtUsd6($summary['avg_cost']) }}</p>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Заявки</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtInt($summary['total_requests']) }}</p>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Общо токени</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtInt($summary['total_tokens']) }}</p>
    </div>
    <div class="bg-white border border-emerald-200 rounded-xl p-4">
        <p class="text-xs text-emerald-600 uppercase tracking-wide">OpenAI</p>
        <p class="text-2xl font-bold text-emerald-700 mt-1">{{ $fmtUsd($summary['openai_cost']) }}</p>
    </div>
    <div class="bg-white border border-violet-200 rounded-xl p-4">
        <p class="text-xs text-violet-600 uppercase tracking-wide">Anthropic</p>
        <p class="text-2xl font-bold text-violet-700 mt-1">{{ $fmtUsd($summary['anthropic_cost']) }}</p>
    </div>
</div>

{{-- ── Charts ────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по дни (USD)</h3>
        <div class="relative h-56"><canvas id="chartSpendDay"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Токени по дни</h3>
        <div class="relative h-56"><canvas id="chartTokensDay"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по провайдър</h3>
        <div class="relative h-56"><canvas id="chartProvider"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Заявки по тип</h3>
        <div class="relative h-56"><canvas id="chartKind"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по модел (топ 8)</h3>
        <div class="relative h-56"><canvas id="chartModel"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по фирма (топ 8)</h3>
        <div class="relative h-56"><canvas id="chartCompany"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4 lg:col-span-2">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Обем по провайдър — платено срещу безплатно (Ollama)</h3>
        <div class="relative h-56"><canvas id="chartVolume"></canvas></div>
    </div>
</div>

{{-- ── Filter bar ────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('admin.costs.index') }}"
      class="bg-white border border-gray-200 rounded-xl p-4 mb-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Провайдър</label>
        <select name="provider" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['providers'] as $p)
                <option value="{{ $p }}" @selected(($filters['provider'] ?? '') === $p)>{{ $p }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Модел</label>
        <select name="model" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['models'] as $m)
                <option value="{{ $m }}" @selected(($filters['model'] ?? '') === $m)>{{ $m }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Фирма</label>
        <select name="company_id" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['companies'] as $c)
                <option value="{{ $c->id }}" @selected((string)($filters['company_id'] ?? '') === (string)$c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Flow</label>
        <select name="flow_id" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['flows'] as $f)
                <option value="{{ $f->id }}" @selected((string)($filters['flow_id'] ?? '') === (string)$f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Тип</label>
        <select name="kind" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['kinds'] as $k)
                <option value="{{ $k }}" @selected(($filters['kind'] ?? '') === $k)>{{ $k }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Статус</label>
        <select name="status" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            <option value="completed" @selected(($filters['status'] ?? '') === 'completed')>completed</option>
            <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>failed</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">От</label>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">До</label>
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
    </div>
    <div class="col-span-2 md:col-span-4 lg:col-span-8 flex gap-2">
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">Филтрирай</button>
        @if($hasFilters)
            <a href="{{ route('admin.costs.index') }}" class="px-4 py-2 rounded-lg text-sm border border-gray-300 text-gray-600 hover:bg-gray-50 transition">Изчисти</a>
        @endif
    </div>
</form>

{{-- ── Detailed table ────────────────────────────────────────────────── --}}
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-3 py-2 font-medium">Време</th>
                    <th class="text-left px-3 py-2 font-medium">Провайдър</th>
                    <th class="text-left px-3 py-2 font-medium">Модел</th>
                    <th class="text-left px-3 py-2 font-medium">Цел</th>
                    <th class="text-left px-3 py-2 font-medium">Фирма</th>
                    <th class="text-left px-3 py-2 font-medium">Flow</th>
                    <th class="text-left px-3 py-2 font-medium">Агент</th>
                    <th class="text-right px-3 py-2 font-medium">Tokens (in/out)</th>
                    <th class="text-right px-3 py-2 font-medium">Цена</th>
                    <th class="text-right px-3 py-2 font-medium">ms</th>
                    <th class="text-left px-3 py-2 font-medium">Статус</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($requests as $req)
                    <tr class="hover:bg-indigo-50/50 cursor-pointer" data-cost-row data-source="{{ $req['source'] }}" data-id="{{ $req['id'] }}">
                        <td class="px-3 py-2 whitespace-nowrap text-gray-500">{{ $req['created_at'] }}</td>
                        <td class="px-3 py-2">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                {{ ['openai' => 'bg-emerald-100 text-emerald-700', 'anthropic' => 'bg-violet-100 text-violet-700'][$req['provider']] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $req['provider'] }}
                            </span>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $req['model'] }}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-gray-500">
                            {{ $req['purpose'] }}
                            <span class="text-gray-300">·</span>
                            <span class="text-gray-400">{{ $req['kind'] }}</span>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $req['company'] ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $req['flow'] ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">
                            {{ $req['agent_name'] ?? '—' }}
                            @if($req['agent_type'])<span class="text-gray-400 text-xs">({{ $req['agent_type'] }})</span>@endif
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap text-right text-gray-600 tabular-nums">
                            {{ $req['prompt_tokens'] !== null ? $fmtInt($req['prompt_tokens']) : '—' }} / {{ $req['completion_tokens'] !== null ? $fmtInt($req['completion_tokens']) : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap text-right font-semibold text-gray-900 tabular-nums">{{ $fmtUsd6($req['cost_usd']) }}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-right text-gray-500 tabular-nums">{{ $fmtInt($req['duration_ms'] ?? 0) }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $req['status'] === 'completed' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $req['status'] }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-3 py-12 text-center text-gray-400">Няма записани заявки за избраните филтри.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    {{ $requests->links() }}
</div>

{{-- ── Detail popup ──────────────────────────────────────────────────── --}}
<div id="costModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close></div>
    <div class="absolute inset-0 flex items-start justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl my-8">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                <h3 class="font-semibold text-gray-900">Заявка <span id="m-id" class="text-gray-400"></span></h3>
                <button data-modal-close class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-2 text-sm">
                    <div><span class="text-gray-400">Време:</span> <span id="m-time" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Провайдър:</span> <span id="m-provider" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Модел:</span> <span id="m-model" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Цел / тип:</span> <span id="m-purpose" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Фирма:</span> <span id="m-company" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Flow:</span> <span id="m-flow" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Агент:</span> <span id="m-agent" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Run / Node:</span> <span id="m-run" class="text-gray-800"></span></div>
                    <div><span class="text-gray-400">Статус:</span> <span id="m-status" class="text-gray-800"></span></div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-400">Prompt tokens</p><p id="m-ptok" class="font-semibold text-gray-900"></p></div>
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-400">Completion tokens</p><p id="m-ctok" class="font-semibold text-gray-900"></p></div>
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-400">Цена (USD)</p><p id="m-cost" class="font-semibold text-gray-900"></p></div>
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-400">Времетраене</p><p id="m-dur" class="font-semibold text-gray-900"></p></div>
                </div>
                <div id="m-error-wrap" class="hidden">
                    <p class="text-xs font-semibold text-red-600 uppercase tracking-wide mb-1">Грешка</p>
                    <pre id="m-error" class="text-xs bg-red-50 text-red-700 rounded-lg p-3 whitespace-pre-wrap break-words"></pre>
                </div>
                <div id="m-options-wrap" class="hidden">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Опции</p>
                    <pre id="m-options" class="text-xs bg-gray-50 text-gray-700 rounded-lg p-3 whitespace-pre-wrap break-words max-h-32 overflow-auto"></pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">System prompt</p>
                    <pre id="m-system" class="text-xs bg-gray-50 text-gray-800 rounded-lg p-3 whitespace-pre-wrap break-words max-h-56 overflow-auto"></pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Входящ промпт (user)</p>
                    <pre id="m-user" class="text-xs bg-gray-50 text-gray-800 rounded-lg p-3 whitespace-pre-wrap break-words max-h-56 overflow-auto"></pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-indigo-600 uppercase tracking-wide mb-1">Изход на модела</p>
                    <pre id="m-response" class="text-xs bg-indigo-50/60 text-gray-800 rounded-lg p-3 whitespace-pre-wrap break-words max-h-72 overflow-auto"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
const CHARTS = @json($charts);
const COST_SHOW_URL = "{{ url('admin/costs/detail') }}";

// ── Charts ────────────────────────────────────────────────────────────
const C = { emerald: '#10b981', violet: '#8b5cf6', indigo: '#6366f1', amber: '#f59e0b',
            sky: '#0ea5e9', rose: '#f43f5e', gray: '#9ca3af', teal: '#14b8a6' };
const PALETTE = [C.indigo, C.emerald, C.violet, C.amber, C.sky, C.rose, C.teal, C.gray];

function providerColors(labels) {
    return labels.map(l => l === 'openai' ? C.emerald : l === 'anthropic' ? C.violet : C.gray);
}

new Chart(document.getElementById('chartSpendDay'), {
    type: 'line',
    data: { labels: CHARTS.spendByDay.labels, datasets: [{
        label: 'USD', data: CHARTS.spendByDay.cost, borderColor: C.indigo,
        backgroundColor: 'rgba(99,102,241,0.15)', fill: true, tension: 0.3, pointRadius: 2 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v } } } }
});

new Chart(document.getElementById('chartTokensDay'), {
    type: 'bar',
    data: { labels: CHARTS.spendByDay.labels, datasets: [
        { label: 'Prompt', data: CHARTS.spendByDay.promptTokens, backgroundColor: C.sky },
        { label: 'Completion', data: CHARTS.spendByDay.completionTokens, backgroundColor: C.indigo } ] },
    options: { responsive: true, maintainAspectRatio: false,
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
});

new Chart(document.getElementById('chartProvider'), {
    type: 'doughnut',
    data: { labels: CHARTS.spendByProvider.labels, datasets: [{
        data: CHARTS.spendByProvider.data, backgroundColor: providerColors(CHARTS.spendByProvider.labels) }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
});

new Chart(document.getElementById('chartKind'), {
    type: 'doughnut',
    data: { labels: CHARTS.requestsByKind.labels, datasets: [{
        data: CHARTS.requestsByKind.data, backgroundColor: PALETTE }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
});

new Chart(document.getElementById('chartModel'), {
    type: 'bar',
    data: { labels: CHARTS.spendByModel.labels, datasets: [{
        label: 'USD', data: CHARTS.spendByModel.data, backgroundColor: C.violet }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { callback: v => '$' + v } } } }
});

new Chart(document.getElementById('chartCompany'), {
    type: 'bar',
    data: { labels: CHARTS.spendByCompany.labels, datasets: [{
        label: 'USD', data: CHARTS.spendByCompany.data, backgroundColor: C.emerald }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { callback: v => '$' + v } } } }
});

new Chart(document.getElementById('chartVolume'), {
    type: 'bar',
    data: { labels: CHARTS.volumeByProvider.labels, datasets: [
        { label: 'Заявки', data: CHARTS.volumeByProvider.requests, backgroundColor: C.indigo, yAxisID: 'y' },
        { label: 'Токени', data: CHARTS.volumeByProvider.tokens, backgroundColor: C.amber, yAxisID: 'y1' } ] },
    options: { responsive: true, maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Заявки' } },
                  y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Токени' } } } }
});

// ── Detail popup ──────────────────────────────────────────────────────
const modal = document.getElementById('costModal');
const fmtUsd = v => '$' + Number(v || 0).toFixed(6);
const fmtInt = v => Number(v || 0).toLocaleString();
const setText = (id, val) => { document.getElementById(id).textContent = (val ?? '') === '' ? '—' : val; };

async function openCost(source, id) {
    try {
        const resp = await fetch(`${COST_SHOW_URL}?source=${encodeURIComponent(source)}&id=${encodeURIComponent(id)}`, { headers: { 'Accept': 'application/json' } });
        if (!resp.ok) { alert('Грешка при зареждане на заявката.'); return; }
        const d = await resp.json();

        setText('m-id', '#' + d.id);
        setText('m-time', d.created_at);
        setText('m-provider', d.provider);
        setText('m-model', d.model);
        setText('m-purpose', [d.purpose, d.kind].filter(Boolean).join(' · '));
        setText('m-company', d.company);
        setText('m-flow', d.flow);
        setText('m-agent', [d.agent_name, d.agent_type ? '(' + d.agent_type + ')' : ''].filter(Boolean).join(' '));
        setText('m-run', [d.flow_run_id ? 'run ' + d.flow_run_id : '', d.node_key ? '· ' + d.node_key : ''].filter(Boolean).join(' '));
        setText('m-status', d.status);
        setText('m-ptok', d.prompt_tokens != null ? fmtInt(d.prompt_tokens) : '—');
        setText('m-ctok', d.completion_tokens != null ? fmtInt(d.completion_tokens) : '—');
        setText('m-cost', fmtUsd(d.cost_usd));
        setText('m-dur', d.duration_ms != null ? fmtInt(d.duration_ms) + ' ms' : '—');

        const errWrap = document.getElementById('m-error-wrap');
        if (d.error) { document.getElementById('m-error').textContent = d.error; errWrap.classList.remove('hidden'); }
        else { errWrap.classList.add('hidden'); }

        const optWrap = document.getElementById('m-options-wrap');
        if (d.options && Object.keys(d.options).length) {
            document.getElementById('m-options').textContent = JSON.stringify(d.options, null, 2);
            optWrap.classList.remove('hidden');
        } else { optWrap.classList.add('hidden'); }

        setText('m-system', d.system_prompt);
        setText('m-user', d.user_message);
        setText('m-response', d.response_text);

        modal.classList.remove('hidden');
    } catch (e) {
        console.error(e);
        alert('Мрежова грешка.');
    }
}

document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-cost-row]');
    if (row) { openCost(row.dataset.source, row.dataset.id); return; }
    if (e.target.closest('[data-modal-close]')) { modal.classList.add('hidden'); }
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') modal.classList.add('hidden'); });
</script>
@endsection
