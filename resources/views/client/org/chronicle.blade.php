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
    ];
@endphp
<div class="max-w-3xl mx-auto px-6 py-8" x-data="{ busy: false, msg: '' }">
    @include('client.org._lens-tabs', ['active' => null])

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Хроника на организацията</h1>
            <p class="text-muted mt-1">Кой какво свърши — наемания, уволнения, действия, ревюта.</p>
        </div>
        <x-button size="sm" variant="secondary" x-bind:disabled="busy"
            x-on:click="busy = true; fetch('{{ route('client.org.review') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } }).then(r => r.json()).then(d => { msg = d.message || ''; busy = false; })">
            Пусни ревю сега</x-button>
    </div>
    <p x-show="msg" x-text="msg" class="text-sm text-success-strong mb-4"></p>

    @if ($events->isEmpty())
        <x-empty-state title="Празна история" description="Събитията се появяват тук с развитието на организацията." />
    @else
        <ol class="relative border-s border-line ms-3 space-y-5">
            @foreach ($events as $event)
                @php($tone = $typeTone[$event->type] ?? ['text-muted', $event->type])
                <li class="ms-5">
                    <span class="absolute -start-1.5 mt-1 h-3 w-3 rounded-full bg-surface ring-2 ring-line"></span>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium {{ $tone[0] }}">{{ $tone[1] }}</span>
                        <span class="text-xs text-subtle tabular-nums">{{ $event->created_at?->format('d.m.Y H:i') }}</span>
                        @if ($event->actor)<span class="text-xs text-subtle">· {{ $event->actor }}</span>@endif
                    </div>
                    <p class="text-sm text-ink mt-0.5">{{ $event->summary }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</div>
@endsection
