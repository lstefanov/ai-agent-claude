@extends('layouts.app')

@section('title', 'Run #' . $flowRun->id)

@section('content')
<div class="mb-6">
    <a href="{{ route('flows.show', $flowRun->flow) }}" class="text-indigo-600 hover:underline text-sm">
        ← {{ $flowRun->flow->name }}
    </a>
    <h1 class="text-3xl font-bold text-gray-900 mt-2">
        Run #{{ $flowRun->id }}
        <span @class([
            'text-base px-3 py-1 rounded-full ml-2',
            'bg-green-100 text-green-700' => $flowRun->status === 'completed',
            'bg-red-100 text-red-700'     => $flowRun->status === 'failed',
            'bg-blue-100 text-blue-700'   => $flowRun->status === 'running',
            'bg-gray-100 text-gray-500'   => $flowRun->status === 'pending',
        ])>{{ ucfirst($flowRun->status) }}</span>
    </h1>
    @if($flowRun->started_at)
        <p class="text-gray-500 mt-1">
            {{ $flowRun->started_at->format('d.m.Y H:i:s') }}
            @if($flowRun->completed_at)
                · {{ $flowRun->started_at->diffInSeconds($flowRun->completed_at) }} секунди
            @endif
        </p>
    @endif
</div>

{{-- Agent Runs --}}
<div class="space-y-4">
    @forelse($flowRun->agentRuns as $run)
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden" x-data="{ open: false }">
        <div class="px-6 py-4 flex items-center gap-4 cursor-pointer hover:bg-gray-50" @click="open = !open">
            <span @class([
                'w-2.5 h-2.5 rounded-full shrink-0',
                'bg-green-500' => $run->status === 'completed',
                'bg-red-500'   => $run->status === 'failed',
                'bg-blue-500'  => $run->status === 'running',
                'bg-gray-300'  => in_array($run->status, ['pending','skipped']),
            ])></span>
            <div class="flex-1">
                <span class="font-medium text-gray-900">{{ $run->agent->name ?? 'Агент #'.$run->agent_id }}</span>
                <span class="text-xs text-gray-400 ml-2">{{ $run->agent->type ?? '' }}</span>
            </div>
            <span class="text-xs font-mono text-indigo-600 bg-indigo-50 px-2 py-1 rounded">{{ $run->model_used }}</span>
            @if($run->duration_ms)
                <span class="text-xs text-gray-400">{{ number_format($run->duration_ms / 1000, 2) }}с</span>
            @endif
            <span class="text-gray-400 text-sm" x-text="open ? '▲' : '▼'"></span>
        </div>

        <div x-show="open" x-cloak class="border-t border-gray-100 divide-y divide-gray-50">
            <div class="px-6 py-4">
                <p class="text-xs font-medium text-gray-500 uppercase mb-2">Вход</p>
                <pre class="bg-gray-50 rounded p-3 text-xs text-gray-600 overflow-auto max-h-40 whitespace-pre-wrap">{{ $run->input }}</pre>
            </div>
            <div class="px-6 py-4">
                <p class="text-xs font-medium text-gray-500 uppercase mb-2">Изход</p>
                <pre class="bg-gray-50 rounded p-3 text-xs text-gray-700 overflow-auto max-h-60 whitespace-pre-wrap">{{ $run->output ?? $run->error ?? '—' }}</pre>
            </div>
        </div>
    </div>
    @empty
        <div class="text-center py-12 text-gray-400">Няма записани стъпки за този run.</div>
    @endforelse
</div>
@endsection
