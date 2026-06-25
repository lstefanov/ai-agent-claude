@extends('layouts.client')

@section('title', $flow->name)

@section('content')
@php
    $statusMap = [
        'completed' => ['Завършен', 'success'],
        'failed' => ['Неуспешен', 'danger'],
        'running' => ['Изпълнява се', 'info'],
        'pending' => ['Изчаква', 'neutral'],
        'waiting_approval' => ['Изчаква преглед', 'warning'],
    ];
    $fmtDuration = function ($run) {
        if (! $run->started_at || ! $run->completed_at) return '—';
        $s = $run->completed_at->diffInSeconds($run->started_at);
        return $s >= 60 ? intdiv($s, 60).'м '.($s % 60).'с' : $s.'с';
    };
@endphp

<div class="max-w-3xl mx-auto">
    {{-- Хедър + изпълнение --}}
    <div x-data="flowCard({ runUrl: '{{ route('client.flows.run', $flow) }}' })">
        <a href="{{ route('client.flows.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
            <x-icon name="arrow-left" size="4" /> Моите Flows
        </a>

        <div class="flex items-start justify-between gap-4 mt-2 mb-5">
            <h1 class="text-2xl font-display font-bold text-ink">{{ $flow->name }}</h1>

            {{-- Голям бутон „Изпълни" + състояния --}}
            <div class="shrink-0">
                <div x-show="state==='idle'">
                    <x-button x-on:click="run()" icon="play">Изпълни</x-button>
                </div>
                <div x-show="state==='done'" x-cloak>
                    <a x-bind:href="resultUrl" class="inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover transition">
                        <x-icon name="document-text" size="4" /> Резултат
                    </a>
                </div>
                <div x-show="state==='failed'" x-cloak>
                    <x-button variant="secondary" x-on:click="run()">Опитай пак</x-button>
                </div>
            </div>
        </div>

        {{-- running / under_review ленти --}}
        <div x-show="state==='running'" x-cloak class="mb-5">
            <x-card>
                <div class="flex items-center justify-between text-sm text-muted mb-2">
                    <span x-text="stepTotal ? ('Стъпка ' + stepIndex + '/' + stepTotal + ' · ' + stepLabel) : stepLabel"></span>
                    <span class="tabular-nums" x-text="percent + '%'"></span>
                </div>
                <div class="h-2 rounded-full bg-surface-subtle overflow-hidden">
                    <div class="h-full bg-primary transition-all duration-500" :style="`width:${percent}%`"></div>
                </div>
            </x-card>
        </div>
        <div x-show="state==='under_review'" x-cloak class="mb-5">
            <x-alert type="warning" :dismissible="false">Изпълнението изисква преглед от човек. Ще продължи след одобрение.</x-alert>
        </div>
        <div x-show="state==='failed'" x-cloak class="mb-5">
            <x-alert type="error" :dismissible="false"><span x-text="errorMsg"></span></x-alert>
        </div>
    </div>

    {{-- Описание (inline edit) --}}
    <div x-data="{ editing: false }" class="mb-6">
        <x-card>
            <div class="flex items-center justify-between gap-3 mb-3">
                <h2 class="text-sm font-semibold text-ink">Описание</h2>
                <button type="button" x-show="!editing" @click="editing = true"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:text-primary-hover transition">
                    <x-icon name="pencil-square" size="4" /> Редактирай описанието
                </button>
            </div>

            <p x-show="!editing" class="text-sm text-muted whitespace-pre-line">{{ $flow->description ?: 'Без описание' }}</p>

            <form x-show="editing" x-cloak action="{{ route('client.flows.update-description', $flow) }}" method="POST" class="space-y-3">
                @csrf
                @method('PUT')
                <x-textarea name="description" rows="6" required>{{ old('description', $flow->description) }}</x-textarea>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm">Запази</x-button>
                    <x-button type="button" variant="secondary" size="sm" @click="editing = false">Откажи</x-button>
                </div>
            </form>
        </x-card>
    </div>

    {{-- История на изпълненията --}}
    <h2 class="text-sm font-semibold text-ink mb-3">История на изпълненията</h2>
    @if($runs->isEmpty())
        <x-card>
            <p class="text-sm text-muted text-center py-4">Още няма изпълнения на този Flow.</p>
        </x-card>
    @else
        <x-card :padding="false">
            <div class="divide-y divide-line">
                @foreach($runs as $run)
                    @php([$rLabel, $rColor] = $statusMap[$run->status] ?? [$run->status, 'neutral'])
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="text-sm text-ink">{{ $run->created_at?->format('d.m.Y H:i') }}</p>
                            <p class="text-xs text-subtle">Времетраене: {{ $fmtDuration($run) }}</p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <x-badge :color="$rColor">{{ $rLabel }}</x-badge>
                            @if($run->status === 'completed')
                                <a href="{{ route('client.runs.result', $run) }}" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:text-primary-hover">
                                    Резултат <x-icon name="arrow-right" size="4" />
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    @include('client.partials.run-card-script')
</div>
@endsection
