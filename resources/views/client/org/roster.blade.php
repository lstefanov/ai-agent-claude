@extends('layouts.client')

@section('title', 'Екип')

@section('content')
<div>
    @include('client.org._lens-tabs', ['active' => 'roster'])

    @if (! $graph['version'])
        <x-empty-state title="Още нямаш екип" description="Управителят трябва да проектира организацията.">
            <x-button :href="route('client.org.design.review')">Проектирай екипа</x-button>
        </x-empty-state>
    @else
        {{-- Запалване / чакащи решения (§F видимост) --}}
        @if (($graph['igniting'] ?? 0) > 0)
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-accent/30 bg-accent/5 px-4 py-3">
                <span class="h-2 w-2 rounded-full bg-accent animate-pulse"></span>
                <p class="text-sm text-muted">Планът се генерира — <span class="font-semibold text-ink tabular-nums">{{ $graph['igniting'] }}</span> {{ $graph['igniting'] === 1 ? 'задача се подготвя' : 'задачи се подготвят' }}. Решенията ще се появят в Кутията.</p>
            </div>
        @elseif (($graph['decisions']['total'] ?? 0) > 0)
            <a href="{{ route('client.org.decisions') }}" class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-line bg-surface-subtle/40 px-4 py-3 hover:bg-surface-subtle/70 transition">
                <p class="text-sm text-muted"><span class="font-semibold text-ink tabular-nums">{{ $graph['decisions']['total'] }}</span> {{ $graph['decisions']['total'] === 1 ? 'решение чака' : 'решения чакат' }} одобрение</p>
                <span class="text-xs font-mono uppercase tracking-wider text-accent">Към Кутията →</span>
            </a>
        @endif

        {{-- Управител (hero) --}}
        @if ($graph['manager'])
            <section class="mb-8">
                <h2 class="text-xs font-mono uppercase tracking-wider text-muted mb-2">Управител</h2>
                <div class="max-w-md">@include('client.org._persona-card', ['m' => $graph['manager']])</div>
            </section>
        @endif

        {{-- Директори и техните асистенти --}}
        <section>
            <h2 class="text-xs font-mono uppercase tracking-wider text-muted mb-3">Директори · кой кого управлява</h2>
            <div class="space-y-8">
                @foreach ($graph['directors'] as $dir)
                    @php($assistants = collect($graph['assistants'])->where('director_id', $dir['placement_id']))
                    <div class="rounded-xl border border-line bg-surface-subtle/40 p-4">
                        <div class="grid lg:grid-cols-[300px_1fr] gap-5">
                            <div>
                                <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1">{{ $dir['domain'] }}</p>
                                @if (! empty($dir['mandate']))
                                    <p class="text-sm text-muted mb-2">{{ $dir['mandate'] }}</p>
                                @endif
                                @if (! empty($dir['priorities']))
                                    <div class="flex flex-wrap gap-1.5 mb-3">
                                        @foreach ($dir['priorities'] as $p)
                                            <span class="inline-flex items-center rounded-full border border-line bg-surface px-2.5 py-1 text-xs text-muted">{{ $p }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @include('client.org._persona-card', ['m' => $dir['member']])
                            </div>
                            <div>
                                <p class="text-xs text-muted mb-2 flex flex-wrap items-center gap-x-2">
                                    <span>{{ $dir['stats']['assistants_count'] }} {{ $dir['stats']['assistants_count'] === 1 ? 'асистент' : 'асистенти' }}</span>
                                    <span class="text-subtle">·</span>
                                    <span><span class="font-semibold text-ink tabular-nums">{{ $dir['stats']['flows_total'] }}</span> flows</span>
                                    @if ($dir['stats']['active'] > 0)
                                        <span class="inline-flex items-center gap-1 text-accent"><span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>{{ $dir['stats']['active'] }} активни</span>
                                    @endif
                                </p>
                                <div class="grid sm:grid-cols-2 gap-3">
                                    @forelse ($assistants as $a)
                                        @include('client.org._persona-card', ['m' => $a['member'], 'stats' => $a['stats']])
                                    @empty
                                        <p class="text-sm text-subtle col-span-full">Няма асистенти.</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
