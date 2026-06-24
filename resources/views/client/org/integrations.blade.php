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
    @include('client.org._lens-tabs', ['active' => null])

    <h1 class="text-2xl font-semibold text-ink mb-1">Интеграции · Инвентар</h1>
    <p class="text-muted mb-6">Свързаните системи, които екипът ползва за реални действия.</p>

    {{-- act гейт статус (§B2) --}}
    @if (! $actEnabled)
        <x-alert class="mb-6">
            <strong>Реалните действия (act) са изключени</strong> в preview режим. Задачите тип
            „act" произвеждат <em>чернова на действието</em> за преглед, без реален страничен ефект.
            Реалните write-ове се отключват с истински вход (Фаза 6).
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

    {{-- act задачи, които ще ги ползват --}}
    <section>
        <h2 class="text-sm font-semibold text-ink mb-3">Задачи с реални действия (act)</h2>
        @if ($actTasks->isEmpty())
            <p class="text-sm text-subtle">Няма act задачи. Всички задачи са в режим „чернова".</p>
        @else
            <div class="rounded-xl border border-line bg-surface divide-y divide-line">
                @foreach ($actTasks as $task)
                    <div class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p class="font-medium text-ink">{{ $task->title }}</p>
                            <p class="text-xs text-muted">{{ $task->orgMember->persona?->name ?? $task->orgMember->display_name }} · {{ $task->act_mode }} · {{ $task->approval_policy }}</p>
                        </div>
                        <a href="{{ route('client.org.member', $task->org_member_id) }}" class="text-sm text-primary font-medium">Отвори →</a>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
