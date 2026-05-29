@extends('layouts.app')

@section('title', 'Run #' . $flowRun->id . ' — ' . $flowRun->flow->name)

@php
$initialAgents = $flowRun->flow->agents
    ->where('is_active', true)
    ->sortBy('order')
    ->map(fn($a) => [
        'id'           => $a->id,
        'name'         => $a->name,
        'type'         => $a->type,
        'is_verifier'  => (bool) $a->is_verifier,
        'qa_threshold' => $a->qa_threshold,
        'model'        => $a->model,
        'order'        => $a->order,
    ])->values();

$initialRuns = (object) $flowRun->agentRuns->mapWithKeys(fn($r) => [
    (string) $r->agent_id => [
        'agent_id'     => $r->agent_id,
        'status'       => $r->status,
        'model_used'   => $r->model_used,
        'input'        => $r->input,
        'output'       => $r->output,
        'error'        => $r->error,
        'duration_ms'  => $r->duration_ms,
        'tokens_used'  => $r->tokens_used,
        'started_at'   => $r->started_at?->format('H:i:s'),
        'completed_at' => $r->completed_at?->format('H:i:s'),
    ]
])->toArray();

// Detect platform from flow description / name
$flowDesc     = strtolower($flowRun->flow->description ?? '');
$flowName     = strtolower($flowRun->flow->name ?? '');
$postPlatform = 'generic';
if (str_contains($flowDesc . $flowName, 'facebook') || str_contains($flowDesc . $flowName, ' fb ') || str_starts_with($flowDesc . $flowName, 'fb')) {
    $postPlatform = 'facebook';
} elseif (str_contains($flowDesc . $flowName, 'instagram')) {
    $postPlatform = 'instagram';
} elseif (str_contains($flowDesc . $flowName, 'twitter') || str_contains($flowDesc . $flowName, 'tweet')) {
    $postPlatform = 'twitter';
} elseif (str_contains($flowDesc . $flowName, 'linkedin')) {
    $postPlatform = 'linkedin';
}

$companyName    = $flowRun->flow->company->name ?? 'Company';
$companyInitial = mb_strtoupper(mb_substr($companyName, 0, 1));
@endphp

{{-- ── Inject data into window BEFORE Alpine boots ─────────────── --}}
<script>
window.__runData = {
    status:       @json($flowRun->status),
    startedAt:    @json($flowRun->started_at?->toISOString()),
    completedAt:  @json($flowRun->completed_at?->toISOString()),
    agents:       @json($initialAgents),
    runs:         @json($initialRuns),
    pollUrl:      @json(route('flow-runs.poll', $flowRun)),
    logUrl:       @json(route('flow-runs.log',  $flowRun)),
};
</script>

@section('content')

<div x-data="flowRunMonitor()" x-init="init()">

{{-- ── HEADER ──────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between mb-6">
    <div>
        <a href="{{ route('flows.show', $flowRun->flow) }}"
           class="text-indigo-600 hover:underline text-sm inline-flex items-center gap-1">
            ← {{ $flowRun->flow->name }}
        </a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3 flex-wrap">
            Run #{{ $flowRun->id }}
            <span x-html="statusBadge(flowStatus)"
                  class="transition-all duration-500 text-sm font-medium px-3 py-1 rounded-full border inline-flex items-center">
            </span>
        </h1>
        <p class="text-gray-400 mt-1 text-sm flex items-center gap-2 flex-wrap">
            <span>{{ $flowRun->triggered_by === 'manual' ? '▶ Ръчно' : '⏰ Планиран' }}</span>
            @if($flowRun->created_at)
                <span>·</span><span>{{ $flowRun->created_at->format('d.m.Y H:i') }}</span>
            @endif
            <template x-if="totalDuration !== null">
                <span>· <span class="font-medium text-gray-600" x-text="formatSecs(totalDuration) + ' общо'"></span></span>
            </template>
            <template x-if="flowStatus === 'running' && elapsed > 0">
                <span class="text-indigo-500 font-mono tabular-nums" x-text="'⏱ ' + formatSecs(elapsed)"></span>
            </template>
        </p>
    </div>
    <a :href="logUrl" target="_blank"
       class="text-xs text-gray-400 hover:text-gray-600 border border-gray-200 px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition shrink-0 mt-1">
        📋 Лог файл
    </a>
</div>

{{-- ── PROGRESS BAR ────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-semibold text-gray-700"
                  x-text="completedCount + ' от ' + agents.length + ' агента'"></span>
            <template x-if="currentRunningName">
                <span class="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full font-medium"
                      x-text="'▶ ' + currentRunningName"></span>
            </template>
            <template x-if="flowStatus === 'completed'">
                <span class="text-xs bg-green-50 text-green-700 px-2 py-0.5 rounded-full font-medium">✓ Всички завършиха</span>
            </template>
            <template x-if="flowStatus === 'failed'">
                <span class="text-xs bg-red-50 text-red-600 px-2 py-0.5 rounded-full font-medium">✗ Грешка</span>
            </template>
            <template x-if="flowStatus === 'pending'">
                <span class="text-xs text-gray-400">подготовка…</span>
            </template>
        </div>
        <span class="text-sm font-bold tabular-nums"
              :class="{
                'text-green-600':  flowStatus === 'completed',
                'text-red-500':    flowStatus === 'failed',
                'text-indigo-600': flowStatus === 'running',
                'text-gray-400':   flowStatus === 'pending',
              }"
              x-text="progressPercent + '%'">
        </span>
    </div>

    <div class="relative h-2.5 bg-gray-100 rounded-full overflow-hidden">
        <div class="absolute inset-y-0 left-0 rounded-full transition-all duration-700 ease-out"
             :class="{
                 'progress-bar-running': flowStatus === 'running',
                 'bg-gradient-to-r from-green-400 to-emerald-500': flowStatus === 'completed',
                 'bg-red-400':  flowStatus === 'failed',
                 'bg-gray-300': flowStatus === 'pending',
             }"
             :style="'width:' + progressPercent + '%'">
        </div>
    </div>

    <div class="flex items-center gap-1 mt-2.5" x-show="agents.length > 0">
        <template x-for="agent in agents" :key="agent.id">
            <div class="flex-1 h-1 rounded-full transition-all duration-500"
                 :class="stepDotClass(agent.id)"></div>
        </template>
    </div>
</div>

{{-- ── FINAL OUTPUT PREVIEW ────────────────────────────────────── --}}
{{-- Show whenever there's output — even if QA failed the flow --}}
<template x-if="finalOutput && (flowStatus === 'completed' || flowStatus === 'failed')">
    <div class="mb-6">
        <h2 class="text-base font-semibold text-gray-700 mb-3 flex items-center gap-2">
            🎯 Финален резултат
            <span class="text-xs text-gray-400 font-normal">— изходът на последния агент</span>
        </h2>

        @if($postPlatform === 'facebook')
        {{-- Facebook preview --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm max-w-lg">
            <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
                <div class="w-10 h-10 rounded-full bg-indigo-600 flex items-center justify-center text-white font-bold text-sm shrink-0">
                    {{ $companyInitial }}
                </div>
                <div>
                    <p class="font-semibold text-gray-900 text-sm">{{ $companyName }}</p>
                    <p class="text-xs text-gray-400">Сега · 🌐</p>
                </div>
                <button @click="copyFinalOutput()"
                        class="ml-auto text-xs text-gray-400 hover:text-gray-600 transition"
                        x-text="copied ? '✓ Копирано' : '📋 Копирай'"></button>
            </div>
            <div class="px-4 py-3">
                <p class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed"
                   x-text="formatPostText(finalOutput)"></p>
            </div>
            <template x-if="hasImage(finalOutput)">
                <div class="border-t border-gray-100">
                    <img :src="extractImageUrl(finalOutput)" class="w-full object-cover max-h-72" alt="Post image">
                </div>
            </template>
            <template x-if="extractHashtags(finalOutput).length > 0">
                <div class="px-4 pb-3 flex flex-wrap gap-1">
                    <template x-for="tag in extractHashtags(finalOutput)" :key="tag">
                        <span class="text-sm text-indigo-600 hover:underline cursor-pointer" x-text="tag"></span>
                    </template>
                </div>
            </template>
            <div class="px-4 py-2 border-t border-gray-100 flex items-center gap-5 text-gray-500 text-sm">
                <button class="flex items-center gap-1.5 hover:text-blue-600 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z"/></svg>
                    Харесай
                </button>
                <button class="flex items-center gap-1.5 hover:text-blue-600 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Коментирай
                </button>
                <button class="flex items-center gap-1.5 hover:text-blue-600 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/></svg>
                    Сподели
                </button>
            </div>
        </div>

        @elseif($postPlatform === 'instagram')
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm max-w-sm">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 via-pink-500 to-orange-400 flex items-center justify-center text-white font-bold text-xs shrink-0">
                    {{ $companyInitial }}
                </div>
                <span class="font-semibold text-sm text-gray-900">{{ Str::slug($companyName, '_') }}</span>
                <button @click="copyFinalOutput()" class="ml-auto text-xs text-gray-400 hover:text-gray-600 transition"
                        x-text="copied ? '✓' : '📋'"></button>
            </div>
            <template x-if="hasImage(finalOutput)">
                <img :src="extractImageUrl(finalOutput)" class="w-full aspect-square object-cover" alt="">
            </template>
            <div class="px-3 py-2.5">
                <p class="text-sm text-gray-800 whitespace-pre-line leading-relaxed" x-text="formatPostText(finalOutput)"></p>
                <div class="flex flex-wrap gap-1 mt-1">
                    <template x-for="tag in extractHashtags(finalOutput)" :key="tag">
                        <span class="text-sm text-indigo-600 cursor-pointer hover:underline" x-text="tag"></span>
                    </template>
                </div>
            </div>
        </div>

        @elseif($postPlatform === 'twitter')
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm max-w-lg p-4">
            <div class="flex gap-3">
                <div class="w-10 h-10 rounded-full bg-gray-900 flex items-center justify-center text-white font-bold text-sm shrink-0">{{ $companyInitial }}</div>
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="font-bold text-gray-900 text-sm">{{ $companyName }}</span>
                        <span class="text-gray-400 text-sm">@{{ Str::slug($companyName) }}</span>
                        <button @click="copyFinalOutput()" class="ml-auto text-xs text-gray-400 hover:text-gray-600 transition"
                                x-text="copied ? '✓ Копирано' : '📋'"></button>
                    </div>
                    <p class="text-sm text-gray-900 whitespace-pre-line leading-relaxed" x-text="formatPostText(finalOutput)"></p>
                    <template x-if="hasImage(finalOutput)">
                        <img :src="extractImageUrl(finalOutput)" class="mt-2 rounded-xl max-h-48 w-full object-cover border border-gray-100" alt="">
                    </template>
                    <div class="flex items-center gap-4 mt-3 text-gray-400 text-xs">
                        <span>💬 0</span><span>🔁 0</span><span>❤️ 0</span>
                    </div>
                </div>
            </div>
        </div>

        @else
        {{-- Generic output --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm max-w-2xl">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 bg-gray-50">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Изход на последния агент</p>
                <button @click="copyFinalOutput()" class="text-xs text-gray-400 hover:text-gray-600 transition"
                        x-text="copied ? '✓ Копирано' : '📋 Копирай'"></button>
            </div>
            <template x-if="hasImage(finalOutput)">
                <div class="p-4 border-b border-gray-100">
                    <img :src="extractImageUrl(finalOutput)" class="rounded-lg max-h-56 object-cover border border-gray-200" alt="">
                </div>
            </template>
            <pre class="px-5 py-4 text-sm text-gray-800 whitespace-pre-wrap leading-relaxed font-sans"
                 x-text="stripImageMarkdown(finalOutput)"></pre>
        </div>
        @endif

    </div>
</template>

{{-- ── AGENT PIPELINE ──────────────────────────────────────────── --}}
<div class="space-y-2">
    <template x-for="(agent, idx) in agents" :key="agent.id">
        <div class="rounded-xl border overflow-hidden transition-all duration-300"
             :class="cardClass(agent.id)">

            <div class="px-5 py-3.5 flex items-center gap-3 cursor-pointer select-none"
                 @click="toggleExpand(agent.id)">

                <div class="shrink-0 w-7 h-7 flex items-center justify-center rounded-full text-xs font-bold transition-all duration-300"
                     :class="iconBgClass(agent.id)">
                    <template x-if="runStatus(agent.id) === 'running'">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/>
                        </svg>
                    </template>
                    <template x-if="runStatus(agent.id) === 'completed' && !agent.is_verifier">
                        <span>✓</span>
                    </template>
                    <template x-if="runStatus(agent.id) === 'completed' && agent.is_verifier">
                        <span x-text="qaScore(agent.id) >= (agent.qa_threshold || 75) ? '✓' : '✗'"></span>
                    </template>
                    <template x-if="runStatus(agent.id) === 'failed'">
                        <span>✗</span>
                    </template>
                    <template x-if="runStatus(agent.id) === 'pending'">
                        <span class="text-gray-400" x-text="idx + 1"></span>
                    </template>
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium text-gray-900 text-sm" x-text="agent.name"></span>
                        <span class="text-xs text-gray-400 font-mono hidden sm:inline" x-text="agent.type"></span>
                        <template x-if="agent.is_verifier">
                            <span class="text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded-full font-medium"
                                  x-text="'QA' + (agent.qa_threshold ? ' ' + agent.qa_threshold + '%' : '')"></span>
                        </template>
                    </div>
                    <template x-if="runStatus(agent.id) === 'running'">
                        <p class="text-xs text-indigo-500 mt-0.5 flex items-center gap-1">
                            Обработва се
                            <span class="inline-flex gap-0.5 ml-0.5">
                                <span class="w-1 h-1 bg-indigo-400 rounded-full animate-bounce" style="animation-delay:0s"></span>
                                <span class="w-1 h-1 bg-indigo-400 rounded-full animate-bounce" style="animation-delay:.15s"></span>
                                <span class="w-1 h-1 bg-indigo-400 rounded-full animate-bounce" style="animation-delay:.3s"></span>
                            </span>
                        </p>
                    </template>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <template x-if="agent.is_verifier && runStatus(agent.id) === 'completed'">
                        <span class="text-base font-bold tabular-nums"
                              :class="qaScore(agent.id) >= (agent.qa_threshold || 75) ? 'text-green-600' : 'text-red-500'"
                              x-text="qaScore(agent.id) + '%'"></span>
                    </template>
                    <template x-if="getRun(agent.id) && getRun(agent.id).duration_ms">
                        <span class="text-xs text-gray-400 font-mono tabular-nums"
                              x-text="formatMs(getRun(agent.id).duration_ms)"></span>
                    </template>
                    <span class="text-xs font-mono px-2 py-0.5 rounded hidden md:inline"
                          :class="runStatus(agent.id) === 'pending' ? 'text-gray-300 bg-gray-100' : 'text-indigo-600 bg-indigo-50'"
                          x-text="(getRun(agent.id) && getRun(agent.id).model_used) ? getRun(agent.id).model_used : agent.model"></span>
                    <span class="text-gray-300 text-xs transition-transform duration-200"
                          :class="expanded[agent.id] ? 'rotate-180' : ''"
                          x-show="runStatus(agent.id) !== 'pending'">▼</span>
                </div>
            </div>

            {{-- Running skeleton --}}
            <template x-if="runStatus(agent.id) === 'running'">
                <div class="px-5 pb-4">
                    <div class="rounded-lg bg-indigo-50/60 border border-indigo-100 p-3 space-y-2">
                        <div class="h-2 bg-indigo-100 rounded animate-pulse w-3/4"></div>
                        <div class="h-2 bg-indigo-100 rounded animate-pulse w-1/2"></div>
                        <div class="h-2 bg-indigo-100 rounded animate-pulse w-2/3"></div>
                    </div>
                </div>
            </template>

            {{-- Expanded body --}}
            <div x-show="expanded[agent.id] && runStatus(agent.id) !== 'running' && runStatus(agent.id) !== 'pending'"
                 x-cloak
                 class="border-t"
                 :class="runStatus(agent.id) === 'failed' ? 'border-red-100' : 'border-gray-100'">

                <template x-if="getRun(agent.id) && getRun(agent.id).error">
                    <div class="px-5 py-4 bg-red-50">
                        <p class="text-xs font-semibold text-red-600 uppercase tracking-wider mb-2">⚠ Грешка</p>
                        <pre class="text-xs text-red-800 bg-red-100 rounded-lg p-3 overflow-auto max-h-40 whitespace-pre-wrap font-mono"
                             x-text="getRun(agent.id).error"></pre>
                    </div>
                </template>

                <template x-if="getRun(agent.id) && hasImage(getRun(agent.id).output)">
                    <div class="px-5 pt-4">
                        <img :src="extractImageUrl(getRun(agent.id).output)"
                             class="rounded-lg max-h-56 object-cover border border-gray-200 shadow-sm" alt="Generated image">
                    </div>
                </template>

                <template x-if="getRun(agent.id) && getRun(agent.id).output">
                    <div class="px-5 py-4" x-data="{ copiedAgent: false }">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Изход</p>
                            <div class="flex items-center gap-3">
                                <template x-if="getRun(agent.id) && getRun(agent.id).started_at">
                                    <span class="text-xs text-gray-300"
                                          x-text="getRun(agent.id).started_at + ' → ' + (getRun(agent.id).completed_at || '')"></span>
                                </template>
                                <button @click="navigator.clipboard.writeText(getRun(agent.id).output || ''); copiedAgent=true; setTimeout(()=>copiedAgent=false,2000)"
                                        class="text-xs text-gray-400 hover:text-gray-600 transition"
                                        x-text="copiedAgent ? '✓ Копирано' : '📋 Копирай'">
                                </button>
                            </div>
                        </div>
                        <pre class="bg-gray-50 rounded-lg p-3 text-xs text-gray-700 overflow-auto max-h-60 whitespace-pre-wrap font-mono leading-relaxed border border-gray-100"
                             x-text="stripImageMarkdown(getRun(agent.id).output)"></pre>
                    </div>
                </template>

                <template x-if="getRun(agent.id) && getRun(agent.id).tokens_used && !agent.is_verifier">
                    <div class="px-5 py-2 bg-gray-50 border-t border-gray-100 text-xs text-gray-400">
                        Токени: <span x-text="Number(getRun(agent.id).tokens_used).toLocaleString()"></span>
                    </div>
                </template>
            </div>

        </div>
    </template>
</div>

{{-- ── SUMMARY ─────────────────────────────────────────────────── --}}
<template x-if="flowStatus === 'completed' || flowStatus === 'failed'">
    <div class="mt-6 bg-white rounded-xl border border-gray-200 px-5 py-4 flex items-center gap-5 flex-wrap text-sm">
        <div class="flex items-center gap-1.5">
            <span class="text-gray-400">Агенти:</span>
            <span class="font-semibold text-gray-900" x-text="agents.length"></span>
        </div>
        <template x-if="totalDuration !== null">
            <div class="flex items-center gap-1.5">
                <span class="text-gray-400">Общо:</span>
                <span class="font-semibold text-gray-900" x-text="formatSecs(totalDuration)"></span>
            </div>
        </template>
        <div class="flex items-center gap-1.5">
            <span class="text-gray-400">Успешни:</span>
            <span class="font-semibold text-green-600"
                  x-text="Object.values(runs).filter(r => r.status === 'completed').length"></span>
        </div>
        <template x-if="Object.values(runs).some(r => r.status === 'failed')">
            <div class="flex items-center gap-1.5">
                <span class="text-gray-400">Неуспешни:</span>
                <span class="font-semibold text-red-500"
                      x-text="Object.values(runs).filter(r => r.status === 'failed').length"></span>
            </div>
        </template>
        <a :href="logUrl" target="_blank"
           class="ml-auto text-xs text-gray-400 hover:text-gray-600 transition">📋 Пълен лог</a>
    </div>
</template>

</div>{{-- /x-data --}}

@endsection

@push('scripts')
<style>
@keyframes shimmer {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(200%); }
}
.progress-bar-running {
    background: linear-gradient(90deg, #6366f1, #a855f7);
    position: relative;
    overflow: hidden;
}
.progress-bar-running::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.4) 50%, transparent 100%);
    animation: shimmer 1.8s infinite linear;
}
[x-cloak] { display: none !important; }
</style>
<script>
function flowRunMonitor() {
    const d = window.__runData;

    return {
        flowStatus:  d.status,
        agents:      d.agents,
        runs:        d.runs || {},
        expanded:    {},
        elapsed:     0,
        copied:      false,
        logUrl:      d.logUrl,
        pollUrl:     d.pollUrl,
        _timer:      null,
        _poller:     null,
        startedAt:   d.startedAt   ? new Date(d.startedAt)   : null,
        completedAt: d.completedAt ? new Date(d.completedAt) : null,

        init() {
            // Auto-expand completed and failed runs on load
            const runsObj = this.runs;
            for (const key in runsObj) {
                const r = runsObj[key];
                if (r && ['completed', 'failed'].includes(r.status)) {
                    this.expanded[r.agent_id] = true;
                }
            }
            if (['pending', 'running'].includes(this.flowStatus)) {
                this._startTimer();
                this._startPolling();
            }
        },

        _startTimer() {
            this._timer = setInterval(() => {
                this.elapsed = this.startedAt
                    ? Math.floor((Date.now() - this.startedAt.getTime()) / 1000)
                    : this.elapsed + 1;
            }, 1000);
        },

        _startPolling() {
            this._poller = setInterval(() => this._poll(), 2500);
        },

        _stopAll() {
            clearInterval(this._timer);
            clearInterval(this._poller);
        },

        async _poll() {
            // Guard: if already in terminal state, ensure stopped
            if (['completed', 'failed'].includes(this.flowStatus)) {
                this._stopAll();
                return;
            }

            let data = null;
            try {
                const res = await fetch(this.pollUrl, { headers: { Accept: 'application/json' } });
                if (res.ok) data = await res.json();
            } catch (e) {
                // network error — keep polling, don't return early
            }

            if (!data) return;  // No usable response — keep interval running for next tick

            try {
                this.flowStatus = data.status;

                if (data.started_at_iso && !this.startedAt)
                    this.startedAt = new Date(data.started_at_iso);
                if (data.completed_at_iso)
                    this.completedAt = new Date(data.completed_at_iso);

                const newRuns    = { ...this.runs };
                const newExpanded = { ...this.expanded };

                (data.agent_runs || []).forEach(r => {
                    const key  = String(r.agent_id);
                    const prev = newRuns[key];
                    newRuns[key] = r;
                    const wasPending = !prev || ['pending', 'running'].includes(prev.status);
                    if (wasPending && ['completed', 'failed'].includes(r.status)) {
                        newExpanded[r.agent_id] = true;
                    }
                });

                this.runs     = newRuns;
                this.expanded = newExpanded;
            } catch (e) {
                console.error('[poll] processing error:', e);
            } finally {
                if (['completed', 'failed'].includes(data?.status)) {
                    this._stopAll();
                }
            }
        },

        // ── Helpers ────────────────────────────────────────────────

        toggleExpand(agentId) {
            this.expanded = { ...this.expanded, [agentId]: !this.expanded[agentId] };
        },

        getRun(agentId) {
            return this.runs[String(agentId)] || null;
        },

        runStatus(agentId) {
            const r = this.getRun(agentId);
            return r ? r.status : 'pending';
        },

        // ── Final output: last completed non-QA agent ─────────────
        get finalOutput() {
            const reversed = [...this.agents].reverse();
            // First pass: skip verifiers AND qa_verifier type
            for (const a of reversed) {
                if (a.is_verifier || a.type === 'qa_verifier') continue;
                const r = this.getRun(a.id);
                if (r && r.status === 'completed' && r.output) return r.output;
            }
            // Second pass: any completed agent that isn't a QA verifier
            for (const a of reversed) {
                if (a.type === 'qa_verifier') continue;
                const r = this.getRun(a.id);
                if (r && r.status === 'completed' && r.output) return r.output;
            }
            return null;
        },

        // ── QA helpers ─────────────────────────────────────────────
        qaScore(agentId) {
            const r = this.getRun(agentId);
            if (!r) return 0;
            if (r.output) {
                const m = r.output.match(/"score"\s*:\s*(\d{1,3})/i);
                if (m) return Math.min(100, Math.max(0, parseInt(m[1])));
            }
            if (r.tokens_used != null) return Math.min(100, Math.max(0, parseInt(r.tokens_used)));
            if (r.output) {
                const n = r.output.match(/\b(\d{1,3})\b/);
                if (n) return Math.min(100, Math.max(0, parseInt(n[1])));
            }
            return 0;
        },

        // ── Computed ───────────────────────────────────────────────
        get completedCount() {
            return Object.values(this.runs).filter(r => r && ['completed', 'failed'].includes(r.status)).length;
        },

        get progressPercent() {
            if (!this.agents.length) return 0;
            return Math.round((this.completedCount / this.agents.length) * 100);
        },

        get currentRunningName() {
            const run = Object.values(this.runs).find(r => r && r.status === 'running');
            if (!run) return null;
            const agent = this.agents.find(a => String(a.id) === String(run.agent_id));
            return agent ? agent.name : null;
        },

        get totalDuration() {
            if (!this.startedAt || !this.completedAt) return null;
            return Math.floor((this.completedAt.getTime() - this.startedAt.getTime()) / 1000);
        },

        // ── CSS class helpers ──────────────────────────────────────
        cardClass(agentId) {
            const s = this.runStatus(agentId);
            if (s === 'running')   return 'border-indigo-300 shadow-sm bg-white';
            if (s === 'completed') return 'border-green-200 bg-white';
            if (s === 'failed')    return 'border-red-200 bg-white';
            return 'border-gray-200 bg-gray-50 opacity-60';
        },

        iconBgClass(agentId) {
            const s = this.runStatus(agentId);
            if (s === 'running')   return 'bg-indigo-100 text-indigo-600';
            if (s === 'completed') return 'bg-green-100 text-green-700';
            if (s === 'failed')    return 'bg-red-100 text-red-600';
            return 'bg-gray-100 text-gray-400';
        },

        stepDotClass(agentId) {
            const s = this.runStatus(agentId);
            if (s === 'running')   return 'bg-indigo-400 animate-pulse';
            if (s === 'completed') return 'bg-green-400';
            if (s === 'failed')    return 'bg-red-400';
            return 'bg-gray-200';
        },

        statusBadge(status) {
            const map = {
                pending:   'background:#f3f4f6;color:#6b7280;border-color:#e5e7eb',
                running:   'background:#eef2ff;color:#4f46e5;border-color:#c7d2fe',
                completed: 'background:#f0fdf4;color:#16a34a;border-color:#bbf7d0',
                failed:    'background:#fef2f2;color:#dc2626;border-color:#fecaca',
            };
            const labels = {
                pending: '⏳ Изчакване', running: '⚡ Изпълнява се',
                completed: '✓ Завършен',  failed: '✗ Неуспешен',
            };
            const style = map[status] || map.pending;
            const label = labels[status] || status;
            return `<span style="${style};padding:.2rem .75rem;border-radius:9999px;border:1px solid;font-size:.875rem;font-weight:500">${label}</span>`;
        },

        // ── Post helpers ───────────────────────────────────────────
        formatPostText(output) {
            if (!output) return '';
            return output
                .replace(/!\[.*?\]\(https?:\/\/[^)]+\)\n?/g, '')
                .replace(/#\S+/g, '')
                .replace(/\*\*(SD Prompt|Prompt|Path):\*\*.*$/gm, '')
                .trim();
        },

        extractHashtags(output) {
            if (!output) return [];
            return (output.match(/#[^\s#，。！？\n]+/g) || []).slice(0, 15);
        },

        hasImage(output) {
            return !!(output && /!\[.*?\]\((https?:\/\/[^)]+)\)/.test(output));
        },

        extractImageUrl(output) {
            if (!output) return null;
            const m = output.match(/!\[.*?\]\((https?:\/\/[^)]+)\)/);
            return m ? m[1] : null;
        },

        stripImageMarkdown(output) {
            return output ? output.replace(/!\[.*?\]\(https?:\/\/[^)]+\)\n?/g, '').trim() : '';
        },

        copyFinalOutput() {
            const text = this.finalOutput ? this.stripImageMarkdown(this.finalOutput) : '';
            navigator.clipboard.writeText(text);
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        },

        // ── Formatters ─────────────────────────────────────────────
        formatMs(ms) {
            if (!ms && ms !== 0) return '';
            const s = Math.round(ms / 1000);
            return s >= 60 ? Math.floor(s / 60) + 'м ' + (s % 60) + 'с' : s + 'с';
        },

        formatSecs(s) {
            if (s == null) return '';
            return s >= 60 ? Math.floor(s / 60) + 'м ' + (s % 60) + 'с' : s + 'с';
        },
    };
}
</script>
@endpush
