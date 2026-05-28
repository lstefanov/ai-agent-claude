@extends('layouts.app')

@section('title', $flow->name)

@php
$triggeredByLabel = ['manual' => '▶ Ръчно', 'scheduler' => '⏰ Планиран'];
$langFlag = ['bg' => '🇧🇬', 'en' => '🇬🇧', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸', 'ru' => '🇷🇺'];
@endphp

{{-- Clear the create-form draft for this company on successful save --}}
<script>sessionStorage.removeItem('flowai_draft_{{ $flow->company_id }}');</script>

@section('content')
{{-- Header --}}
<div class="flex items-start justify-between mb-6">
    <div>
        <a href="{{ route('companies.show', $flow->company) }}" class="text-indigo-600 hover:underline text-sm inline-flex items-center gap-1">
            ← {{ $flow->company->name }}
        </a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3 flex-wrap">
            {{ $flow->name }}
            @include('partials.status-badge', ['status' => $flow->status, 'class' => 'text-sm px-3'])
        </h1>
        <p class="text-gray-500 mt-1 max-w-2xl">{{ $flow->description }}</p>
    </div>
    <div class="flex gap-2 shrink-0">
        <a href="{{ route('flows.edit', $flow) }}"
           class="bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
            ✏ Редактирай
        </a>
        <form action="{{ route('flow-runs.store', $flow) }}" method="POST">
            @csrf
            <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-bold transition flex items-center gap-2">
                ▶ Стартирай
            </button>
        </form>
    </div>
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

{{-- Agents Table --}}
<div class="bg-white rounded-xl border border-gray-200 mb-8 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
        <h2 class="text-base font-semibold text-gray-900">
            Агенти
            <span class="ml-2 text-xs bg-gray-200 text-gray-600 px-2 py-0.5 rounded-full">{{ $flow->agents->count() }}</span>
        </h2>
    </div>

    @if($flow->agents->isEmpty())
        <div class="px-6 py-8 text-center text-gray-400 text-sm">Няма добавени агенти към този flow.</div>
    @else
    <div class="divide-y divide-gray-50">
        @foreach($flow->agents as $agent)
        <div class="px-6 py-4 flex items-center gap-4 hover:bg-gray-50/60 transition {{ !$agent->is_active ? 'opacity-50' : '' }}">
            {{-- Order badge --}}
            <span class="w-7 h-7 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center text-xs font-bold shrink-0">
                {{ $agent->order }}
            </span>

            {{-- Name + role --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap mb-0.5">
                    <span class="font-medium text-gray-900">{{ $agent->name }}</span>
                    @if($agent->is_verifier)
                        <span class="text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded-full font-medium">QA{{ $agent->qa_threshold ? ' '.$agent->qa_threshold.'%' : '' }}</span>
                    @endif
                    @if(!$agent->is_active)
                        <span class="text-xs bg-gray-100 text-gray-400 px-1.5 py-0.5 rounded-full">неактивен</span>
                    @endif
                </div>
                <p class="text-xs text-gray-400 truncate">{{ Str::limit($agent->role, 90) }}</p>
            </div>

            {{-- Output preferences chips --}}
            <div class="flex items-center gap-1.5 shrink-0">
                @if($agent->output_language)
                    <span class="text-sm" title="Език: {{ $agent->output_language }}">{{ $langFlag[$agent->output_language] ?? $agent->output_language }}</span>
                @endif
                @if($agent->output_tone)
                    <span class="text-xs bg-purple-50 text-purple-600 px-1.5 py-0.5 rounded">{{ $agent->output_tone }}</span>
                @endif
                @if($agent->output_format)
                    <span class="text-xs bg-sky-50 text-sky-600 px-1.5 py-0.5 rounded">{{ $agent->output_format }}</span>
                @endif
                @php $cfg = $agent->config ?? []; @endphp
                @if(!empty($cfg['temperature']))
                    <span class="text-xs text-gray-400" title="Temperature">🌡{{ $cfg['temperature'] }}</span>
                @endif
            </div>

            {{-- Type + Model --}}
            <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded font-mono shrink-0 hidden lg:inline">{{ $agent->type }}</span>
            <span class="text-xs text-indigo-600 bg-indigo-50 px-2 py-1 rounded font-mono shrink-0">{{ $agent->model }}</span>

            {{-- Edit --}}
            <a href="{{ route('agents.edit', [$flow, $agent]) }}"
               class="text-gray-300 hover:text-indigo-600 text-base shrink-0 transition" title="Редактирай">✏</a>
        </div>
        @endforeach
    </div>
    @endif
</div>

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
                    <a href="{{ route('flow-runs.show', $run) }}"
                       class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Детайли →</a>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
