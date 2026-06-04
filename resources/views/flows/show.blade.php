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

@include('flows.partials.graph-preview')

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
