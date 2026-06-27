@extends('layouts.client')

@section('title', 'Хроника')

@section('content')
@php
    $typeTone = [
        'hire' => ['text-success-strong', 'Наемане'],
        'fire' => ['text-danger', 'Уволнение'],
        'reassign' => ['text-char-blue-strong', 'Преназначаване'],
        'mandate_change' => ['text-char-amber-strong', 'Мандат/ниво'],
        'approval' => ['text-primary', 'Одобрение'],
        'action' => ['text-char-teal-strong', 'Действие'],
        'review' => ['text-muted', 'Ревю'],
        'task_proposed' => ['text-char-amber-strong', 'Предложена задача'],
        'task_approved' => ['text-success-strong', 'Одобрена задача'],
        'task_rejected' => ['text-danger', 'Отхвърлена задача'],
        'task_completed' => ['text-success-strong', 'Изпълнена задача'],
        'task_failed' => ['text-danger', 'Провалена задача'],
        'flow_activated' => ['text-primary', 'Активиран flow'],
        'knowledge_added' => ['text-char-teal-strong', 'Ново знание'],
        'daily_digest' => ['text-muted', 'Дневен преглед'],
    ];
    $grouped = $events->groupBy(fn ($e) => optional($e->created_at)->format('Y-m-d') ?? '');
@endphp
<div x-data="{ busy: false, msg: '', filter: 'all' }">
    <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Хроника на организацията</h1>
            <p class="text-muted mt-1">Смислена история на реалните събития — предложения, решения, изпълнения, знание.</p>
        </div>
        <div class="flex items-center gap-2">
            <select x-model="filter" class="text-sm rounded-md border border-line bg-surface px-2 py-1.5">
                <option value="all">Всички типове</option>
                <option value="task">Задачи</option>
                <option value="hire">Екип</option>
                <option value="review">Ревюта</option>
                <option value="knowledge_added">Знание</option>
            </select>
            <x-button size="sm" variant="secondary" x-bind:disabled="busy"
                x-on:click="busy = true; fetch('{{ route('client.org.review') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } }).then(r => r.json()).then(d => { msg = d.message || ''; busy = false; })">
                Пусни ревю сега</x-button>
        </div>
    </div>
    <p x-show="msg" x-text="msg" class="text-sm text-success-strong mb-4"></p>

    @if ($events->isEmpty())
        <x-empty-state title="Празна история" description="Събитията се появяват тук с развитието на организацията." />
    @else
        <div class="space-y-6 max-w-3xl">
            @foreach ($grouped as $day => $dayEvents)
                <div>
                    <p class="text-xs font-semibold text-subtle uppercase tracking-wider mb-3">
                        {{ $day ? \Illuminate\Support\Carbon::parse($day)->isoFormat('D MMMM YYYY') : '' }}
                    </p>
                    <ol class="relative border-s border-line ms-3 space-y-5">
                        @foreach ($dayEvents as $event)
                            @php($tone = $typeTone[$event->type] ?? ['text-muted', $event->type])
                            @php($group = \Illuminate\Support\Str::startsWith($event->type, 'task') ? 'task' : $event->type)
                            <li class="ms-5" x-show="filter === 'all' || filter === '{{ $group }}'">
                                <span class="absolute -start-1.5 mt-1 h-3 w-3 rounded-full bg-surface ring-2 ring-line"></span>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium {{ $tone[0] }}">{{ $tone[1] }}</span>
                                    <span class="text-xs text-subtle tabular-nums">{{ $event->created_at?->format('H:i') }}</span>
                                    @if ($event->actor)<span class="text-xs text-subtle">· {{ $event->actor }}</span>@endif
                                </div>
                                <p class="text-sm text-ink mt-0.5"><x-prose :text="$event->summary" inline /></p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
