@extends('layouts.app')

@section('title', 'Run #' . $flowRun->id)

@php
$triggeredByLabel = ['manual' => '▶ Ръчно', 'scheduler' => '⏰ Планиран'];
$totalDuration = $flowRun->agentRuns->sum('duration_ms');
$totalTokens   = $flowRun->agentRuns->sum('tokens_used');
@endphp

@section('content')

{{-- Header --}}
<div class="mb-6">
    <a href="{{ route('flows.show', $flowRun->flow) }}" class="text-indigo-600 hover:underline text-sm inline-flex items-center gap-1">
        ← {{ $flowRun->flow->name }}
    </a>
    <div class="flex items-start justify-between mt-2">
        <div>
            <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3 flex-wrap">
                Run #{{ $flowRun->id }}
                @include('partials.status-badge', ['status' => $flowRun->status, 'class' => 'text-sm px-3'])
            </h1>
            <p class="text-gray-500 mt-1 text-sm flex items-center gap-3 flex-wrap">
                @if($flowRun->started_at)
                    <span>{{ $flowRun->started_at->format('d.m.Y H:i:s') }}</span>
                @endif
                @if($flowRun->started_at && $flowRun->completed_at)
                    @php $secs = $flowRun->started_at->diffInSeconds($flowRun->completed_at); @endphp
                    <span class="text-gray-400">·</span>
                    <span>{{ $secs >= 60 ? floor($secs/60).'м '.($secs%60).'с' : $secs.'с' }} общо</span>
                @endif
                <span class="text-gray-400">·</span>
                <span>{{ $triggeredByLabel[$flowRun->triggered_by] ?? $flowRun->triggered_by }}</span>
            </p>
        </div>

        {{-- Summary stats --}}
        <div class="flex items-center gap-3 shrink-0">
            @if($totalDuration > 0)
            <div class="text-center bg-white border border-gray-200 rounded-lg px-4 py-2">
                <p class="text-xs text-gray-400">Агенти</p>
                <p class="text-lg font-bold text-gray-900">{{ $flowRun->agentRuns->count() }}</p>
            </div>
            @endif
            @if($totalTokens > 0)
            <div class="text-center bg-white border border-gray-200 rounded-lg px-4 py-2">
                <p class="text-xs text-gray-400">Токени</p>
                <p class="text-lg font-bold text-gray-900">{{ number_format($totalTokens) }}</p>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Failed flow banner --}}
@if($flowRun->status === 'failed')
<div class="mb-6 bg-red-50 border border-red-200 rounded-xl px-5 py-4 flex items-start gap-3">
    <span class="text-red-500 text-xl shrink-0">⚠</span>
    <div>
        <p class="font-semibold text-red-800 text-sm">Изпълнението е неуспешно</p>
        <p class="text-red-600 text-xs mt-0.5">Провери грешката в съответния агент по-долу.</p>
    </div>
</div>
@endif

{{-- Agent Runs --}}
<div class="space-y-3">
    @forelse($flowRun->agentRuns as $index => $run)
    @php
        $isFailed    = $run->status === 'failed';
        $isCompleted = $run->status === 'completed';
        $isVerifier  = $run->agent?->is_verifier;
        $qaScore     = $isVerifier ? ($run->tokens_used ?? null) : null;
    @endphp

    <div class="bg-white rounded-xl border overflow-hidden
                {{ $isFailed ? 'border-red-200' : 'border-gray-200' }}"
         x-data="{ open: {{ $isFailed ? 'true' : 'false' }} }">

        {{-- Row header --}}
        <div class="px-6 py-4 flex items-center gap-4 cursor-pointer hover:bg-gray-50/60 transition"
             @click="open = !open">

            {{-- Step number --}}
            <span class="w-6 h-6 rounded-full text-xs font-bold flex items-center justify-center shrink-0
                         {{ $isCompleted ? 'bg-green-100 text-green-700' : ($isFailed ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-500') }}">
                {{ $index + 1 }}
            </span>

            {{-- Status dot --}}
            <span @class([
                'w-2 h-2 rounded-full shrink-0',
                'bg-green-500'              => $isCompleted,
                'bg-red-500'                => $isFailed,
                'bg-blue-500 animate-pulse' => $run->status === 'running',
                'bg-gray-300'               => in_array($run->status, ['pending','skipped']),
            ])></span>

            {{-- Agent name + type --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-medium text-gray-900">{{ $run->agent->name ?? 'Агент #'.$run->agent_id }}</span>
                    @if($run->agent?->type)
                        <span class="text-xs text-gray-400 font-mono">{{ $run->agent->type }}</span>
                    @endif
                    @if($isVerifier)
                        <span class="text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded-full font-medium">QA</span>
                    @endif
                    @if($isFailed)
                        <span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full">Неуспешен</span>
                    @endif
                </div>
            </div>

            {{-- QA score --}}
            @if($isVerifier && $qaScore !== null)
            <div class="shrink-0 text-center">
                <span class="text-sm font-bold {{ $qaScore >= ($run->agent->qa_threshold ?? 70) ? 'text-green-600' : 'text-red-600' }}">
                    {{ $qaScore }}%
                </span>
                <span class="text-xs text-gray-400 ml-1">QA</span>
            </div>
            @endif

            {{-- Model --}}
            @if($run->model_used)
            <span class="text-xs font-mono text-indigo-600 bg-indigo-50 px-2 py-1 rounded shrink-0">
                {{ $run->model_used }}
            </span>
            @endif

            {{-- Duration --}}
            @if($run->duration_ms)
            <span class="text-xs text-gray-400 tabular-nums shrink-0">
                {{ $run->duration_ms >= 60000
                   ? floor($run->duration_ms/60000).'м '.round(($run->duration_ms%60000)/1000).'с'
                   : number_format($run->duration_ms / 1000, 1).'с' }}
            </span>
            @endif

            <span class="text-gray-300 text-sm shrink-0" x-text="open ? '▲' : '▼'"></span>
        </div>

        {{-- Expandable body --}}
        <div x-show="open" x-cloak class="border-t {{ $isFailed ? 'border-red-100' : 'border-gray-100' }}">

            {{-- Error block (prominent) --}}
            @if($isFailed && $run->error)
            <div class="px-6 py-4 bg-red-50">
                <p class="text-xs font-semibold text-red-700 uppercase tracking-wide mb-2">⚠ Грешка</p>
                <pre class="text-xs text-red-800 bg-red-100 rounded-lg p-3 overflow-auto max-h-48 whitespace-pre-wrap font-mono">{{ $run->error }}</pre>
            </div>
            @endif

            {{-- Input --}}
            @if($run->input)
            <div class="px-6 py-4 {{ $isFailed && $run->error ? 'border-t border-red-100' : '' }}"
                 x-data="{ copied: false }">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Вход</p>
                    <button @click="navigator.clipboard.writeText($el.closest('[x-data]').querySelector('pre').textContent); copied = true; setTimeout(() => copied = false, 2000)"
                            class="text-xs text-gray-400 hover:text-gray-600 transition flex items-center gap-1">
                        <span x-text="copied ? '✓ Копирано' : '📋 Копирай'"></span>
                    </button>
                </div>
                <pre class="bg-gray-50 rounded-lg p-3 text-xs text-gray-600 overflow-auto max-h-48 whitespace-pre-wrap font-mono leading-relaxed">{{ $run->input }}</pre>
            </div>
            @endif

            {{-- Output --}}
            <div class="px-6 py-4 border-t border-gray-50"
                 x-data="{ copied: false }">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Изход</p>
                    @if($run->output)
                    <button @click="navigator.clipboard.writeText($el.closest('[x-data]').querySelector('pre').textContent); copied = true; setTimeout(() => copied = false, 2000)"
                            class="text-xs text-gray-400 hover:text-gray-600 transition flex items-center gap-1">
                        <span x-text="copied ? '✓ Копирано' : '📋 Копирай'"></span>
                    </button>
                    @endif
                </div>
                @if($run->output)
                    <pre class="bg-gray-50 rounded-lg p-3 text-xs text-gray-700 overflow-auto max-h-72 whitespace-pre-wrap font-mono leading-relaxed">{{ $run->output }}</pre>
                @elseif(!$run->error)
                    <p class="text-xs text-gray-400 italic">Без изход</p>
                @endif
            </div>

            {{-- Stats footer --}}
            @if($run->tokens_used || $run->started_at)
            <div class="px-6 py-2 bg-gray-50 border-t border-gray-100 flex items-center gap-4 text-xs text-gray-400">
                @if($run->started_at)
                    <span>Старт: {{ $run->started_at->format('H:i:s') }}</span>
                @endif
                @if($run->completed_at)
                    <span>Край: {{ $run->completed_at->format('H:i:s') }}</span>
                @endif
                @if($run->tokens_used && !$isVerifier)
                    <span>Токени: {{ number_format($run->tokens_used) }}</span>
                @endif
            </div>
            @endif
        </div>
    </div>
    @empty
        <div class="text-center py-12 bg-white rounded-xl border border-dashed border-gray-200">
            <p class="text-gray-400 text-sm">Няма записани стъпки за този run.</p>
        </div>
    @endforelse
</div>
@endsection
