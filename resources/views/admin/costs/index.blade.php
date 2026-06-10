@extends('admin.layouts.admin')

@section('title', 'Разходи')
@section('container-class', 'max-w-screen-2xl')

@section('content')
@php
    $fmtUsd = fn ($v) => '$' . number_format((float) $v, 2);
    $fmtInt = fn ($v) => number_format((int) $v);
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">💰 Разходи</h1>
        <p class="text-sm text-gray-500 mt-1">Пълен одит на всяка LLM заявка — токени, цена, промпт и отговор. Кликни ред за детайли.</p>
    </div>
</div>

{{-- ── Summary cards ─────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-3 mb-8">
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
        <p class="text-xs text-gray-500 uppercase tracking-wide">Средно / платена заявка</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtUsd($summary['avg_cost']) }}</p>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Заявки</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtInt($summary['total_requests']) }}</p>
        <p class="text-xs text-gray-500 mt-1">
            <span class="text-emerald-600 font-medium">{{ $fmtInt($summary['paid_requests']) }}</span> платени ·
            <span class="text-gray-600 font-medium">{{ $fmtInt($summary['ollama_requests']) }}</span> безпл.
        </p>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-500 uppercase tracking-wide">Токени</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $fmtInt($summary['paid_tokens'] + $summary['free_tokens']) }}</p>
        <p class="text-xs text-gray-500 mt-1">
            <span class="text-emerald-600 font-medium">{{ $fmtInt($summary['paid_tokens']) }}</span> платени ·
            <span class="text-gray-600 font-medium">{{ $fmtInt($summary['free_tokens']) }}</span> безпл.
        </p>
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
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
    {{-- Row 1: wide time-series + tokens --}}
    <div class="bg-white border border-gray-200 rounded-xl p-4 lg:col-span-2">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по дни (USD)</h3>
        <div class="relative h-52"><canvas id="chartSpendDay"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по провайдър</h3>
        <div class="relative h-52"><canvas id="chartProvider"></canvas></div>
    </div>

    {{-- Row 2 --}}
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Токени по дни</h3>
        <div class="relative h-52"><canvas id="chartTokensDay"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по модел (топ 8)</h3>
        <div class="relative h-52"><canvas id="chartModel"></canvas></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по фирма (топ 8)</h3>
        <div class="relative h-52"><canvas id="chartCompany"></canvas></div>
    </div>

    {{-- Row 3: full-width volume --}}
    <div class="bg-white border border-gray-200 rounded-xl p-4 lg:col-span-3">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Обем по провайдър — платено срещу безплатно (Ollama)</h3>
        <div class="relative h-48"><canvas id="chartVolume"></canvas></div>
    </div>
</div>

{{-- ── Filter bar ────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('admin.costs.index') }}"
      class="bg-white border border-gray-200 rounded-xl p-4 mb-4 flex flex-wrap gap-3 items-end">
    <div class="min-w-[120px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Провайдър</label>
        <select name="provider" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['providers'] as $p)
                <option value="{{ $p }}" @selected(($filters['provider'] ?? '') === $p)>{{ $p }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[160px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Модел</label>
        <select name="model" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['models'] as $m)
                <option value="{{ $m }}" @selected(($filters['model'] ?? '') === $m)>{{ $m }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[160px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Фирма</label>
        <select name="company_id" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['companies'] as $c)
                <option value="{{ $c->id }}" @selected((string)($filters['company_id'] ?? '') === (string)$c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[160px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Flow</label>
        <select name="flow_id" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['flows'] as $f)
                <option value="{{ $f->id }}" @selected((string)($filters['flow_id'] ?? '') === (string)$f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[120px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Статус</label>
        <select name="status" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            <option value="completed" @selected(($filters['status'] ?? '') === 'completed')>completed</option>
            <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>failed</option>
        </select>
    </div>
    <div class="min-w-[130px]">
        <label class="block text-xs text-gray-500 mb-1">От</label>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
    </div>
    <div class="min-w-[130px]">
        <label class="block text-xs text-gray-500 mb-1">До</label>
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
    </div>
    <div class="flex gap-2 items-end pb-0.5">
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">Филтрирай</button>
        @if($hasFilters)
            <a href="{{ route('admin.costs.index') }}" class="px-4 py-2 rounded-lg text-sm border border-gray-300 text-gray-600 hover:bg-gray-50 transition">Изчисти</a>
        @endif
    </div>
</form>

{{-- ── Main table: grouped sessions (Grid.js) ────────────────────────── --}}
<div class="bg-white border border-gray-200 rounded-xl p-4 mb-4 overflow-x-auto">
    <p class="text-xs text-gray-400 mb-3">Кликни ред за да видиш отделните заявки в сесията.</p>
    <div id="costGrid"></div>
</div>

{{-- ── Sub-table modal (drill-down: individual calls in a session) ────── --}}
<div id="groupModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" id="groupModalOverlay"></div>
    <div class="absolute inset-0 flex items-start justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-5xl my-8 relative">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                <div>
                    <span id="gm-type-badge"></span>
                    <span id="gm-title" class="ml-2 font-semibold text-gray-900 text-sm"></span>
                </div>
                <button id="groupModalClose" class="text-gray-400 hover:text-gray-700 text-xl leading-none ml-4">&times;</button>
            </div>
            <div class="px-5 py-3">
                {{-- Run details header (shown only for run sessions) --}}
                <div id="gm-meta" class="hidden mb-4 grid grid-cols-2 md:grid-cols-4 gap-3"></div>
                <p class="text-xs text-gray-400 mb-2">Кликни ред за да видиш пълния промпт и отговора на модела.</p>
                <div id="subGridWrap">
                    <div id="subGridLoading" class="py-8 text-center text-gray-400 text-sm">Зареждане…</div>
                    <div id="subGrid"></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Detail popup (full prompt + response for a single request) ──────── --}}
<div id="costModal" class="fixed inset-0 z-[60] hidden">
    <div class="absolute inset-0 bg-black/40" id="costModalOverlay"></div>
    <div class="absolute inset-0 flex items-start justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl my-8 relative">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                <h3 class="font-semibold text-gray-900">Заявка <span id="m-id" class="text-gray-400 font-normal"></span></h3>
                <button id="costModalClose" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
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

<link href="https://cdn.jsdelivr.net/npm/gridjs@6.0.6/dist/theme/mermaid.min.css" rel="stylesheet">
<style>
.gridjs-th, .gridjs-td { padding: 10px 12px !important; }
.gridjs-td             { white-space: normal !important; word-break: break-word !important; vertical-align: top; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script src="https://cdn.jsdelivr.net/npm/gridjs@6.0.6/dist/gridjs.umd.js"></script>
<script>
const CHARTS = @json($charts);
const ROWS   = @json($rows);
const DETAIL_URL      = "{{ url('admin/costs/detail') }}";
const GROUP_DETAIL_URL = "{{ url('admin/costs/group-detail') }}";

// ── Colour palette ────────────────────────────────────────────────────
const C = { emerald:'#10b981', violet:'#8b5cf6', indigo:'#6366f1',
            amber:'#f59e0b', sky:'#0ea5e9', rose:'#f43f5e',
            gray:'#9ca3af', teal:'#14b8a6' };
const PALETTE = [C.indigo, C.emerald, C.violet, C.amber, C.sky, C.rose, C.teal, C.gray];

function providerColors(labels) {
    return labels.map(l => l === 'openai' ? C.emerald : l === 'anthropic' ? C.violet : C.gray);
}

// ── Charts ────────────────────────────────────────────────────────────
new Chart(document.getElementById('chartSpendDay'), {
    type: 'line',
    data: { labels: CHARTS.spendByDay.labels, datasets: [{
        label: 'USD', data: CHARTS.spendByDay.cost, borderColor: C.indigo,
        backgroundColor: 'rgba(99,102,241,0.12)', fill: true, tension: 0.3, pointRadius: 2 }] },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + Number(v).toFixed(2) } } } }
});

new Chart(document.getElementById('chartTokensDay'), {
    type: 'bar',
    data: { labels: CHARTS.spendByDay.labels, datasets: [
        { label: 'Prompt',     data: CHARTS.spendByDay.promptTokens,     backgroundColor: C.sky },
        { label: 'Completion', data: CHARTS.spendByDay.completionTokens, backgroundColor: C.indigo }] },
    options: { responsive: true, maintainAspectRatio: false,
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
});

new Chart(document.getElementById('chartProvider'), {
    type: 'doughnut',
    data: { labels: CHARTS.spendByProvider.labels, datasets: [{
        data: CHARTS.spendByProvider.data,
        backgroundColor: providerColors(CHARTS.spendByProvider.labels) }] },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('chartModel'), {
    type: 'bar',
    data: { labels: CHARTS.spendByModel.labels, datasets: [{
        label: 'USD', data: CHARTS.spendByModel.data, backgroundColor: C.violet }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { callback: v => '$' + Number(v).toFixed(2) } } } }
});

new Chart(document.getElementById('chartCompany'), {
    type: 'bar',
    data: { labels: CHARTS.spendByCompany.labels, datasets: [{
        label: 'USD', data: CHARTS.spendByCompany.data, backgroundColor: C.emerald }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { callback: v => '$' + Number(v).toFixed(2) } } } }
});

new Chart(document.getElementById('chartVolume'), {
    type: 'bar',
    data: { labels: CHARTS.volumeByProvider.labels, datasets: [
        { label: 'Заявки', data: CHARTS.volumeByProvider.requests, backgroundColor: C.indigo, yAxisID: 'y' },
        { label: 'Токени', data: CHARTS.volumeByProvider.tokens,   backgroundColor: C.amber,  yAxisID: 'y1' }] },
    options: { responsive: true, maintainAspectRatio: false,
        scales: {
            y:  { beginAtZero: true, position: 'left',  title: { display: true, text: 'Заявки' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Токени' } }
        } }
});

// ── Shared badge helpers ──────────────────────────────────────────────
const PROVIDER_COLORS = {
    openai:    'background:#d1fae5;color:#047857',
    anthropic: 'background:#ede9fe;color:#6d28d9',
    deepseek:  'background:#dbeafe;color:#1e40af',
    gemini:    'background:#fef9c3;color:#854d0e',
    xai:       'background:#e5e7eb;color:#111827',
    qwen:      'background:#ffedd5;color:#9a3412',
};
const providerBadge = (p) => {
    if (!p) return '';
    return p.split(', ')
        .map(name => {
            name = name.trim();
            const s = PROVIDER_COLORS[name] || 'background:#f3f4f6;color:#4b5563';
            return `<span style="${s};padding:2px 8px;border-radius:9999px;font-size:11px;display:inline-block;margin:1px 2px 1px 0;">${name}</span>`;
        })
        .join('');
};
const statusBadge = (st) => {
    const s = st === 'completed' ? 'background:#dcfce7;color:#15803d' : 'background:#fee2e2;color:#b91c1c';
    return `<span style="${s};padding:2px 8px;border-radius:9999px;font-size:11px;">${st ?? ''}</span>`;
};
const typeBadge = (type) => {
    const [icon, label, s] = type === 'generation'
        ? ['🤖', 'Създаване на агенти', 'background:#dbeafe;color:#1d4ed8']
        : ['▶',  'Изпълнение',          'background:#dcfce7;color:#166534'];
    return `<span style="${s};padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:500;">${icon} ${label}</span>`;
};

// ── Main grouped Grid.js ──────────────────────────────────────────────
// col 0: group_key (hidden) — used in rowClick for drill-down
const mainGrid = new gridjs.Grid({
    data: ROWS.map(r => [
        r.group_key,          // 0 hidden
        r.ref_id,             // 1 ID
        r.row_type,           // 2
        r.created_at,         // 3
        r.provider,           // 4
        r.model,              // 5
        r.company,            // 6
        r.flow,               // 7
        r.call_count,         // 8
        r.prompt_tokens,      // 9
        r.completion_tokens,  // 10
        r.cost_usd,           // 11
        r.duration_ms,        // 12
        r.status,             // 13
    ]),
    columns: [
        { name: 'Ключ', hidden: true },
        { name: 'ID',          width: '72px' },
        { name: 'Тип',         width: '180px', formatter: c => gridjs.html(typeBadge(c)) },
        { name: 'Дата/Час',    width: '148px' },
        { name: 'Провайдър',   width: '128px', formatter: c => gridjs.html(providerBadge(c)) },
        { name: 'Модел',       width: '210px', formatter: c => c || '—' },
        { name: 'Фирма',       width: '155px', formatter: c => c || '—' },
        { name: 'Flow',        width: '210px', formatter: c => c || '—' },
        { name: 'Агенти',      width: '95px' },
        { name: 'Вх. ток.',    width: '108px', formatter: c => c ? Number(c).toLocaleString() : '—' },
        { name: 'Изх. ток.',   width: '115px', formatter: c => c ? Number(c).toLocaleString() : '—' },
        { name: 'Цена',        width: '80px',  formatter: c => '$' + Number(c || 0).toFixed(2) },
        { name: 'Сек.',        width: '75px',  formatter: c => c ? (Number(c) / 1000).toFixed(1) : '—' },
        { name: 'Статус',      width: '108px', formatter: c => gridjs.html(statusBadge(c)) },
    ],
    search: true,
    sort: true,
    pagination: { limit: 25 },
    language: {
        search: { placeholder: 'Търси…' },
        pagination: { previous: '‹', next: '›', showing: 'Показва', to: '–', of: 'от', results: () => 'сесии' },
        noRecordsFound: 'Няма сесии за избраните филтри',
        error: 'Грешка при зареждане',
    },
    style: { table: { 'font-size': '13px' }, th: { 'white-space': 'nowrap' } },
});
mainGrid.render(document.getElementById('costGrid'));
mainGrid.on('rowClick', (event, row) => {
    const groupKey = row.cells[0].data;
    const rowType  = row.cells[2].data;
    const flowName = row.cells[7].data;
    openGroup(groupKey, rowType, flowName);
});

// ── Sub-table modal (drill-down) ──────────────────────────────────────
let subGridInstance = null;

async function openGroup(groupKey, rowType, flowName) {
    const modal       = document.getElementById('groupModal');
    const loading     = document.getElementById('subGridLoading');
    const subGridEl   = document.getElementById('subGrid');
    const titleEl     = document.getElementById('gm-title');
    const badgeEl     = document.getElementById('gm-type-badge');
    const metaEl      = document.getElementById('gm-meta');

    // Reset
    loading.classList.remove('hidden');
    subGridEl.innerHTML = '';
    metaEl.innerHTML = '';
    metaEl.classList.add('hidden');
    if (subGridInstance) { try { subGridInstance.destroy(); } catch(e) {} subGridInstance = null; }
    titleEl.textContent = flowName || groupKey;
    badgeEl.innerHTML   = typeBadge(rowType);
    modal.classList.remove('hidden');

    try {
        const resp = await fetch(`${GROUP_DETAIL_URL}?key=${encodeURIComponent(groupKey)}`,
            { headers: { Accept: 'application/json' } });
        if (!resp.ok) { loading.textContent = 'Грешка при зареждане.'; return; }
        const data = await resp.json();
        const rows = data.rows || [];

        // Run-details header (only for run sessions)
        if (data.meta) renderRunMeta(metaEl, data.meta);

        loading.classList.add('hidden');

        if (!rows.length) {
            subGridEl.innerHTML = '<p class="text-gray-400 text-sm py-4 text-center">Няма заявки.</p>';
            return;
        }

        subGridInstance = new gridjs.Grid({
            data: rows.map(r => [
                r.id, r.created_at, r.provider, r.model, r.purpose, r.agent,
                r.prompt_tokens, r.completion_tokens, r.cost_usd, r.duration_ms, r.status,
            ]),
            columns: [
                { name: '#',           width: '50px' },
                { name: 'Час',         width: '148px' },
                { name: 'Провайдър',   width: '120px', formatter: c => gridjs.html(providerBadge(c)) },
                { name: 'Модел',       width: '170px', formatter: c => c || '—' },
                { name: 'Цел',         width: '130px', formatter: c => c || '—' },
                { name: 'Агент',       width: '150px', formatter: c => c || '—' },
                { name: 'Вх. ток.',    width: '108px', formatter: c => c ? Number(c).toLocaleString() : '—' },
                { name: 'Изх. ток.',   width: '115px', formatter: c => c ? Number(c).toLocaleString() : '—' },
                { name: 'Цена',        width: '80px',  formatter: c => '$' + Number(c || 0).toFixed(2) },
                { name: 'Сек.',        width: '75px',  formatter: c => c ? (Number(c) / 1000).toFixed(1) : '—' },
                { name: 'Статус',      width: '100px', formatter: c => gridjs.html(statusBadge(c)) },
            ],
            sort: true,
            style: { table: { 'font-size': '12px' }, th: { 'white-space': 'nowrap' } },
        });
        subGridInstance.render(subGridEl);
        subGridInstance.on('rowClick', (event, row) => { openCost(row.cells[0].data); });
    } catch (e) {
        console.error(e);
        loading.textContent = 'Мрежова грешка.';
    }
}

// Run-details header cards (flow, status, timing, cost, agents)
function renderRunMeta(el, meta) {
    const card = (label, value) =>
        `<div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs text-gray-400">${label}</p>
            <p class="font-semibold text-gray-900 text-sm">${value ?? '—'}</p>
         </div>`;
    const secs = meta.duration_ms ? (meta.duration_ms / 1000).toFixed(1) + ' сек.' : '—';
    el.innerHTML =
        card('Flow', meta.flow || ('run #' + meta.flow_run_id)) +
        card('Статус', statusBadge(meta.status)) +
        card('Агенти', meta.agents) +
        card('Обща цена', '$' + Number(meta.cost_usd || 0).toFixed(2)) +
        card('Токени', Number(meta.tokens || 0).toLocaleString()) +
        card('Времетраене', secs) +
        card('Начало', meta.started_at) +
        card('Край', meta.completed_at);
    el.classList.remove('hidden');
}

function closeGroupModal() {
    document.getElementById('groupModal').classList.add('hidden');
}
document.getElementById('groupModalClose').addEventListener('click', closeGroupModal);
document.getElementById('groupModalOverlay').addEventListener('click', closeGroupModal);

// ── Detail popup (full prompt + response) ────────────────────────────
const costModal = document.getElementById('costModal');
const fmtInt    = v => Number(v || 0).toLocaleString();
const setText   = (id, val) => { document.getElementById(id).textContent = (val ?? '') === '' ? '—' : val; };

async function openCost(id) {
    try {
        const resp = await fetch(`${DETAIL_URL}?id=${encodeURIComponent(id)}`,
            { headers: { Accept: 'application/json' } });
        if (!resp.ok) { alert('Грешка при зареждане.'); return; }
        const d = await resp.json();

        setText('m-id',      '#' + d.id);
        setText('m-time',    d.created_at);
        setText('m-provider',d.provider);
        setText('m-model',   d.model);
        setText('m-purpose', [d.purpose, d.kind].filter(Boolean).join(' · '));
        setText('m-company', d.company);
        setText('m-flow',    d.flow);
        setText('m-agent',   [d.agent_name, d.agent_type ? '(' + d.agent_type + ')' : ''].filter(Boolean).join(' '));
        setText('m-run',     [d.flow_run_id ? 'run ' + d.flow_run_id : '', d.node_key ? '· ' + d.node_key : ''].filter(Boolean).join(' '));
        setText('m-status',  d.status);
        setText('m-ptok',    d.prompt_tokens     != null ? fmtInt(d.prompt_tokens)     : '—');
        setText('m-ctok',    d.completion_tokens != null ? fmtInt(d.completion_tokens) : '—');
        setText('m-cost',    '$' + Number(d.cost_usd || 0).toFixed(6));
        setText('m-dur',     d.duration_ms != null ? (d.duration_ms / 1000).toFixed(2) + ' сек.' : '—');

        const errWrap = document.getElementById('m-error-wrap');
        if (d.error) { document.getElementById('m-error').textContent = d.error; errWrap.classList.remove('hidden'); }
        else { errWrap.classList.add('hidden'); }

        const optWrap = document.getElementById('m-options-wrap');
        if (d.options && Object.keys(d.options).length) {
            document.getElementById('m-options').textContent = JSON.stringify(d.options, null, 2);
            optWrap.classList.remove('hidden');
        } else { optWrap.classList.add('hidden'); }

        setText('m-system',   d.system_prompt);
        setText('m-user',     d.user_message);
        setText('m-response', d.response_text);

        costModal.classList.remove('hidden');
    } catch (e) {
        console.error(e);
        alert('Мрежова грешка.');
    }
}

document.getElementById('costModalClose').addEventListener('click', () => costModal.classList.add('hidden'));
document.getElementById('costModalOverlay').addEventListener('click', () => costModal.classList.add('hidden'));
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        if (!costModal.classList.contains('hidden')) { costModal.classList.add('hidden'); }
        else { closeGroupModal(); }
    }
});
</script>
@endsection
