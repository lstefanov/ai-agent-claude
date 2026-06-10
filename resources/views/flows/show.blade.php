@extends('layouts.app')

@section('title', $flow->name)

@php
$triggeredByLabel = ['manual' => '▶ Ръчно', 'scheduler' => '⏰ Планиран'];
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

{{-- Result delivery panel --}}
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
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6"
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
                <div class="px-6 py-3 flex flex-wrap items-center justify-between gap-3 hover:bg-gray-50/60 transition">
                    <div class="flex items-center gap-3 min-w-0">
                        @if($version->is_active)
                            <span class="shrink-0 text-[11px] font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700 border border-green-200">● активен</span>
                        @else
                            <span class="shrink-0 w-[64px]"></span>
                        @endif
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-900 truncate">{{ $version->name }}</div>
                            <div class="text-xs text-gray-400 truncate">
                                <span title="Провайдър/модел на генерацията">⚙ {{ $version->generatorLabel() }}</span>
                                <span class="mx-1.5">·</span>
                                <span title="Генериран на">{{ $version->created_at->format('d.m.Y H:i') }}</span>
                                @if($version->cost_usd > 0)
                                    <span class="mx-1.5">·</span>
                                    <span class="text-amber-600">${{ number_format($version->cost_usd, 4) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        @unless($version->is_active)
                            <button type="button"
                                    @click="activate(@js(route('flows.versions.activate', [$flow, $version])))"
                                    class="px-2.5 py-1.5 rounded-lg border border-green-300 text-green-700 hover:bg-green-50 font-medium">
                                Активирай
                            </button>
                        @endunless
                        <button type="button"
                                @click="rename(@js(route('flows.versions.update', [$flow, $version])), @js($version->name))"
                                class="px-2.5 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50">
                            Преименувай
                        </button>
                        <a href="{{ route('flows.builder', $flow) }}?version={{ $version->id }}"
                           class="px-2.5 py-1.5 rounded-lg border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-medium">
                            ✎ Редактирай
                        </a>
                        @if($version->is_active)
                            <button type="button" disabled title="Активният шаблон не може да бъде изтрит — първо активирай друг."
                                    class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-gray-300 cursor-not-allowed">
                                Изтрий
                            </button>
                        @else
                            <button type="button"
                                    @click="destroy(@js(route('flows.versions.destroy', [$flow, $version])), @js($version->name))"
                                    class="px-2.5 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50">
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
        async destroy(url, name) {
            if (!confirm('Изтриване на шаблона „' + name + '“? Действието е необратимо.')) return;
            try { await send(url, 'DELETE'); window.location.reload(); }
            catch (e) { this.error = e.message; }
        },
    };
}
</script>

{{-- Run History --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900">История на изпълненията</h2>
        @if($runs->isNotEmpty())
            <span class="text-xs text-gray-400">Последни {{ $runs->count() }}</span>
        @endif
    </div>

    @if($runs->isEmpty())
        <div class="px-6 py-10 text-center">
            <p class="text-gray-400 text-sm mb-3">Все още няма изпълнения</p>
            <p class="text-xs text-gray-300">Натисни ▶ Стартирай за първото изпълнение</p>
        </div>
    @else
        <div class="divide-y divide-gray-50">
            @foreach($runs as $run)
            <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50/60 transition">
                <div class="flex items-center gap-3">
                    {{-- Status dot --}}
                    <span @class([
                        'w-2 h-2 rounded-full shrink-0',
                        'bg-green-500'                   => $run->status === 'completed',
                        'bg-red-500'                     => $run->status === 'failed',
                        'bg-blue-500 animate-pulse'      => $run->status === 'running',
                        'bg-gray-300'                    => $run->status === 'pending',
                    ])></span>
                    @include('partials.status-badge', ['status' => $run->status])
                    <span class="text-xs text-gray-400">
                        {{ $triggeredByLabel[$run->triggered_by] ?? $run->triggered_by }}
                    </span>
                    @if($run->flowVersion)
                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600 border border-indigo-100"
                              title="Шаблон, с който е изпълнен този run">
                            {{ $run->flowVersion->name }}
                        </span>
                    @endif
                </div>

                <div class="flex items-center gap-4">
                    @if($run->started_at)
                        <span class="text-xs text-gray-400">{{ $run->started_at->format('d.m.Y H:i') }}</span>
                    @endif
                    @if($run->started_at && $run->completed_at)
                        @php $secs = $run->started_at->diffInSeconds($run->completed_at); @endphp
                        <span class="text-xs text-gray-400 tabular-nums">
                            {{ $secs >= 60 ? floor($secs/60).'м '.($secs%60).'с' : $secs.'с' }}
                        </span>
                    @endif
                    <a href="{{ route('flows.builder', ['flow' => $flow, 'run' => $run->id]) }}"
                       class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Детайли →</a>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
