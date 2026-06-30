@extends('layouts.client')

@section('title', 'Интеграции')

@section('content')
@php
    $statusTone = [
        'active' => ['text-success-strong', 'bg-success-soft'],
        'expired' => ['text-warning-strong', 'bg-warning-soft'],
        'error' => ['text-danger-strong', 'bg-danger-soft'],
        'revoked' => ['text-muted', 'bg-surface-subtle'],
    ];
@endphp
<div class="max-w-4xl mx-auto px-6 py-8">
    <h1 class="text-2xl font-semibold text-ink mb-1">Интеграции · Инвентар</h1>
    <p class="text-muted mb-6">Свързаните системи, които екипът ползва за реални действия.</p>

    {{-- Статус на реалните действия (§B2) --}}
    @if (! $actEnabled)
        <x-alert class="mb-6">
            <strong>Реалните действия са изключени</strong> в режим за преглед. Задачите с реални действия
            произвеждат <em>чернова на действието</em> за преглед, без реален страничен ефект.
            Реалното писане към външни системи се отключва с истински вход.
        </x-alert>
    @endif

    {{-- Конектори --}}
    <section class="mb-8">
        <h2 class="text-sm font-semibold text-ink mb-3">Конектори</h2>
        @if ($connectors->isEmpty())
            <x-empty-state title="Няма свързани конектори" description="Свържи система (Gmail, Sheets, Slack…), за да позволиш реални действия." />
        @else
            <div class="rounded-xl border border-line bg-surface divide-y divide-line">
                @foreach ($connectors as $conn)
                    @php($tone = $statusTone[$conn->status] ?? ['text-muted', 'bg-surface-subtle'])
                    <div class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p class="font-medium text-ink">{{ $conn->display_name ?: $conn->connector_type }}</p>
                            <p class="text-xs text-muted">{{ $conn->connector_type }} · {{ $conn->auth_type }}</p>
                        </div>
                        <span class="text-xs font-medium rounded-full px-2.5 py-1 {{ $tone[0] }} {{ $tone[1] }}">{{ $conn->status }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Задачи с реални действия, които ще ги ползват --}}
    <section>
        <h2 class="text-sm font-semibold text-ink mb-3">Задачи с реални действия</h2>
        @if ($actTasks->isEmpty())
            <p class="text-sm text-subtle">Няма задачи с реални действия. Всички задачи са в режим „чернова".</p>
        @else
            <div class="rounded-xl border border-line bg-surface divide-y divide-line">
                @foreach ($actTasks as $task)
                    @php
                        $actLabels = ['draft' => 'чернова', 'act' => 'действие', 'mixed' => 'смесен режим'];
                        $approvalLabels = ['auto' => 'самостоятелно', 'approve_first_then_auto' => 'първо одобрение', 'approve_each' => 'всяко действие с одобрение'];
                    @endphp
                    <div class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p class="font-medium text-ink">{{ $task->title }}</p>
                            <p class="text-xs text-muted">{{ $task->orgMember->persona?->name ?? $task->orgMember->display_name }} · {{ $actLabels[$task->act_mode] ?? $task->act_mode }} · {{ $approvalLabels[$task->approval_policy] ?? $task->approval_policy }}</p>
                        </div>
                        <a href="{{ route('client.org.member', $task->org_member_id) }}" class="text-sm text-primary font-medium">Отвори →</a>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
