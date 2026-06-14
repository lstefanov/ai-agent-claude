@extends('admin.layouts.admin')

@section('title', 'Разходи')
@section('container-class', 'max-w-screen-2xl')

@section('content')
@php
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">💰 Разходи</h1>
        <p class="text-sm text-gray-500 mt-1">Пълен одит на всяка LLM заявка — токени, цена, промпт и отговор. Данните се зареждат по таб, при нужда.</p>
    </div>
</div>

{{-- ── Filter bar (JS-driven — no full page reload) ───────────────────── --}}
<div class="bg-white border border-gray-200 rounded-xl p-4 mb-4 flex flex-wrap gap-3 items-end">
    <div class="min-w-[120px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Провайдър</label>
        <select id="f-provider" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['providers'] as $p)
                <option value="{{ $p }}" @selected(($filters['provider'] ?? '') === $p)>{{ $p }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[160px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Модел</label>
        <select id="f-model" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['models'] as $m)
                <option value="{{ $m }}" @selected(($filters['model'] ?? '') === $m)>{{ $m }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[160px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Фирма</label>
        <select id="f-company" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['companies'] as $c)
                <option value="{{ $c->id }}" @selected((string)($filters['company_id'] ?? '') === (string)$c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[160px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Flow</label>
        <select id="f-flow" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            @foreach($filterOptions['flows'] as $f)
                <option value="{{ $f->id }}" @selected((string)($filters['flow_id'] ?? '') === (string)$f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-[120px] flex-1">
        <label class="block text-xs text-gray-500 mb-1">Статус</label>
        <select id="f-status" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="">Всички</option>
            <option value="completed" @selected(($filters['status'] ?? '') === 'completed')>completed</option>
            <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>failed</option>
        </select>
    </div>
    <div class="min-w-[130px]">
        <label class="block text-xs text-gray-500 mb-1">От</label>
        <input type="date" id="f-from" value="{{ $filters['from'] ?? '' }}" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
    </div>
    <div class="min-w-[130px]">
        <label class="block text-xs text-gray-500 mb-1">До</label>
        <input type="date" id="f-to" value="{{ $filters['to'] ?? '' }}" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
    </div>
    <div class="flex gap-2 items-end pb-0.5">
        <button type="button" onclick="applyFilters()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">Филтрирай</button>
        <button type="button" onclick="clearFilters()" class="px-4 py-2 rounded-lg text-sm border border-gray-300 text-gray-600 hover:bg-gray-50 transition {{ $hasFilters ? '' : 'hidden' }}" id="f-clear">Изчисти</button>
    </div>
</div>

{{-- ── Tab nav ───────────────────────────────────────────────────────── --}}
<div id="tabNav" class="flex flex-wrap gap-1 border-b border-gray-200 mb-5">
    <button class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-800 -mb-px transition" data-tab="overview">📊 Преглед</button>
    <button class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-800 -mb-px transition" data-tab="grid">▶ Изпълнения и създаване</button>
    <button class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-800 -mb-px transition" data-tab="chat">🤖 Copilot чат</button>
    <button class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-800 -mb-px transition" data-tab="external">🔎 Външни API</button>
    <button class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-800 -mb-px transition" data-tab="knowledge">🧠 Знания и Разни</button>
    <button class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-800 -mb-px transition" data-tab="ocr">📄 OCR</button>
</div>

{{-- ══ Tab: Преглед ═══════════════════════════════════════════════════ --}}
<div class="tab-panel" data-panel="overview">
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3 mb-4">
        <div class="bg-white border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500 uppercase tracking-wide">Общо разход</p><p id="sum-total" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500 uppercase tracking-wide">Този месец</p><p id="sum-month" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500 uppercase tracking-wide">Днес</p><p id="sum-today" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Заявки</p>
            <p id="sum-requests" class="text-2xl font-bold text-gray-900 mt-1">—</p>
            <p class="text-xs text-gray-500 mt-1"><span id="sum-paid-req" class="text-emerald-600 font-medium">—</span> платени<br><span id="sum-free-req" class="text-gray-600 font-medium">—</span> безпл.</p>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Токени</p>
            <p id="sum-tokens" class="text-2xl font-bold text-gray-900 mt-1">—</p>
            <p class="text-xs text-gray-500 mt-1"><span id="sum-paid-tok" class="text-emerald-600 font-medium">—</span> платени<br><span id="sum-free-tok" class="text-gray-600 font-medium">—</span> безпл.</p>
        </div>
    </div>

    <div id="ovProviders" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-8"></div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
        <div class="bg-white border border-gray-200 rounded-xl p-4 lg:col-span-2">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по дни (USD)</h3>
            <div class="relative h-52"><canvas id="chartSpendDay"></canvas></div>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Разход по провайдър</h3>
            <div class="relative h-52"><canvas id="chartProvider"></canvas></div>
        </div>
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
        <div class="bg-white border border-gray-200 rounded-xl p-4 lg:col-span-3">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Обем по провайдър — платено срещу безплатно (Ollama)</h3>
            <div class="relative h-48"><canvas id="chartVolume"></canvas></div>
        </div>
    </div>
</div>

{{-- ══ Tab: Изпълнения и създаване ════════════════════════════════════ --}}
<div class="tab-panel hidden" data-panel="grid">
    <div class="bg-white border border-gray-200 rounded-xl p-4 overflow-x-auto">
        <p class="text-xs text-gray-400 mb-3">Кликни ред за да видиш отделните заявки в сесията.</p>
        <div id="costGrid"></div>
    </div>
</div>

{{-- ══ Tab: Copilot чат ══════════════════════════════════════════════ --}}
<div class="tab-panel hidden" data-panel="chat">
    <div class="flex items-center flex-wrap gap-3 mb-4">
        <h2 class="text-lg font-bold text-gray-900">🤖 Чат асистент (Builder Copilot)</h2>
        @if($chatModel)
            <span class="inline-block px-3 py-0.5 rounded-full text-xs font-semibold text-amber-600 border border-amber-200 bg-white">{{ $chatProvider }} / {{ $chatModel }}</span>
        @endif
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3 mb-4">
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Сесии</p><p id="chat-sessions" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Съобщения</p><p id="chat-messages" class="text-2xl font-bold text-gray-900 mt-1">—</p><p class="text-xs text-gray-500 mt-1"><span id="chat-calls">—</span> LLM заявки</p></div>
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Токени (общо)</p><p id="chat-tokens" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Разход</p><p id="chat-cost" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Ср. разход / сесия</p><p id="chat-avg" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="chat-topmodel" class="text-xs text-gray-400 mt-1 truncate"></p></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4 overflow-x-auto">
        <p class="text-xs text-gray-400 mb-3">Кликни ред за да видиш пълния разговор (въпрос и отговор).</p>
        <div id="chatGrid"></div>
    </div>
</div>

{{-- ══ Tab: Външни API ═══════════════════════════════════════════════ --}}
<div class="tab-panel hidden" data-panel="external">
    <div class="flex items-center flex-wrap gap-3 mb-4">
        <h2 class="text-lg font-bold text-gray-900">🔎 Външни API</h2>
        <span class="inline-block px-3 py-0.5 rounded-full text-xs font-semibold text-teal-700 border border-teal-200 bg-white">Perplexity · Brave · Google Places</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div class="bg-white border border-teal-200 rounded-xl p-4"><p class="text-xs text-teal-600 uppercase tracking-wide">Perplexity</p><p id="ext-pplx-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="ext-pplx-sub" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-teal-200 rounded-xl p-4"><p class="text-xs text-teal-600 uppercase tracking-wide">Brave Search</p><p id="ext-brave-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="ext-brave-sub" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-teal-200 rounded-xl p-4"><p class="text-xs text-teal-600 uppercase tracking-wide">Google Places</p><p id="ext-gp-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="ext-gp-sub" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-teal-200 rounded-xl p-4"><p class="text-xs text-teal-600 uppercase tracking-wide">Общо разход</p><p id="ext-total" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="ext-total-sub" class="text-xs text-gray-400 mt-1"></p></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4 overflow-x-auto">
        <p class="text-xs text-gray-400 mb-3">Кликни ред за пълната заявка (заявка, резултати, разход).</p>
        <div id="externalApiGrid"></div>
    </div>
</div>

{{-- ══ Tab: Знания и Разни ═══════════════════════════════════════════ --}}
<div class="tab-panel hidden" data-panel="knowledge">
    <div class="flex items-center flex-wrap gap-3 mb-4">
        <h2 class="text-lg font-bold text-gray-900">🧠 Знания и Embeddings</h2>
        <span class="inline-block px-3 py-0.5 rounded-full text-xs font-semibold text-violet-700 border border-violet-200 bg-white">синтез · факти · чат · embeddings</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4">
        <div class="bg-white border border-violet-200 rounded-xl p-4"><p class="text-xs text-violet-600 uppercase tracking-wide">Синтез</p><p id="kn-syn-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="kn-syn-cost" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-violet-200 rounded-xl p-4"><p class="text-xs text-violet-600 uppercase tracking-wide">Факти</p><p id="kn-fact-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="kn-fact-cost" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-violet-200 rounded-xl p-4"><p class="text-xs text-violet-600 uppercase tracking-wide">Чат за знания</p><p id="kn-chat-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="kn-chat-cost" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-violet-200 rounded-xl p-4"><p class="text-xs text-violet-600 uppercase tracking-wide">Embeddings</p><p id="kn-emb-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="kn-emb-cost" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-violet-200 rounded-xl p-4"><p class="text-xs text-violet-600 uppercase tracking-wide">Общо разход</p><p id="kn-total" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="kn-total-sub" class="text-xs text-gray-400 mt-1"></p></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4 overflow-x-auto mb-8">
        <p class="text-xs text-gray-400 mb-3">Кликни ред за пълния промпт + изход. Локалните Ollama embeddings са с разход $0.</p>
        <div id="knowledgeGrid"></div>
    </div>

    <div class="flex items-center flex-wrap gap-3 mb-4">
        <h2 class="text-lg font-bold text-gray-900">🧾 Разни</h2>
        <span class="inline-block px-3 py-0.5 rounded-full text-xs font-semibold text-gray-600 border border-gray-300 bg-white">извън run/сесия — ad-hoc, relevel, без контекст</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-white border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500 uppercase tracking-wide">Заявки</p><p id="oth-req" class="text-2xl font-bold text-gray-900 mt-1">—</p><p id="oth-top" class="text-xs text-gray-400 mt-1"></p></div>
        <div class="bg-white border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500 uppercase tracking-wide">Токени</p><p id="oth-tokens" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500 uppercase tracking-wide">Разход</p><p id="oth-cost" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4 overflow-x-auto">
        <p class="text-xs text-gray-400 mb-3">Извиквания без flow run и без сесия — кликни ред за пълния промпт + изход.</p>
        <div id="otherGrid"></div>
    </div>
</div>

{{-- ══ Tab: OCR ══════════════════════════════════════════════════════ --}}
<div class="tab-panel hidden" data-panel="ocr">
    <div class="flex items-center flex-wrap gap-3 mb-4">
        <h2 class="text-lg font-bold text-gray-900">📄 Mistral OCR</h2>
        <span class="inline-block px-3 py-0.5 rounded-full text-xs font-semibold text-amber-700 border border-amber-200 bg-white">сканирани документи</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Документи</p><p id="ocr-docs" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Страници</p><p id="ocr-pages" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
        <div class="bg-white border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-600 uppercase tracking-wide">Разход</p><p id="ocr-cost" class="text-2xl font-bold text-gray-900 mt-1">—</p></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-xl p-4 overflow-x-auto">
        <p class="text-xs text-gray-400 mb-3">Кликни ред за преглед на сканираното (суров OCR + синтезиран digest). „Документ" отваря оригинала.</p>
        <div id="ocrGrid"></div>
    </div>
</div>

{{-- ── OCR preview popup (raw scan + synthesized digest) ───────────────── --}}
<div id="ocrModal" class="fixed inset-0 z-[60] hidden">
    <div class="absolute inset-0 bg-black/40" id="ocrModalOverlay"></div>
    <div class="absolute inset-0 flex items-start justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl my-8 relative">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200 gap-3">
                <div class="min-w-0">
                    <h3 class="font-semibold text-gray-900 truncate" id="ocr-doc">Документ</h3>
                    <p class="text-xs text-gray-400" id="ocr-meta"></p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <a id="ocr-original" href="#" target="_blank" rel="noopener"
                       class="text-xs px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 transition">Отвори оригинала ↗</a>
                    <button id="ocrModalClose" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
                </div>
            </div>
            <div class="px-5 pt-3">
                <div class="flex gap-1 border-b border-gray-100">
                    <button class="ocr-tab px-3 py-2 text-sm border-b-2 border-indigo-500 text-indigo-600 font-medium" data-tab="raw">Сканиран текст (OCR)</button>
                    <button class="ocr-tab px-3 py-2 text-sm border-b-2 border-transparent text-gray-500 hover:text-gray-800" data-tab="digest">Синтезиран digest</button>
                </div>
            </div>
            <div class="p-5">
                <pre id="ocr-raw" class="whitespace-pre-wrap text-sm text-gray-700 bg-gray-50 border border-gray-100 rounded-lg p-4 max-h-[60vh] overflow-y-auto"></pre>
                <pre id="ocr-digest" class="hidden whitespace-pre-wrap text-sm text-gray-700 bg-gray-50 border border-gray-100 rounded-lg p-4 max-h-[60vh] overflow-y-auto"></pre>
            </div>
        </div>
    </div>
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

{{-- ── Chat transcript modal ──────────────────────────────────────────── --}}
<div id="chatModal" class="fixed inset-0 z-[70] hidden">
    <div class="absolute inset-0 bg-black/40" id="chatModalOverlay"></div>
    <div class="absolute inset-0 flex items-start justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl my-8 relative">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                <div class="flex items-center gap-2 min-w-0">
                    <span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;white-space:nowrap;">🤖 Чат</span>
                    <span id="cm-title" class="font-semibold text-gray-900 text-sm truncate"></span>
                </div>
                <button id="chatModalClose" class="text-gray-400 hover:text-gray-700 text-xl leading-none ml-4 shrink-0">&times;</button>
            </div>
            <div class="p-5 space-y-4">
                <div id="cm-meta" class="grid grid-cols-2 md:grid-cols-3 gap-3"></div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Разговор</p>
                    <div id="cm-messages" class="space-y-3 max-h-[62vh] overflow-y-auto pr-1"></div>
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
const DATA_BASE        = "{{ url('admin/costs/data') }}";
const DETAIL_URL       = "{{ url('admin/costs/detail') }}";
const GROUP_DETAIL_URL = "{{ url('admin/costs/group-detail') }}";
const CHAT_DETAIL_URL  = "{{ url('admin/costs/chat-detail') }}";
const OCR_DETAIL_URL   = "{{ url('admin/costs/ocr-detail') }}";
const MODELS_BY_PROVIDER = @json($filterOptions['modelsByProvider']);

// ── Formatting helpers ────────────────────────────────────────────────
const fmtInt = v => Number(v || 0).toLocaleString();
function fmtUsd(v) { v = Number(v) || 0; return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtUsdSmart(v) {
    v = Number(v) || 0;
    const d = (v > 0 && v < 0.0001) ? 6 : (v > 0 && v < 0.01) ? 4 : 2;
    return '$' + v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}
const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const setVal = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };

// ── Filter state ──────────────────────────────────────────────────────
function filterParams() {
    const p = {};
    const g = id => (document.getElementById(id)?.value || '').trim();
    if (g('f-provider')) p.provider = g('f-provider');
    if (g('f-model'))    p.model = g('f-model');
    if (g('f-company'))  p.company_id = g('f-company');
    if (g('f-flow'))     p.flow_id = g('f-flow');
    if (g('f-status'))   p.status = g('f-status');
    if (g('f-from'))     p.from = g('f-from');
    if (g('f-to'))       p.to = g('f-to');
    return p;
}
function filterQS() { return new URLSearchParams(filterParams()).toString(); }

function appendQS(url, params) {
    const u = new URL(url, location.origin);
    Object.entries(params).forEach(([k, v]) => { if (v !== '' && v != null) u.searchParams.set(k, v); });
    return u.pathname + '?' + u.searchParams.toString();
}

function applyFilters() {
    const qs = filterQS();
    history.replaceState(null, '', qs ? ('?' + qs) : location.pathname);
    document.getElementById('f-clear').classList.toggle('hidden', qs === '');
    destroyAll();
    loadTab(activeTab);
}
function clearFilters() {
    ['f-provider', 'f-model', 'f-company', 'f-flow', 'f-status', 'f-from', 'f-to'].forEach(id => {
        const e = document.getElementById(id); if (e) e.value = '';
    });
    syncModelOptions();
    applyFilters();
}

// Linked dropdowns: "Модел" shows only the chosen provider's models
const providerSel = document.getElementById('f-provider');
const modelSel    = document.getElementById('f-model');
function syncModelOptions() {
    const models = providerSel.value
        ? (MODELS_BY_PROVIDER[providerSel.value] || [])
        : [...new Set(Object.values(MODELS_BY_PROVIDER).flat())].sort();
    const current = modelSel.value;
    modelSel.innerHTML = '<option value="">Всички</option>' + models.map(m => `<option value="${esc(m)}">${esc(m)}</option>`).join('');
    if (models.includes(current)) modelSel.value = current;
}
providerSel.addEventListener('change', syncModelOptions);
syncModelOptions();

// ── Badge helpers ─────────────────────────────────────────────────────
const PROVIDER_COLORS = {
    openai:    'background:#d1fae5;color:#047857',
    anthropic: 'background:#ede9fe;color:#6d28d9',
    deepseek:  'background:#dbeafe;color:#1e40af',
    gemini:    'background:#fef9c3;color:#854d0e',
    xai:       'background:#e5e7eb;color:#111827',
    qwen:      'background:#ffedd5;color:#9a3412',
    perplexity:'background:#ccfbf1;color:#0f766e',
    mistral:   'background:#fee2e2;color:#b91c1c',
    brave:     'background:#ffedd5;color:#c2410c',
    google_places: 'background:#e0f2fe;color:#075985',
};
const providerBadge = (p) => {
    if (!p) return '';
    return p.split(', ').map(name => {
        name = name.trim();
        const s = PROVIDER_COLORS[name] || 'background:#f3f4f6;color:#4b5563';
        return `<span style="${s};padding:2px 8px;border-radius:9999px;font-size:11px;display:inline-block;margin:1px 2px 1px 0;">${esc(name)}</span>`;
    }).join('');
};
const statusBadge = (st) => {
    const s = st === 'completed' ? 'background:#dcfce7;color:#15803d' : 'background:#fee2e2;color:#b91c1c';
    return `<span style="${s};padding:2px 8px;border-radius:9999px;font-size:11px;">${esc(st ?? '')}</span>`;
};
const typeBadge = (type) => {
    const [icon, label, s] = type === 'generation'
        ? ['🤖', 'Създаване на агенти', 'background:#dbeafe;color:#1d4ed8']
        : ['▶',  'Изпълнение',          'background:#dcfce7;color:#166534'];
    return `<span style="${s};padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:500;white-space:nowrap;display:inline-block;">${icon} ${label}</span>`;
};
const extKind = k => ({ web_search: '🌐 Web search', people_search: '👤 People search', reviews: '⭐ Reviews' }[k] || k || '—');
const knowledgePurpose = p => ({ embedding: '🔢 Embedding', knowledge_synthesis: '📝 Синтез', knowledge_fact_harvest: '🧩 Факти', knowledge_chat: '💬 Чат' }[p] || p || '—');

// ── Grid.js server-mode builder ───────────────────────────────────────
const LANG = (results) => ({
    search: { placeholder: 'Търси…' },
    pagination: { previous: '‹', next: '›', showing: 'Показва', to: '–', of: 'от', results: () => results },
    loading: 'Зареждане…',
    noRecordsFound: 'Няма записи за избраните филтри',
    error: 'Грешка при зареждане',
});
function sortUrl(prev, cols, sortNames) {
    if (!cols || !cols.length) return prev;
    const c = cols[0];
    const name = sortNames[c.index];
    if (!name) return prev;
    return appendQS(prev, { sort: name, dir: c.direction === 1 ? 'asc' : 'desc' });
}
function buildServerGrid({ el, section, columns, sortNames, map, results, onData }) {
    const base = `${DATA_BASE}/${section}?${filterQS()}`;
    const grid = new gridjs.Grid({
        columns,
        server: {
            url: base,
            then: data => { if (onData) onData(data); return (data.rows || []).map(map); },
            total: data => data.total || 0,
        },
        pagination: { limit: 25, server: { url: (prev, page, limit) => appendQS(prev, { page: page + 1, limit }) } },
        search: { server: { url: (prev, kw) => appendQS(prev, { search: kw }) } },
        sort: { multiColumn: false, server: { url: (prev, cols) => sortUrl(prev, cols, sortNames) } },
        language: LANG(results),
        style: { table: { 'font-size': '13px' }, th: { 'white-space': 'nowrap' } },
    });
    grid.render(document.getElementById(el));
    return grid;
}

// ══ Tab machinery ═════════════════════════════════════════════════════
const gridInst = {};   // section -> Grid.js instance
const chartInst = {};  // chart id -> Chart instance
const tabState = {};   // tab -> loaded bool
let activeTab = 'overview';

function destroyAll() {
    Object.values(gridInst).forEach(g => { try { g.destroy(); } catch (e) {} });
    for (const k in gridInst) delete gridInst[k];
    Object.values(chartInst).forEach(c => { try { c.destroy(); } catch (e) {} });
    for (const k in chartInst) delete chartInst[k];
    Object.keys(tabState).forEach(k => tabState[k] = false);
}

function setActiveBtn(btn, on) {
    btn.classList.toggle('border-indigo-500', on);
    btn.classList.toggle('text-indigo-600', on);
    btn.classList.toggle('border-transparent', !on);
    btn.classList.toggle('text-gray-500', !on);
}
function showTab(name) {
    activeTab = name;
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== name));
    document.querySelectorAll('.tab-btn').forEach(b => setActiveBtn(b, b.dataset.tab === name));
    if (!tabState[name]) loadTab(name);
}
function loadTab(name) {
    tabState[name] = true;
    (LOADERS[name] || (() => {}))();
}
document.querySelectorAll('.tab-btn').forEach(b => b.addEventListener('click', () => showTab(b.dataset.tab)));

// ── Loaders ───────────────────────────────────────────────────────────
const LOADERS = {
    overview: loadOverview,
    grid: mountMain,
    chat: mountChat,
    external: mountExternal,
    knowledge: () => { mountKnowledge(); mountOther(); },
    ocr: mountOcr,
};

// Overview: summary cards + provider boxes + charts (fetched once per filter set)
async function loadOverview() {
    try {
        const resp = await fetch(`${DATA_BASE}/overview?${filterQS()}`, { headers: { Accept: 'application/json' } });
        if (!resp.ok) return;
        const d = await resp.json();
        const s = d.summary;
        setVal('sum-total', fmtUsd(s.total_cost));
        setVal('sum-month', fmtUsd(s.month_cost));
        setVal('sum-today', fmtUsd(s.today_cost));
        setVal('sum-requests', fmtInt(s.total_requests));
        setVal('sum-paid-req', fmtInt(s.paid_requests));
        setVal('sum-free-req', fmtInt(s.ollama_requests));
        setVal('sum-tokens', fmtInt(s.paid_tokens + s.free_tokens));
        setVal('sum-paid-tok', fmtInt(s.paid_tokens));
        setVal('sum-free-tok', fmtInt(s.free_tokens));
        renderProviderBoxes(d.providers);
        renderCharts(d.charts);
    } catch (e) { console.error(e); }
}

const PROVIDER_BOX_STYLE = {
    openai:    { border: 'border-emerald-200', label: 'text-emerald-600', value: 'text-emerald-700' },
    anthropic: { border: 'border-violet-200',  label: 'text-violet-600',  value: 'text-violet-700' },
    deepseek:  { border: 'border-blue-200',    label: 'text-blue-600',    value: 'text-blue-700' },
    gemini:    { border: 'border-amber-200',   label: 'text-amber-600',   value: 'text-amber-700' },
    xai:       { border: 'border-gray-300',    label: 'text-gray-600',    value: 'text-gray-800' },
    qwen:      { border: 'border-orange-200',  label: 'text-orange-600',  value: 'text-orange-700' },
    ollama:    { border: 'border-gray-200',    label: 'text-gray-500',    value: 'text-gray-700' },
    _default:  { border: 'border-gray-200',    label: 'text-gray-500',    value: 'text-gray-700' },
};
function renderProviderBoxes(providers) {
    const wrap = document.getElementById('ovProviders');
    if (!providers || !providers.length) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = providers.map(p => {
        const st = PROVIDER_BOX_STYLE[p.provider] || PROVIDER_BOX_STYLE._default;
        const isOllama = p.provider === 'ollama';
        const top = 4;
        const extra = isOllama ? Math.max(0, p.models.length - top) : 0;
        const models = p.models.map((m, idx) => {
            const unused = (m.requests || 0) === 0;
            const hide = isOllama && extra > 0 && idx >= top;
            const right = unused
                ? '<span class="text-gray-300 italic">—</span>'
                : `<span class="font-semibold text-gray-800">${fmtUsdSmart(m.cost)}</span><span class="text-gray-400 ml-1">${fmtInt(m.requests)}×</span>`;
            return `<li class="flex items-baseline justify-between gap-2 text-xs ${unused ? 'opacity-40' : ''}" ${hide ? 'style="display:none;" data-ollama-extra' : ''}>
                <span class="${unused ? 'text-gray-400' : 'text-gray-600'} truncate" title="${esc(m.model)}">${esc(m.model)}</span>
                <span class="shrink-0 text-right">${right}</span></li>`;
        }).join('');
        const toggle = (isOllama && extra > 0)
            ? `<button onclick="toggleOllamaModels(this)" data-extra="${extra}" class="mt-2 text-xs text-gray-400 hover:text-gray-600 transition w-full text-left">покажи всички (${extra} още) ↓</button>`
            : '';
        return `<div class="bg-white border ${st.border} rounded-xl p-4">
            <div class="flex items-baseline justify-between">
                <p class="text-xs ${st.label} uppercase tracking-wide font-semibold">${esc(p.provider)}</p>
                <p class="text-xs text-gray-400">${fmtInt(p.requests)} ${p.requests === 1 ? 'заявка' : 'заявки'}</p>
            </div>
            <p class="text-2xl font-bold ${st.value} mt-1">${fmtUsdSmart(p.total)}</p>
            <ul class="mt-3 pt-3 border-t border-gray-100 space-y-1.5">${models}</ul>
            ${toggle}
        </div>`;
    }).join('');
}
function toggleOllamaModels(btn) {
    const ul = btn.previousElementSibling;
    const extras = ul.querySelectorAll('[data-ollama-extra]');
    const expanded = btn.dataset.expanded === '1';
    extras.forEach(li => li.style.display = expanded ? 'none' : '');
    btn.dataset.expanded = expanded ? '0' : '1';
    btn.textContent = expanded ? `покажи всички (${btn.dataset.extra} още) ↓` : 'скрий ↑';
}

// ── Charts ────────────────────────────────────────────────────────────
const C = { emerald: '#10b981', violet: '#8b5cf6', indigo: '#6366f1', amber: '#f59e0b', sky: '#0ea5e9', rose: '#f43f5e', gray: '#9ca3af', teal: '#14b8a6' };
const PROVIDER_CHART_COLORS = { openai: C.emerald, anthropic: C.violet, deepseek: C.sky, gemini: C.amber, xai: C.gray, qwen: C.rose, ollama: C.teal };
const providerColors = labels => labels.map(l => PROVIDER_CHART_COLORS[l] || C.gray);

function renderCharts(CHARTS) {
    Object.values(chartInst).forEach(c => { try { c.destroy(); } catch (e) {} });
    chartInst.spendDay = new Chart(document.getElementById('chartSpendDay'), {
        type: 'line',
        data: { labels: CHARTS.spendByDay.labels, datasets: [{ label: 'USD', data: CHARTS.spendByDay.cost, borderColor: C.indigo, backgroundColor: 'rgba(99,102,241,0.12)', fill: true, tension: 0.3, pointRadius: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + Number(v).toFixed(2) } } } }
    });
    chartInst.tokensDay = new Chart(document.getElementById('chartTokensDay'), {
        type: 'bar',
        data: { labels: CHARTS.spendByDay.labels, datasets: [
            { label: 'Prompt', data: CHARTS.spendByDay.promptTokens, backgroundColor: C.sky },
            { label: 'Completion', data: CHARTS.spendByDay.completionTokens, backgroundColor: C.indigo }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
    });
    chartInst.provider = new Chart(document.getElementById('chartProvider'), {
        type: 'doughnut',
        data: { labels: CHARTS.spendByProvider.labels, datasets: [{ data: CHARTS.spendByProvider.data, backgroundColor: providerColors(CHARTS.spendByProvider.labels) }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    chartInst.model = new Chart(document.getElementById('chartModel'), {
        type: 'bar',
        data: { labels: CHARTS.spendByModel.labels, datasets: [{ label: 'USD', data: CHARTS.spendByModel.data, backgroundColor: C.violet }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { callback: v => '$' + Number(v).toFixed(2) } } } }
    });
    chartInst.company = new Chart(document.getElementById('chartCompany'), {
        type: 'bar',
        data: { labels: CHARTS.spendByCompany.labels, datasets: [{ label: 'USD', data: CHARTS.spendByCompany.data, backgroundColor: C.emerald }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { callback: v => '$' + Number(v).toFixed(2) } } } }
    });
    chartInst.volume = new Chart(document.getElementById('chartVolume'), {
        type: 'bar',
        data: { labels: CHARTS.volumeByProvider.labels, datasets: [
            { label: 'Заявки', data: CHARTS.volumeByProvider.requests, backgroundColor: C.indigo, yAxisID: 'y' },
            { label: 'Токени', data: CHARTS.volumeByProvider.tokens, backgroundColor: C.amber, yAxisID: 'y1' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Заявки' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Токени' } } } }
    });
}

// ── Main grouped grid (run + generation) ──────────────────────────────
function mountMain() {
    gridInst.grid = buildServerGrid({
        el: 'costGrid', section: 'grid', results: 'сесии',
        columns: [
            { name: 'Ключ', hidden: true },
            { name: 'ID', width: '72px', sort: false },
            { name: 'Тип', width: '180px', sort: false, formatter: c => gridjs.html(typeBadge(c)) },
            { name: 'Дата/Час', width: '148px' },
            { name: 'Провайдър', width: '128px', sort: false, formatter: c => gridjs.html(providerBadge(c)) },
            { name: 'Модел', width: '210px', sort: false, formatter: c => c || '—' },
            { name: 'Фирма', width: '155px', sort: false, formatter: c => c || '—' },
            { name: 'Flow', width: '210px', sort: false, formatter: c => c || '—' },
            { name: 'Агенти', width: '95px', sort: false },
            { name: 'Вх. ток.', width: '108px', sort: false, formatter: c => c ? Number(c).toLocaleString() : '—' },
            { name: 'Изх. ток.', width: '115px', sort: false, formatter: c => c ? Number(c).toLocaleString() : '—' },
            { name: 'Цена', width: '80px', formatter: c => '$' + Number(c || 0).toFixed(2) },
            { name: 'Сек.', width: '75px', formatter: c => c ? (Number(c) / 1000).toFixed(1) : '—' },
            { name: 'Статус', width: '108px', sort: false, formatter: c => gridjs.html(statusBadge(c)) },
        ],
        sortNames: [null, null, null, 'created_at', null, null, null, null, null, null, null, 'cost_usd', 'duration_ms', null],
        map: r => [r.group_key, r.ref_id, r.row_type, r.created_at, r.provider, r.model, r.company, r.flow, r.call_count, r.prompt_tokens, r.completion_tokens, r.cost_usd, r.duration_ms, r.status],
    });
    gridInst.grid.on('rowClick', (event, row) => openGroup(row.cells[0].data, row.cells[2].data, row.cells[7].data));
}

// ── Copilot chat sessions ─────────────────────────────────────────────
function mountChat() {
    gridInst.chat = buildServerGrid({
        el: 'chatGrid', section: 'chat', results: 'разговора',
        columns: [
            { name: 'Сесия', hidden: true },
            { name: 'Последна активност', width: '160px' },
            { name: 'Провайдър', width: '110px', sort: false, formatter: c => gridjs.html(providerBadge(c)) },
            { name: 'Модел', width: '180px', sort: false, formatter: c => c || '—' },
            { name: 'Фирма', width: '150px', sort: false, formatter: c => c || '—' },
            { name: 'Flow', width: '180px', sort: false, formatter: c => c || '—' },
            { name: 'Съобщения', width: '112px', sort: false },
            { name: 'Токени', width: '100px', formatter: c => c ? Number(c).toLocaleString() : '—' },
            { name: 'Цена', width: '84px', formatter: c => '$' + Number(c || 0).toFixed(4) },
            { name: 'Статус', width: '100px', sort: false, formatter: c => gridjs.html(statusBadge(c)) },
        ],
        sortNames: [null, 'last_at', null, null, null, null, null, 'total_tokens', 'cost_usd', null],
        map: r => [r.session_id, r.created_at, r.provider, r.model, r.company, r.flow, r.msg_count, r.total_tokens, r.cost_usd, r.status],
        onData: d => paintChatSummary(d.summary),
    });
    gridInst.chat.on('rowClick', (event, row) => openChat(row.cells[0].data, row.cells[5].data || row.cells[4].data));
}
function paintChatSummary(s) {
    if (!s) return;
    setVal('chat-sessions', fmtInt(s.sessions));
    setVal('chat-messages', fmtInt(s.messages));
    setVal('chat-calls', fmtInt(s.calls));
    setVal('chat-tokens', fmtInt(s.total_tokens));
    setVal('chat-cost', fmtUsdSmart(s.total_cost));
    setVal('chat-avg', fmtUsdSmart(s.avg_cost));
    setVal('chat-topmodel', s.top_model !== '—' ? s.top_model : '');
}

// ── External APIs ─────────────────────────────────────────────────────
function mountExternal() {
    gridInst.external = buildServerGrid({
        el: 'externalApiGrid', section: 'external', results: 'заявки',
        columns: [
            { name: 'ID', hidden: true },
            { name: 'Време', width: '150px' },
            { name: 'Провайдър', width: '128px', sort: false, formatter: c => gridjs.html(providerBadge(c)) },
            { name: 'Тип', width: '140px', sort: false, formatter: c => extKind(c) },
            { name: 'Фирма', width: '140px', sort: false, formatter: c => c || '—' },
            { name: 'Заявка', width: '300px', sort: false, formatter: c => c || '—' },
            { name: 'Разход', width: '96px', formatter: c => '$' + Number(c || 0).toFixed(4) },
            { name: 'Времетраене', width: '104px', formatter: c => c ? (c / 1000).toFixed(2) + ' сек.' : '—' },
            { name: 'Статус', width: '100px', sort: false, formatter: c => gridjs.html(statusBadge(c)) },
        ],
        sortNames: [null, 'created_at', null, null, null, null, 'cost_usd', 'duration_ms', null],
        map: r => [r.id, r.created_at, r.provider, r.kind, r.company, r.query, r.cost_usd, r.duration_ms, r.status],
        onData: d => paintExternalSummary(d.summary),
    });
    gridInst.external.on('rowClick', (event, row) => openCost(row.cells[0].data));
}
function paintExternalSummary(s) {
    if (!s) return;
    setVal('ext-pplx-req', fmtInt(s.providers.perplexity.requests));
    setVal('ext-pplx-sub', `web ${fmtInt(s.perplexity_web)} · people ${fmtInt(s.perplexity_people)} · ${fmtUsdSmart(s.providers.perplexity.cost)}`);
    setVal('ext-brave-req', fmtInt(s.providers.brave.requests));
    setVal('ext-brave-sub', fmtUsdSmart(s.providers.brave.cost));
    setVal('ext-gp-req', fmtInt(s.providers.google_places.requests));
    setVal('ext-gp-sub', fmtUsdSmart(s.providers.google_places.cost));
    setVal('ext-total', fmtUsdSmart(s.total_cost));
    setVal('ext-total-sub', `${fmtInt(s.requests)} заявки`);
}

// ── Knowledge & embeddings ────────────────────────────────────────────
function mountKnowledge() {
    gridInst.knowledge = buildServerGrid({
        el: 'knowledgeGrid', section: 'knowledge', results: 'заявки',
        columns: [
            { name: 'ID', hidden: true },
            { name: 'Време', width: '150px' },
            { name: 'Дейност', width: '130px', sort: false, formatter: c => knowledgePurpose(c) },
            { name: 'Провайдър', width: '120px', sort: false, formatter: c => gridjs.html(providerBadge(c)) },
            { name: 'Модел', width: '150px', sort: false, formatter: c => c || '—' },
            { name: 'Фирма', width: '140px', sort: false, formatter: c => c || '—' },
            { name: 'Токени', width: '96px', formatter: c => Number(c || 0).toLocaleString('bg-BG') },
            { name: 'Разход', width: '96px', formatter: c => '$' + Number(c || 0).toFixed(6) },
            { name: 'Времетраене', width: '104px', formatter: c => c ? (c / 1000).toFixed(2) + ' сек.' : '—' },
            { name: 'Статус', width: '100px', sort: false, formatter: c => gridjs.html(statusBadge(c)) },
        ],
        sortNames: [null, 'created_at', null, null, null, null, 'total_tokens', 'cost_usd', 'duration_ms', null],
        map: r => [r.id, r.created_at, r.purpose, r.provider, r.model, r.company, r.tokens, r.cost_usd, r.duration_ms, r.status],
        onData: d => paintKnowledgeSummary(d.summary),
    });
    gridInst.knowledge.on('rowClick', (event, row) => openCost(row.cells[0].data));
}
function paintKnowledgeSummary(s) {
    if (!s) return;
    const P = s.purposes;
    setVal('kn-syn-req', fmtInt(P.knowledge_synthesis.requests));  setVal('kn-syn-cost', fmtUsdSmart(P.knowledge_synthesis.cost));
    setVal('kn-fact-req', fmtInt(P.knowledge_fact_harvest.requests)); setVal('kn-fact-cost', fmtUsdSmart(P.knowledge_fact_harvest.cost));
    setVal('kn-chat-req', fmtInt(P.knowledge_chat.requests)); setVal('kn-chat-cost', fmtUsdSmart(P.knowledge_chat.cost));
    setVal('kn-emb-req', fmtInt(P.embedding.requests)); setVal('kn-emb-cost', `${fmtInt(P.embedding.tokens)} ток. · ${fmtUsdSmart(P.embedding.cost)}`);
    setVal('kn-total', fmtUsdSmart(s.total_cost)); setVal('kn-total-sub', `${fmtInt(s.requests)} заявки`);
}

// ── Other / loose calls ───────────────────────────────────────────────
function mountOther() {
    gridInst.other = buildServerGrid({
        el: 'otherGrid', section: 'other', results: 'заявки',
        columns: [
            { name: 'ID', hidden: true },
            { name: 'Време', width: '150px' },
            { name: 'Дейност', width: '150px', sort: false, formatter: c => c || '—' },
            { name: 'Провайдър', width: '120px', sort: false, formatter: c => gridjs.html(providerBadge(c)) },
            { name: 'Модел', width: '150px', sort: false, formatter: c => c || '—' },
            { name: 'Фирма', width: '140px', sort: false, formatter: c => c || '—' },
            { name: 'Токени', width: '96px', formatter: c => Number(c || 0).toLocaleString('bg-BG') },
            { name: 'Разход', width: '96px', formatter: c => '$' + Number(c || 0).toFixed(6) },
            { name: 'Времетраене', width: '104px', formatter: c => c ? (c / 1000).toFixed(2) + ' сек.' : '—' },
            { name: 'Статус', width: '100px', sort: false, formatter: c => gridjs.html(statusBadge(c)) },
        ],
        sortNames: [null, 'created_at', null, null, null, null, 'total_tokens', 'cost_usd', 'duration_ms', null],
        map: r => [r.id, r.created_at, r.purpose, r.provider, r.model, r.company, r.tokens, r.cost_usd, r.duration_ms, r.status],
        onData: d => paintOtherSummary(d.summary),
    });
    gridInst.other.on('rowClick', (event, row) => openCost(row.cells[0].data));
}
function paintOtherSummary(s) {
    if (!s) return;
    setVal('oth-req', fmtInt(s.requests));
    setVal('oth-top', `най-чест: ${s.top_purpose}`);
    setVal('oth-tokens', fmtInt(s.tokens));
    setVal('oth-cost', fmtUsdSmart(s.total_cost));
}

// ── Mistral OCR ───────────────────────────────────────────────────────
const ocrDocCell = (doc, url) => url
    ? `<a href="${esc(url)}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline" title="Отвори оригинала">${esc(doc) || '—'}</a>`
    : (esc(doc) || '—');
function mountOcr() {
    gridInst.ocr = buildServerGrid({
        el: 'ocrGrid', section: 'ocr', results: 'документа',
        columns: [
            { name: 'ID', hidden: true },
            { name: 'Време', width: '150px' },
            { name: 'Документ', width: '320px', sort: false, formatter: c => gridjs.html(ocrDocCell(c[0], c[1])) },
            { name: 'Стр.', width: '70px', sort: false, formatter: c => c ? Number(c).toLocaleString() : '—' },
            { name: 'Разход', width: '96px', formatter: c => '$' + Number(c || 0).toFixed(4) },
            { name: 'Времетраене', width: '104px', formatter: c => c ? (c / 1000).toFixed(2) + ' сек.' : '—' },
            { name: 'Статус', width: '100px', sort: false, formatter: c => gridjs.html(statusBadge(c)) },
        ],
        sortNames: [null, 'created_at', null, null, 'cost_usd', 'duration_ms', null],
        map: r => [r.id, r.created_at, [r.document, r.original_url], r.pages, r.cost_usd, r.duration_ms, r.status],
        onData: d => paintOcrSummary(d.summary),
    });
    gridInst.ocr.on('rowClick', (event, row) => { if (event.target.closest('a')) return; openOcr(row.cells[0].data); });
}
function paintOcrSummary(s) {
    if (!s) return;
    setVal('ocr-docs', fmtInt(s.documents));
    setVal('ocr-pages', fmtInt(s.pages));
    setVal('ocr-cost', fmtUsdSmart(s.total_cost));
}

// ══ Drill-down popups (unchanged behaviour) ═══════════════════════════
let subGridInstance = null;
async function openGroup(groupKey, rowType, flowName) {
    const modal = document.getElementById('groupModal');
    const loading = document.getElementById('subGridLoading');
    const subGridEl = document.getElementById('subGrid');
    const titleEl = document.getElementById('gm-title');
    const badgeEl = document.getElementById('gm-type-badge');
    const metaEl = document.getElementById('gm-meta');

    loading.classList.remove('hidden');
    subGridEl.innerHTML = '';
    metaEl.innerHTML = '';
    metaEl.classList.add('hidden');
    if (subGridInstance) { try { subGridInstance.destroy(); } catch (e) {} subGridInstance = null; }
    titleEl.textContent = flowName || groupKey;
    badgeEl.innerHTML = typeBadge(rowType);
    modal.classList.remove('hidden');

    try {
        const resp = await fetch(`${GROUP_DETAIL_URL}?key=${encodeURIComponent(groupKey)}`, { headers: { Accept: 'application/json' } });
        if (!resp.ok) { loading.textContent = 'Грешка при зареждане.'; return; }
        const data = await resp.json();
        const rows = data.rows || [];
        if (data.meta) renderRunMeta(metaEl, data.meta);
        loading.classList.add('hidden');
        if (!rows.length) { subGridEl.innerHTML = '<p class="text-gray-400 text-sm py-4 text-center">Няма заявки.</p>'; return; }

        subGridInstance = new gridjs.Grid({
            data: rows.map(r => [r.id, r.created_at, r.provider, r.model, r.purpose, r.agent, r.prompt_tokens, r.completion_tokens, r.cost_usd, r.duration_ms, r.status]),
            columns: [
                { name: '#', width: '50px' },
                { name: 'Час', width: '148px' },
                { name: 'Провайдър', width: '120px', formatter: c => gridjs.html(providerBadge(c)) },
                { name: 'Модел', width: '170px', formatter: c => c || '—' },
                { name: 'Цел', width: '130px', formatter: c => c || '—' },
                { name: 'Агент', width: '150px', formatter: c => c || '—' },
                { name: 'Вх. ток.', width: '108px', formatter: c => c ? Number(c).toLocaleString() : '—' },
                { name: 'Изх. ток.', width: '115px', formatter: c => c ? Number(c).toLocaleString() : '—' },
                { name: 'Цена', width: '80px', formatter: c => '$' + Number(c || 0).toFixed(2) },
                { name: 'Сек.', width: '75px', formatter: c => c ? (Number(c) / 1000).toFixed(1) : '—' },
                { name: 'Статус', width: '100px', formatter: c => gridjs.html(statusBadge(c)) },
            ],
            sort: true,
            style: { table: { 'font-size': '12px' }, th: { 'white-space': 'nowrap' } },
        });
        subGridInstance.render(subGridEl);
        subGridInstance.on('rowClick', (event, row) => openCost(row.cells[0].data));
    } catch (e) { console.error(e); loading.textContent = 'Мрежова грешка.'; }
}
function renderRunMeta(el, meta) {
    const card = (label, value) => `<div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-400">${label}</p><p class="font-semibold text-gray-900 text-sm">${value ?? '—'}</p></div>`;
    const secs = meta.duration_ms ? (meta.duration_ms / 1000).toFixed(1) + ' сек.' : '—';
    el.innerHTML = card('Flow', meta.flow || ('run #' + meta.flow_run_id)) + card('Статус', statusBadge(meta.status)) +
        card('Агенти', meta.agents) + card('Обща цена', '$' + Number(meta.cost_usd || 0).toFixed(2)) +
        card('Токени', Number(meta.tokens || 0).toLocaleString()) + card('Времетраене', secs) +
        card('Начало', meta.started_at) + card('Край', meta.completed_at);
    el.classList.remove('hidden');
}
function closeGroupModal() { document.getElementById('groupModal').classList.add('hidden'); }
document.getElementById('groupModalClose').addEventListener('click', closeGroupModal);
document.getElementById('groupModalOverlay').addEventListener('click', closeGroupModal);

// Detail popup (full prompt + response)
const costModal = document.getElementById('costModal');
const setText = (id, val) => { document.getElementById(id).textContent = (val ?? '') === '' ? '—' : val; };
async function openCost(id) {
    try {
        const resp = await fetch(`${DETAIL_URL}?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
        if (!resp.ok) { alert('Грешка при зареждане.'); return; }
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
        setText('m-cost', '$' + Number(d.cost_usd || 0).toFixed(6));
        setText('m-dur', d.duration_ms != null ? (d.duration_ms / 1000).toFixed(2) + ' сек.' : '—');
        const errWrap = document.getElementById('m-error-wrap');
        if (d.error) { document.getElementById('m-error').textContent = d.error; errWrap.classList.remove('hidden'); } else { errWrap.classList.add('hidden'); }
        const optWrap = document.getElementById('m-options-wrap');
        if (d.options && Object.keys(d.options).length) { document.getElementById('m-options').textContent = JSON.stringify(d.options, null, 2); optWrap.classList.remove('hidden'); } else { optWrap.classList.add('hidden'); }
        setText('m-system', d.system_prompt);
        setText('m-user', d.user_message);
        setText('m-response', d.response_text);
        costModal.classList.remove('hidden');
    } catch (e) { console.error(e); alert('Мрежова грешка.'); }
}
document.getElementById('costModalClose').addEventListener('click', () => costModal.classList.add('hidden'));
document.getElementById('costModalOverlay').addEventListener('click', () => costModal.classList.add('hidden'));

// OCR preview popup
const ocrModal = document.getElementById('ocrModal');
function ocrSwitchTab(tab) {
    document.querySelectorAll('.ocr-tab').forEach(b => {
        const on = b.dataset.tab === tab;
        b.classList.toggle('border-indigo-500', on);
        b.classList.toggle('text-indigo-600', on);
        b.classList.toggle('font-medium', on);
        b.classList.toggle('border-transparent', !on);
        b.classList.toggle('text-gray-500', !on);
    });
    document.getElementById('ocr-raw').classList.toggle('hidden', tab !== 'raw');
    document.getElementById('ocr-digest').classList.toggle('hidden', tab !== 'digest');
}
document.querySelectorAll('.ocr-tab').forEach(b => b.addEventListener('click', () => ocrSwitchTab(b.dataset.tab)));
async function openOcr(id) {
    try {
        const resp = await fetch(`${OCR_DETAIL_URL}?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
        if (!resp.ok) { alert('Грешка при зареждане.'); return; }
        const d = await resp.json();
        setText('ocr-doc', d.document);
        setText('ocr-meta', [d.created_at, d.pages ? d.pages + ' стр.' : '', '$' + Number(d.cost_usd || 0).toFixed(6)].filter(Boolean).join(' · '));
        const orig = document.getElementById('ocr-original');
        if (d.original_url) { orig.href = d.original_url; orig.classList.remove('hidden'); } else { orig.classList.add('hidden'); }
        document.getElementById('ocr-raw').textContent = d.raw_text || '(няма запазен сканиран текст)';
        document.getElementById('ocr-digest').textContent = d.digest || '(няма синтезиран digest — документът може да не е свързан с ресурс)';
        ocrSwitchTab('raw');
        ocrModal.classList.remove('hidden');
    } catch (e) { console.error(e); alert('Мрежова грешка.'); }
}
document.getElementById('ocrModalClose').addEventListener('click', () => ocrModal.classList.add('hidden'));
document.getElementById('ocrModalOverlay').addEventListener('click', () => ocrModal.classList.add('hidden'));

// Chat transcript modal
async function openChat(sessionId, title) {
    const modal = document.getElementById('chatModal');
    const messagesEl = document.getElementById('cm-messages');
    const metaEl = document.getElementById('cm-meta');
    const titleEl = document.getElementById('cm-title');
    messagesEl.innerHTML = '<p class="text-center text-gray-400 text-sm py-8">Зареждане…</p>';
    metaEl.innerHTML = '';
    titleEl.textContent = title || sessionId;
    modal.classList.remove('hidden');
    try {
        const resp = await fetch(`${CHAT_DETAIL_URL}?session=${encodeURIComponent(sessionId)}`, { headers: { Accept: 'application/json' } });
        if (!resp.ok) { messagesEl.innerHTML = '<p class="text-red-500 text-sm text-center py-4">Грешка при зареждане.</p>'; return; }
        const data = await resp.json();
        const card = (label, value) => `<div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-400">${label}</p><p class="font-semibold text-gray-900 text-sm">${value ?? '—'}</p></div>`;
        metaEl.innerHTML = card('Flow', data.meta.flow) + card('Фирма', data.meta.company) + card('Модел', data.meta.models || '—') +
            card('Токени', Number(data.meta.total_tokens || 0).toLocaleString()) + card('Цена', '$' + Number(data.meta.cost_usd || 0).toFixed(4)) + card('LLM заявки', data.meta.call_count);
        titleEl.textContent = data.meta.flow || title || sessionId;
        if (!data.messages || !data.messages.length) { messagesEl.innerHTML = '<p class="text-center text-gray-400 text-sm py-4">Няма съобщения.</p>'; return; }
        messagesEl.innerHTML = data.messages.map(m => {
            const isUser = m.role === 'user';
            const bg = isUser ? 'background:#f0f9ff;border:1px solid #bae6fd;' : 'background:#f9fafb;border:1px solid #e5e7eb;';
            const roleBadge = isUser
                ? '<span style="background:#0ea5e9;color:#fff;padding:1px 8px;border-radius:9999px;font-size:11px;font-weight:600;">Потребител</span>'
                : '<span style="background:#fef9c3;color:#854d0e;padding:1px 8px;border-radius:9999px;font-size:11px;font-weight:600;">Асистент</span>';
            const costStr = (!isUser && m.cost_usd != null) ? `<span style="font-size:11px;color:#9ca3af;margin-left:6px;">$${Number(m.cost_usd).toFixed(4)}</span>` : '';
            const timeStr = m.created_at ? `<span style="font-size:11px;color:#9ca3af;margin-left:6px;">${m.created_at}</span>` : '';
            const opsBadge = m.has_ops ? '<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:9999px;font-size:10px;margin-left:4px;">+граф промени</span>' : '';
            const errHtml = m.error ? `<p style="color:#b91c1c;font-size:12px;margin-top:6px;font-style:italic;">${esc(m.error)}</p>` : '';
            const content = m.content ? esc(m.content) : '<em style="color:#9ca3af">—</em>';
            return `<div style="${bg}border-radius:10px;padding:12px 14px;">
                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-bottom:8px;">${roleBadge}${costStr}${timeStr}${opsBadge}</div>
                        <p style="font-size:13px;color:#1f2937;white-space:pre-wrap;word-break:break-word;margin:0;">${content}</p>${errHtml}
                    </div>`;
        }).join('');
    } catch (e) { console.error(e); messagesEl.innerHTML = '<p class="text-red-500 text-sm text-center py-4">Мрежова грешка.</p>'; }
}
function closeChatModal() { document.getElementById('chatModal').classList.add('hidden'); }
document.getElementById('chatModalClose').addEventListener('click', closeChatModal);
document.getElementById('chatModalOverlay').addEventListener('click', closeChatModal);

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const chatMod = document.getElementById('chatModal');
    if (!ocrModal.classList.contains('hidden')) { ocrModal.classList.add('hidden'); }
    else if (!chatMod.classList.contains('hidden')) { closeChatModal(); }
    else if (!costModal.classList.contains('hidden')) { costModal.classList.add('hidden'); }
    else { closeGroupModal(); }
});

// ── Boot ──────────────────────────────────────────────────────────────
showTab('overview');
</script>
@endsection
