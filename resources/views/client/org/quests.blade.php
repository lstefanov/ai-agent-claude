@extends('layouts.client')

@section('title', 'Куестове')

@section('content')
@php
    $levels = ['low' => '★', 'medium' => '★★', 'high' => '★★★', 'ultra' => '★★★★', 'god' => '★★★★★'];
    $statusTone = [
        'running' => 'text-accent', 'ready' => 'text-success-strong', 'generating' => 'text-warning-strong',
        'proposed' => 'text-muted', 'failed' => 'text-danger', 'disabled' => 'text-subtle',
    ];
@endphp
<div class="max-w-5xl mx-auto px-6 py-8">
    @include('client.org._lens-tabs', ['active' => null])

    <h1 class="text-2xl font-semibold text-ink mb-1">Дневник на куестове</h1>
    <p class="text-muted mb-6">Задачите на екипа — изпълнител, ниво, статус. Отвори героя, за да генерираш или изпълниш.</p>

    @if ($tasks->isEmpty())
        <x-empty-state title="Още няма задачи" description="Управителят предлага задачи при дизайна на екипа.">
            <x-button :href="route('client.org.roster')">Към екипа</x-button>
        </x-empty-state>
    @else
        <div class="rounded-xl border border-line bg-surface divide-y divide-line">
            @foreach ($tasks as $task)
                <div class="flex items-center justify-between gap-4 p-4">
                    <div class="min-w-0">
                        <p class="font-medium text-ink truncate">{{ $task->title }}</p>
                        <p class="text-xs text-muted">
                            {{ $task->orgMember->persona?->name ?? $task->orgMember->display_name }}
                            · {{ $task->act_mode }}
                            · <span class="tabular-nums text-star">{{ $levels[$task->effectiveStarTier()->value] ?? '★' }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-4 shrink-0">
                        <span class="text-xs font-medium {{ $statusTone[$task->status] ?? 'text-muted' }}">{{ $task->status }}</span>
                        <a href="{{ route('client.org.member', $task->org_member_id) }}" class="text-sm text-primary font-medium hover:text-primary-hover">Отвори →</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
