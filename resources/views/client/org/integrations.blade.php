@extends('layouts.client')

@section('title', 'Интеграции')

@section('content')
<div class="max-w-4xl mx-auto px-6 py-8">
    <h1 class="text-2xl font-semibold text-ink mb-1">Интеграции</h1>
    <p class="text-muted mb-6">Свържи системите, от които екипът дърпа информация и в които действа — Gmail, Google Sheets, Drive, Docs, Calendar.</p>

    {{-- Статус на реалните действия (§B2) --}}
    @if (! $actEnabled)
        <x-alert class="mb-6">
            <strong>Реалните действия са изключени</strong> в режим за преглед. Задачите с реални действия
            произвеждат <em>чернова на действието</em> за преглед, без реален страничен ефект.
            Реалното писане към външни системи се отключва с истински вход.
        </x-alert>
    @endif

    {{-- Конектори — свързване + управление (общ панел с админа) --}}
    @include('partials.connectors-manager', ['config' => $config])

    {{-- Задачи с реални действия, които ще ги ползват --}}
    <section class="mt-10">
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
