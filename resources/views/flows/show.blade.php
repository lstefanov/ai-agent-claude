@extends('layouts.app')

@section('title', $flow->name)

@section('content')
<div class="flex items-start justify-between mb-6">
    <div>
        <a href="{{ route('companies.show', $flow->company) }}" class="text-indigo-600 hover:underline text-sm">
            ← {{ $flow->company->name }}
        </a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3">
            {{ $flow->name }}
            <span @class([
                'text-sm px-2 py-1 rounded-full font-medium',
                'bg-green-100 text-green-700'  => $flow->status === 'active',
                'bg-yellow-100 text-yellow-700' => $flow->status === 'draft',
                'bg-gray-100 text-gray-500'     => $flow->status === 'paused',
            ])>{{ $flow->status }}</span>
        </h1>
        <p class="text-gray-500 mt-1 max-w-2xl">{{ $flow->description }}</p>
    </div>
    <div class="flex gap-2 shrink-0">
        <a href="{{ route('flows.edit', $flow) }}"
           class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
            Редактирай
        </a>
        <form action="{{ route('flow-runs.store', $flow) }}" method="POST">
            @csrf
            <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-bold transition">
                ▶ Стартирай
            </button>
        </form>
    </div>
</div>

@if($flow->schedule_cron)
<div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-2 mb-6 text-sm text-blue-700">
    📅 Разписание: <code class="font-mono bg-blue-100 px-1 rounded">{{ $flow->schedule_cron }}</code>
    @if($flow->last_run_at)
        &nbsp;·&nbsp; Последно изпълнение: {{ $flow->last_run_at->diffForHumans() }}
    @endif
</div>
@endif

{{-- Agents Table --}}
<div class="bg-white rounded-xl border border-gray-200 mb-8">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">Агенти ({{ $flow->agents->count() }})</h2>
    </div>
    <div class="divide-y divide-gray-50">
        @foreach($flow->agents as $agent)
        <div class="px-6 py-4 flex items-center gap-4 hover:bg-gray-50 transition">
            <span class="w-7 h-7 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center text-sm font-bold shrink-0">
                {{ $agent->order }}
            </span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-0.5">
                    <span class="font-medium text-gray-900">{{ $agent->name }}</span>
                    @if($agent->is_verifier)
                        <span class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">QA</span>
                    @endif
                    @if(!$agent->is_active)
                        <span class="text-xs bg-gray-100 text-gray-400 px-2 py-0.5 rounded-full">неактивен</span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 truncate">{{ Str::limit($agent->role, 100) }}</p>
            </div>
            <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded font-mono shrink-0">{{ $agent->type }}</span>
            <span class="text-xs text-indigo-600 bg-indigo-50 px-2 py-1 rounded font-mono shrink-0">{{ $agent->model }}</span>
            <a href="{{ route('agents.edit', [$flow, $agent]) }}"
               class="text-gray-400 hover:text-indigo-600 text-sm shrink-0 transition">✏</a>
        </div>
        @endforeach
    </div>
</div>

{{-- Run History --}}
<div class="bg-white rounded-xl border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="text-lg font-semibold text-gray-900">История на изпълненията</h2>
    </div>
    @if($runs->isEmpty())
        <div class="px-6 py-8 text-center text-gray-400 text-sm">Все още няма изпълнения</div>
    @else
        <div class="divide-y divide-gray-50">
            @foreach($runs as $run)
            <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50 transition">
                <div class="flex items-center gap-3">
                    <span @class([
                        'w-2 h-2 rounded-full shrink-0',
                        'bg-green-500' => $run->status === 'completed',
                        'bg-red-500'   => $run->status === 'failed',
                        'bg-blue-500'  => $run->status === 'running',
                        'bg-gray-300'  => $run->status === 'pending',
                    ])></span>
                    <span class="text-sm font-medium text-gray-700">{{ ucfirst($run->status) }}</span>
                    <span class="text-xs text-gray-400">{{ $run->triggered_by }}</span>
                </div>
                <div class="flex items-center gap-4 text-sm text-gray-400">
                    @if($run->started_at)
                        <span>{{ $run->started_at->format('d.m.Y H:i') }}</span>
                    @endif
                    @if($run->started_at && $run->completed_at)
                        <span>{{ $run->started_at->diffInSeconds($run->completed_at) }}с</span>
                    @endif
                    <a href="{{ route('flow-runs.show', $run) }}" class="text-indigo-600 hover:underline">Детайли →</a>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
