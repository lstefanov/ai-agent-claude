@extends('layouts.app')

@section('title', 'Eval run #' . $evalRun->id . ' — ' . $flow->name)

@php
    $detail = $evalRun->scores_detail ?? [];
    $criteria = collect($evalRun->evalCase->criteria ?? [])->keyBy('key');
    $icon = fn ($s) => $s >= 80 ? '✅' : ($s >= 60 ? '⚠️' : '❌');
    $scoreClass = function ($s) {
        if ($s >= 85) return 'text-green-600';
        if ($s >= 65) return 'text-amber-600';
        return 'text-red-600';
    };
    $fmtCost = fn ($c) => '$' . rtrim(rtrim(number_format((float) $c, 4), '0'), '.');
@endphp

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <div class="text-sm text-gray-400 mb-1">
            <a href="{{ route('flows.show', $flow) }}" class="hover:text-indigo-600">{{ $flow->name }}</a>
            <span class="mx-1">/</span>
            <a href="{{ route('flows.eval.results', $flow) }}" class="hover:text-indigo-600">Eval резултати</a>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $evalRun->evalCase->name ?? 'Eval run' }}</h1>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 mt-2">
            <span>Версия: <b>{{ $evalRun->flowVersion->name ?? '—' }}</b></span>
            <span>Ниво: <b class="uppercase">{{ $evalRun->model_level }}</b></span>
            <span>Score: <b class="{{ $scoreClass((float) $evalRun->score) }}">{{ $evalRun->score !== null ? round($evalRun->score) : '—' }}/100</b></span>
            <span>Цена: <b>{{ $fmtCost($evalRun->cost_usd) }}</b></span>
            @if($evalRun->duration_ms)<span>Време: <b>{{ round($evalRun->duration_ms / 1000, 1) }}s</b></span>@endif
            <span class="px-2 py-0.5 rounded text-xs {{ $evalRun->status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $evalRun->status }}</span>
        </div>
    </div>

    @if($evalRun->error)
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-5 text-sm">{{ $evalRun->error }}</div>
    @endif

    {{-- Criteria --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-5">
        <h2 class="font-semibold text-gray-800 mb-3">Критерии</h2>
        <div class="space-y-3">
            @forelse($detail as $key => $res)
                @php $crit = $criteria[$key] ?? []; $score = (int) ($res['score'] ?? 0); @endphp
                <div class="border-b border-gray-50 last:border-0 pb-3 last:pb-0">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-medium text-gray-800">
                            {{ $icon($score) }} {{ $crit['label'] ?? $key }}
                            @if(($crit['type'] ?? '') !== 'llm_judge')<span class="text-xs text-gray-400">[{{ $crit['type'] ?? 'rule' }}]</span>@endif
                        </span>
                        <span class="text-sm shrink-0">
                            <b class="{{ $scoreClass($score) }}">{{ $score }}/100</b>
                            <span class="text-gray-400">· тежест {{ rtrim(rtrim(number_format((float) ($crit['weight'] ?? 1), 1), '0'), '.') }}</span>
                        </span>
                    </div>
                    @if(!empty($res['reason']))
                        <p class="text-sm text-gray-500 mt-1">{{ $res['reason'] }}</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-400">Няма оценени критерии.</p>
            @endforelse
        </div>
    </div>

    {{-- Final output --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-5">
        <h2 class="font-semibold text-gray-800 mb-3">Финален изход</h2>
        <div class="text-sm text-gray-700 whitespace-pre-wrap max-h-96 overflow-auto bg-gray-50 rounded-lg p-4">{{ $evalRun->final_output ?: '(празно)' }}</div>
    </div>

    {{-- Node runs --}}
    @if($evalRun->flowRun && $evalRun->flowRun->nodeRuns->count())
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-3">Node runs</h2>
        <table class="w-full text-sm">
            <thead><tr class="text-gray-400 text-xs border-b border-gray-100">
                <th class="text-left py-2">Възел</th><th class="text-left py-2">Модел</th>
                <th class="text-right py-2">QA</th><th class="text-right py-2">Цена</th><th class="text-right py-2">Статус</th>
            </tr></thead>
            <tbody>
                @foreach($evalRun->flowRun->nodeRuns as $nr)
                    <tr class="border-b border-gray-50 last:border-0">
                        <td class="py-2 text-gray-700">{{ $nr->node_key }}</td>
                        <td class="py-2 text-gray-500 font-mono text-xs">{{ $nr->model_used ?: '—' }}</td>
                        <td class="py-2 text-right">{{ $nr->qa_score !== null ? $nr->qa_score : '—' }}</td>
                        <td class="py-2 text-right text-gray-500">{{ $fmtCost($nr->cost_usd) }}</td>
                        <td class="py-2 text-right text-xs text-gray-400">{{ $nr->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
