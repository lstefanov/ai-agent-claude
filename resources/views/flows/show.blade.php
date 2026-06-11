@extends('layouts.app')

@section('title', $flow->name)

@php
$langFlag = ['bg' => '🇧🇬', 'en' => '🇬🇧', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸', 'ru' => '🇷🇺'];
$qaThresholdOptions = range(0, 100, 5);
@endphp

{{-- Clear the create-form draft for this company on successful save --}}
<script>sessionStorage.removeItem('flowai_draft_{{ $flow->company_id }}');</script>

@section('content')
{{-- Header --}}
<div class="mb-6">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('companies.show', $flow->company) }}" class="text-indigo-600 hover:underline text-sm inline-flex items-center gap-1">
                ← {{ $flow->company->name }}
            </a>
            <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3 flex-wrap">
                {{ $flow->name }}
                @include('partials.status-badge', ['status' => $flow->status, 'class' => 'text-sm px-3'])
            </h1>
        </div>
        <div class="flex items-start gap-2 shrink-0">
            <a href="{{ route('flows.edit', $flow) }}"
               class="inline-flex items-center justify-center bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                ✏ Редактирай
            </a>
            <a href="{{ route('flows.builder', $flow) }}"
               class="inline-flex items-center justify-center bg-white border border-indigo-300 hover:border-indigo-400 text-indigo-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                ⛓ Граф редактор
            </a>
            <a href="{{ route('flows.plan-ab', $flow) }}"
               class="inline-flex items-center justify-center bg-white border border-violet-300 hover:border-violet-400 text-violet-700 px-4 py-2 rounded-lg text-sm font-medium transition"
               title="Планирай с Ollama (безплатно), OpenAI и Anthropic и избери по-добрия план">
                ⚖ A/B план
            </a>
            <form action="{{ route('flow-runs.store', $flow) }}" method="POST" class="flex items-start gap-2">
                @csrf
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-bold transition flex items-center gap-2">
                    ▶ Стартирай
                </button>
            </form>
        </div>
    </div>
    <p class="text-gray-500 mt-1">{!! nl2br(e($flow->description)) !!}</p>
</div>

{{-- Schedule info --}}
@if($flow->schedule_cron)
<div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-3 mb-6 flex items-center gap-3 text-sm text-blue-800">
    <span class="text-lg">📅</span>
    <div>
        <span class="font-medium">Cron разписание:</span>
        <code class="font-mono bg-blue-100 px-2 py-0.5 rounded ml-1 text-xs">{{ $flow->schedule_cron }}</code>
        @if($flow->last_run_at)
            <span class="text-blue-500 ml-3">Последно: {{ $flow->last_run_at->diffForHumans() }}</span>
        @endif
    </div>
</div>
@endif

{{-- Webhook / n8n Integration Panel --}}
@if(false)
<div class="bg-white rounded-xl border border-gray-200 mb-6 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
            <span>🔗</span> Webhook / n8n интеграция
        </h2>
        @if($flow->webhook_secret)
            <span class="inline-flex items-center gap-1.5 text-xs bg-green-100 text-green-700 px-2.5 py-1 rounded-full font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span> Активен
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 text-xs bg-gray-100 text-gray-500 px-2.5 py-1 rounded-full font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-gray-400 inline-block"></span> Неактивен
            </span>
        @endif
    </div>

    <div class="px-6 py-5">
        @if($flow->webhook_secret)
            <p class="text-sm text-gray-500 mb-4">
                Изпрати <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">POST</code> заявка към URL-а по-долу, за да стартираш flow-а от n8n, Zapier, Make или друга платформа. Добави JSON тяло с произволни данни — те ще бъдат достъпни като <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">&#123;&#123;webhook_payload&#125;&#125;</code> в промптовете на агентите.
            </p>

            {{-- Webhook URL --}}
            @php
                $webhookUrl = url('/api/webhook/flows/' . $flow->id . '/run') . '?token=' . $flow->webhook_secret;
            @endphp
            <div class="flex items-center gap-2 mb-4">
                <input type="text"
                       id="webhookUrlInput"
                       value="{{ $webhookUrl }}"
                       readonly
                       class="flex-1 font-mono text-xs bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-gray-700 select-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-300"
                       onclick="this.select()" />
                <button onclick="copyWebhookUrl()"
                        class="shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium px-3 py-2.5 rounded-lg transition">
                    Копирай
                </button>
            </div>

            {{-- n8n example hint --}}
            <details class="mb-4">
                <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700 select-none">
                    Как да настроиш в n8n?
                </summary>
                <div class="mt-3 bg-gray-50 rounded-lg p-4 text-xs text-gray-600 space-y-2 border border-gray-100">
                    <p><strong>Вариант 1 — n8n изпраща към FlowAI (trigger):</strong></p>
                    <ol class="list-decimal list-inside space-y-1 ml-1">
                        <li>В n8n добави нов нод <strong>HTTP Request</strong></li>
                        <li>Метод: <code class="bg-white px-1 rounded border border-gray-200">POST</code> | URL: горният webhook адрес</li>
                        <li>Body: JSON с данните, които искаш да подадеш на агентите</li>
                        <li>Свържи нода след trigger (Gmail, Google Drive, Schedule и др.)</li>
                    </ol>
                    <p class="pt-1"><strong>Вариант 2 — FlowAI изпраща към n8n (action):</strong></p>
                    <ol class="list-decimal list-inside space-y-1 ml-1">
                        <li>В n8n добави нод <strong>Webhook</strong> и копирай неговия URL</li>
                        <li>В FlowAI добави агент тип <strong>webhook_sender</strong> като последен агент</li>
                        <li>В конфигурацията на агента постави URL-а на n8n webhook</li>
                        <li>n8n получава резултата и го разпраща: Gmail, Sheets, Twitter и т.н.</li>
                    </ol>
                </div>
            </details>

            {{-- Revoke button --}}
            <form action="{{ route('flows.webhook.revoke', $flow) }}" method="POST"
                  onsubmit="return confirm('Деактивирай webhook? Всички n8n connections с текущия URL ще спрат да работят.')">
                @csrf
                <button type="submit"
                        class="text-xs text-red-500 hover:text-red-700 underline transition">
                    Деактивирай webhook URL
                </button>
            </form>
        @else
            <p class="text-sm text-gray-500 mb-4">
                Генерирай webhook URL, за да можеш да стартираш този flow автоматично от <strong>n8n</strong>, Zapier, Make или друга платформа — при нов email, файл в Google Drive, Facebook коментар и т.н.
            </p>
            <form action="{{ route('flows.webhook.generate', $flow) }}" method="POST">
                @csrf
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
                    🔗 Генерирай Webhook URL
                </button>
            </form>
        @endif
    </div>
</div>
@endif

{{-- Result delivery panel --}}
@if(false)
@php $delivery = $flow->settings['delivery'] ?? []; @endphp
<div class="bg-white rounded-xl border border-gray-200 p-6 mt-6"
     x-data="{ channel: '{{ $delivery['channel'] ?? 'none' }}' }">
    <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2 mb-1">
        <span>📤</span> Доставка на резултата
    </h2>
    <p class="text-sm text-gray-500 mb-4">След успешен run финалният изход се изпраща автоматично към избрания канал.</p>

    <form action="{{ route('flows.settings.update', $flow) }}" method="POST" class="space-y-4">
        @csrf
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Канал</label>
                <select name="delivery_channel" x-model="channel"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="none">— без доставка —</option>
                    <option value="email">Email</option>
                    <option value="slack">Slack (webhook)</option>
                    <option value="webhook">Webhook (HTTP POST)</option>
                    <option value="file">Файл (storage/app/deliveries)</option>
                </select>
            </div>
            <div x-show="channel !== 'none' && channel !== 'file'" x-cloak>
                <label class="block text-sm font-medium text-gray-700 mb-1"
                       x-text="channel === 'email' ? 'Email адрес' : 'Webhook URL'"></label>
                <input type="text" name="delivery_target" value="{{ $delivery['target'] ?? '' }}"
                       :placeholder="channel === 'email' ? 'name@example.com' : 'https://...'"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>
        <div x-show="channel === 'email'" x-cloak>
            <label class="block text-sm font-medium text-gray-700 mb-1">Тема на имейла (по избор)</label>
            <input type="text" name="delivery_subject" value="{{ $delivery['subject'] ?? '' }}"
                   placeholder="Резултат от flow: {{ $flow->name }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <button type="submit"
                class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
            Запази доставката
        </button>
    </form>
</div>
@endif

<script>
function copyWebhookUrl() {
    const input = document.getElementById('webhookUrlInput');
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = event.target;
        const orig = btn.textContent;
        btn.textContent = 'Копирано ✓';
        btn.classList.replace('bg-indigo-600', 'bg-green-600');
        btn.classList.replace('hover:bg-indigo-700', 'hover:bg-green-700');
        setTimeout(() => {
            btn.textContent = orig;
            btn.classList.replace('bg-green-600', 'bg-indigo-600');
            btn.classList.replace('hover:bg-green-700', 'hover:bg-indigo-700');
        }, 2000);
    });
}
</script>

{{-- Шаблони (граф версии) --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mt-6 mb-6"
     x-data="flowVersionsPanel(@js(csrf_token()))">
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Шаблони</h2>
            <p class="text-xs text-gray-400 mt-0.5">Граф версии на този flow — активният (●) се изпълнява при Run.</p>
        </div>
        <a href="{{ route('flows.builder', $flow) }}?new_template=1"
           class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-3 py-1.5 rounded-lg transition">
            ＋ Нов шаблон
        </a>
    </div>

    <template x-if="error">
        <div class="px-6 py-2 bg-red-50 text-red-700 text-sm border-b border-red-100" x-text="error"></div>
    </template>

    @if($versions->isEmpty())
        <div class="px-6 py-8 text-center text-sm text-gray-400">
            Все още няма шаблони — отвори граф редактора и запази графа, или генерирай нов план.
        </div>
    @else
        <div class="divide-y divide-gray-50">
            @foreach($versions as $version)
                <div class="px-6 py-3 flex items-center justify-between gap-3 hover:bg-gray-50/60 transition">
                    <div class="flex flex-1 items-start gap-3 min-w-0 overflow-hidden">
                        @if($version->is_active)
                            <span class="shrink-0 w-[80px] mt-0.5 inline-flex items-center justify-center text-[11px] font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700 border border-green-200">активен</span>
                        @else
                            <span class="shrink-0 w-[80px]"></span>
                        @endif
                        <div class="min-w-0 flex flex-col gap-0.5">
                            <div class="text-sm font-semibold text-gray-900 truncate">{{ $version->name }}</div>
                            <div class="text-xs text-gray-400 space-y-0.5">
                                <div title="Създаден на">Създаден: {{ $version->created_at->format('d.m.Y H:i') }}</div>
                                <div title="Последен run">Последен run: {{ $version->last_run_at ? $version->last_run_at->format('d.m.Y H:i') : 'няма' }}</div>
                                <div title="Общ разход: генерация + run-ове" class="text-amber-600">Разход: ${{ number_format($version->total_cost_usd ?? 0, 6) }}</div>
                                <div>
                                    <span title="Успешни run-ове" class="text-green-600">Успешни: {{ $version->successful_runs_count }}</span>
                                    <span class="mx-1.5">·</span>
                                    <span title="Неуспешни run-ове" class="text-red-500">Неуспешни: {{ $version->failed_runs_count }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 text-xs">
                        <form method="POST"
                              action="{{ route('flow-runs.store', $flow) }}"
                              target="_blank"
                              class="contents">
                            @csrf
                            <input type="hidden" name="version_id" value="{{ $version->id }}">
                            <button type="submit"
                                    class="h-8 inline-flex items-center justify-center px-2.5 rounded-lg border border-emerald-300 text-emerald-700 hover:bg-emerald-50 font-medium leading-none">
                                ▶ Стартирай
                            </button>
                        </form>
                        @unless($version->is_active)
                            <button type="button"
                                    @click="activate(@js(route('flows.versions.activate', [$flow, $version])))"
                                    class="h-8 inline-flex items-center justify-center px-2.5 rounded-lg border border-green-300 text-green-700 hover:bg-green-50 font-medium leading-none">
                                Активирай
                            </button>
                        @endunless
                        <button type="button"
                                @click="rename(@js(route('flows.versions.update', [$flow, $version])), @js($version->name))"
                                class="h-8 inline-flex items-center justify-center px-2.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 leading-none">
                            Преименувай
                        </button>
                        <button type="button"
                                @click="duplicate(@js(route('flows.versions.duplicate', [$flow, $version])), @js($version->name))"
                                class="h-8 inline-flex items-center justify-center px-2.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 leading-none">
                            Дублирай
                        </button>
                        <a href="{{ route('flows.builder', $flow) }}?version={{ $version->id }}"
                           class="h-8 inline-flex items-center justify-center px-2.5 rounded-lg border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-medium leading-none">
                            ✎ Редактирай
                        </a>
                        @if($version->is_active)
                            <button type="button" disabled title="Активният шаблон не може да бъде изтрит — първо активирай друг."
                                    class="h-8 inline-flex items-center justify-center px-2.5 rounded-lg border border-gray-200 text-gray-300 cursor-not-allowed leading-none">
                                Изтрий
                            </button>
                        @else
                            <button type="button"
                                    @click="destroy(@js(route('flows.versions.destroy', [$flow, $version])), @js($version->name))"
                                    class="h-8 inline-flex items-center justify-center px-2.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 leading-none">
                                Изтрий
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
function flowVersionsPanel(csrf) {
    const send = async (url, method, body = null) => {
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : null,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
            throw new Error(data.error || Object.values(data.errors || {}).flat().join(' ') || 'Грешка при заявката.');
        }
        return data;
    };

    return {
        error: null,
        async activate(url) {
            try { await send(url, 'POST'); window.location.reload(); }
            catch (e) { this.error = e.message; }
        },
        async rename(url, current) {
            const name = prompt('Ново име на шаблона:', current);
            if (!name || name.trim() === '' || name === current) return;
            try { await send(url, 'PUT', { name: name.trim() }); window.location.reload(); }
            catch (e) { this.error = e.message; }
        },
        async duplicate(url, name) {
            const newName = prompt('Дублиране на „' + name + '" — въведи ново име:', 'Копие на ' + name);
            if (!newName || newName.trim() === '') return;
            try { await send(url, 'POST', { name: newName.trim() }); window.location.reload(); }
            catch (e) { this.error = e.message; }
        },
        async destroy(url, name) {
            if (!confirm('Изтриване на шаблона „' + name + '“? Действието е необратимо.')) return;
            try { await send(url, 'DELETE'); window.location.reload(); }
            catch (e) { this.error = e.message; }
        },
    };
}
</script>

{{-- Run History DataTable --}}
<div x-data="runsHistory(@js(route('flows.runs-history', $flow)), @js($versions->map(fn($v) => ['id' => $v->id, 'name' => $v->name])))"
     x-init="load()"
     class="bg-white rounded-xl border border-gray-200 overflow-hidden">

    {{-- Header --}}
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between gap-4 flex-wrap">
        <h2 class="text-base font-semibold text-gray-900">История на изпълненията</h2>
        <span x-show="meta.total !== null" class="text-xs text-gray-400" x-text="'Общо: ' + meta.total"></span>
    </div>

    {{-- Filters --}}
    <div class="px-6 py-3 border-b border-gray-100 flex flex-wrap items-end gap-3">
        {{-- Status --}}
        <div class="flex flex-col gap-1">
            <label class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Статус</label>
            <select x-model="filters.status" @change="reset()" class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-300">
                <option value="">Всички</option>
                <option value="completed">Успешен</option>
                <option value="failed">Неуспешен</option>
                <option value="running">В момента</option>
                <option value="pending">Чакащ</option>
            </select>
        </div>

        {{-- Triggered by --}}
        <div class="flex flex-col gap-1">
            <label class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Задействан от</label>
            <select x-model="filters.triggered_by" @change="reset()" class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-300">
                <option value="">Всички</option>
                <option value="manual">▶ Ръчно</option>
                <option value="scheduler">⏰ Планиран</option>
            </select>
        </div>

        {{-- Version --}}
        <template x-if="versions.length > 0">
            <div class="flex flex-col gap-1">
                <label class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Шаблон</label>
                <select x-model="filters.version_id" @change="reset()" class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-300">
                    <option value="">Всички</option>
                    <template x-for="v in versions" :key="v.id">
                        <option :value="v.id" x-text="v.name"></option>
                    </template>
                </select>
            </div>
        </template>

        {{-- Date from --}}
        <div class="flex flex-col gap-1">
            <label class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Дата от</label>
            <input type="date" x-model="filters.date_from" @change="reset()"
                   class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-300">
        </div>

        {{-- Date to --}}
        <div class="flex flex-col gap-1">
            <label class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Дата до</label>
            <input type="date" x-model="filters.date_to" @change="reset()"
                   class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-300">
        </div>

        {{-- Per page --}}
        <div class="flex flex-col gap-1">
            <label class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">На страница</label>
            <select x-model.number="perPage" @change="reset()" class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-300">
                <option value="10">10</option>
                <option value="15">15</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="0">Всички</option>
            </select>
        </div>

        {{-- Reset --}}
        <button type="button" @click="clearFilters()"
                x-show="hasActiveFilters()"
                class="text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg px-3 py-1.5 bg-white hover:bg-gray-50 transition self-end">
            ✕ Изчисти
        </button>
    </div>

    {{-- Loading skeleton --}}
    <template x-if="loading">
        <div class="divide-y divide-gray-50">
            <template x-for="i in [1,2,3,4,5]" :key="i">
                <div class="px-6 py-3 flex items-center justify-between gap-3 animate-pulse">
                    <div class="flex items-center gap-3">
                        <div class="w-2 h-2 rounded-full bg-gray-200"></div>
                        <div class="h-3 w-16 bg-gray-200 rounded"></div>
                        <div class="h-3 w-12 bg-gray-200 rounded"></div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="h-3 w-24 bg-gray-200 rounded"></div>
                        <div class="h-3 w-16 bg-gray-200 rounded"></div>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- Empty state --}}
    <template x-if="!loading && rows.length === 0">
        <div class="px-6 py-10 text-center">
            <p class="text-gray-400 text-sm mb-3" x-text="hasActiveFilters() ? 'Няма резултати за избраните филтри' : 'Все още няма изпълнения'"></p>
            <p x-show="!hasActiveFilters()" class="text-xs text-gray-300">Натисни ▶ Стартирай за първото изпълнение</p>
        </div>
    </template>

    {{-- Rows --}}
    <template x-if="!loading && rows.length > 0">
        <div class="divide-y divide-gray-50">
            <template x-for="run in rows" :key="run.id">
                <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50/60 transition gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-wrap">
                        {{-- Status dot --}}
                        <span :class="{
                            'w-2 h-2 rounded-full shrink-0': true,
                            'bg-green-500': run.status === 'completed',
                            'bg-red-500':   run.status === 'failed',
                            'bg-blue-500 animate-pulse': run.status === 'running',
                            'bg-violet-500 animate-pulse': run.status === 'waiting_approval',
                            'bg-gray-300':  run.status === 'pending',
                        }"></span>
                        {{-- Status badge --}}
                        <span :class="{
                            'inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium': true,
                            'bg-green-100 text-green-700': run.status === 'completed',
                            'bg-red-100 text-red-700':     run.status === 'failed',
                            'bg-blue-100 text-blue-700':   run.status === 'running',
                            'bg-violet-100 text-violet-700': run.status === 'waiting_approval',
                            'bg-gray-100 text-gray-500':   run.status === 'pending',
                        }" x-text="{completed:'Завършен',failed:'Неуспешен',running:'Работи',pending:'Чакащ',waiting_approval:'✋ Чака одобрение'}[run.status] ?? run.status"></span>
                        {{-- Triggered by --}}
                        <span class="text-xs text-gray-400" x-text="{'manual':'▶ Ръчно','scheduler':'⏰ Планиран'}[run.triggered_by] ?? run.triggered_by"></span>
                        {{-- Version pill --}}
                        <template x-if="run.version_name">
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600 border border-indigo-100"
                                  x-text="run.version_name" title="Шаблон"></span>
                        </template>
                    </div>

                    <div class="flex items-center gap-4 shrink-0">
                        <template x-if="run.started_at">
                            <span class="text-xs text-gray-400" x-text="run.started_at"></span>
                        </template>
                        <template x-if="run.duration_secs !== null">
                            <span class="text-xs text-gray-400 tabular-nums"
                                  x-text="run.duration_secs >= 60 ? Math.floor(run.duration_secs/60)+'м '+(run.duration_secs%60)+'с' : run.duration_secs+'с'"></span>
                        </template>
                        <template x-if="run.cost_usd">
                            <span class="text-xs text-amber-600 tabular-nums font-medium" x-text="'$' + run.cost_usd"></span>
                        </template>
                        <a :href="run.builder_url" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Детайли →</a>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- Pagination --}}
    <template x-if="!loading && meta.last_page > 1">
        <div class="px-6 py-3 border-t border-gray-100 flex items-center justify-between gap-4">
            <span class="text-xs text-gray-400"
                  x-text="'Страница ' + meta.current_page + ' от ' + meta.last_page + ' (' + meta.total + ' общо)'"></span>
            <div class="flex items-center gap-1">
                <button @click="goTo(meta.current_page - 1)" :disabled="meta.current_page <= 1"
                        class="px-2.5 py-1 text-xs border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition">
                    ← Назад
                </button>
                <template x-for="p in pageNumbers()" :key="p">
                    <button @click="p !== '…' && goTo(p)"
                            :class="{
                                'px-2.5 py-1 text-xs border rounded-lg transition': true,
                                'bg-indigo-600 border-indigo-600 text-white': p === meta.current_page,
                                'border-gray-200 text-gray-600 hover:bg-gray-50': p !== meta.current_page && p !== '…',
                                'border-transparent text-gray-400 cursor-default': p === '…',
                            }"
                            x-text="p"></button>
                </template>
                <button @click="goTo(meta.current_page + 1)" :disabled="meta.current_page >= meta.last_page"
                        class="px-2.5 py-1 text-xs border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition">
                    Напред →
                </button>
            </div>
        </div>
    </template>
</div>

<script>
function runsHistory(url, versions) {
    return {
        url,
        versions,
        loading: true,
        rows: [],
        meta: { current_page: 1, last_page: 1, total: null, per_page: 15 },
        perPage: 15,
        filters: { status: '', triggered_by: '', version_id: '', date_from: '', date_to: '' },

        async load() {
            this.loading = true;
            const params = new URLSearchParams({ page: this.meta.current_page, per_page: this.perPage });
            Object.entries(this.filters).forEach(([k, v]) => v && params.set(k, v));
            try {
                const res = await fetch(this.url + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                });
                const json = await res.json();
                this.rows = json.data;
                this.meta  = json.meta;
            } catch (e) {
                console.error('runsHistory fetch error', e);
            } finally {
                this.loading = false;
            }
        },

        reset() {
            this.meta.current_page = 1;
            this.load();
        },

        goTo(page) {
            if (page < 1 || page > this.meta.last_page) return;
            this.meta.current_page = page;
            this.load();
        },

        clearFilters() {
            this.filters = { status: '', triggered_by: '', version_id: '', date_from: '', date_to: '' };
            this.perPage = 15;
            this.reset();
        },

        hasActiveFilters() {
            return Object.values(this.filters).some(v => v !== '') || this.perPage !== 15;
        },

        pageNumbers() {
            const { current_page: cur, last_page: last } = this.meta;
            if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
            const pages = new Set([1, last, cur, cur - 1, cur + 1].filter(p => p >= 1 && p <= last));
            const sorted = [...pages].sort((a, b) => a - b);
            const result = [];
            let prev = null;
            for (const p of sorted) {
                if (prev !== null && p - prev > 1) result.push('…');
                result.push(p);
                prev = p;
            }
            return result;
        },
    };
}
</script>
@endsection
