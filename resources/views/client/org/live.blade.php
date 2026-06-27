@extends('layouts.client')

@section('title', 'Текущ поток')

@section('content')
@php
    $activeStatuses = ['pending', 'running', 'waiting_approval'];
    // Асистенти с поне една активна задача отгоре.
    $rows = collect($graph['assistants'])->map(function ($a) use ($activeStatuses) {
        $active = collect($a['tasks'])->filter(fn ($t) => in_array($t['run']['status'] ?? null, $activeStatuses, true));
        $recent = collect($a['tasks'])->filter(fn ($t) => ($t['run']['status'] ?? null) === 'completed');
        return ['member' => $a['member'], 'title' => $a['title'], 'active' => $active, 'recent' => $recent];
    })->filter(fn ($r) => $r['active']->isNotEmpty() || $r['recent']->isNotEmpty())->values();

    // Обобщение: общо активни runs, общо flows, заети асистенти.
    $totalActive = collect($graph['assistants'])->sum(fn ($a) => $a['stats']['active']);
    $totalFlows = collect($graph['assistants'])->sum(fn ($a) => $a['stats']['flows_total']);
    $busyAssistants = $rows->filter(fn ($r) => $r['active']->isNotEmpty())->count();
@endphp
<div>
    @include('client.org._lens-tabs', ['active' => 'live'])

    <h1 class="text-2xl font-semibold text-ink mb-1">Текущ поток</h1>
    <p class="text-muted mb-4">Снимка на текущата работа. За живо проследяване без презареждане виж <a href="{{ route('client.org.dashboard') }}" class="text-primary hover:text-primary-hover">Табло</a>.</p>

    <div class="mb-6 flex flex-wrap items-center gap-4 rounded-xl border border-line bg-surface px-4 py-3 text-sm">
        <span class="inline-flex items-center gap-2">
            <span class="h-2 w-2 rounded-full {{ $totalActive > 0 ? 'bg-accent animate-pulse' : 'bg-subtle' }}"></span>
            <span class="font-semibold text-ink tabular-nums">{{ $totalActive }}</span> <span class="text-muted">активни изпълнения</span>
        </span>
        <span class="text-subtle">·</span>
        <span><span class="font-semibold text-ink tabular-nums">{{ $busyAssistants }}</span> <span class="text-muted">заети асистенти</span></span>
        <span class="text-subtle">·</span>
        <span><span class="font-semibold text-ink tabular-nums">{{ $totalFlows }}</span> <span class="text-muted">flows общо</span></span>
    </div>

    @if ($rows->isEmpty())
        <x-empty-state title="Тихо е" description="Няма активни или скорошни изпълнения. Пусни задача от Задачи или Профил на служителя." />
    @else
        <div class="space-y-3">
            @foreach ($rows as $row)
                <div class="rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-3 mb-2">
                        <a href="{{ route('client.org.member', $row['member']['id']) }}" class="font-medium text-ink hover:text-primary">{{ $row['member']['name'] }}</a>
                        <span class="text-xs text-muted">{{ $row['title'] }}</span>
                    </div>
                    <div class="space-y-1.5">
                        @foreach ($row['active'] as $t)
                            <div class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2 text-ink">
                                    <span class="h-2 w-2 rounded-full bg-accent animate-pulse"></span>{{ $t['title'] }}
                                </span>
                                <a href="{{ route('client.runs.result', $t['run']['id']) }}" class="text-xs text-primary">{{ $t['run']['status'] }} →</a>
                            </div>
                        @endforeach
                        @foreach ($row['recent'] as $t)
                            <div class="flex items-center justify-between text-sm text-muted">
                                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-success"></span>{{ $t['title'] }}</span>
                                <a href="{{ route('client.runs.result', $t['run']['id']) }}" class="text-xs text-primary">Резултат →</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
