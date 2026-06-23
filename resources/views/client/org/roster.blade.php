@extends('layouts.client')

@section('title', 'Екип')

@section('content')
<div class="max-w-6xl mx-auto px-6 py-8">
    @include('client.org._lens-tabs', ['active' => 'roster'])

    @if (! $graph['version'])
        <x-empty-state title="Още нямаш екип" description="Управителят трябва да проектира организацията.">
            <x-button :href="route('client.org.design.review')">Проектирай екипа</x-button>
        </x-empty-state>
    @else
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
                                @include('client.org._persona-card', ['m' => $dir['member']])
                            </div>
                            <div>
                                <p class="text-xs text-muted mb-2">{{ $assistants->count() }} {{ $assistants->count() === 1 ? 'асистент' : 'асистенти' }}</p>
                                <div class="grid sm:grid-cols-2 gap-3">
                                    @forelse ($assistants as $a)
                                        @include('client.org._persona-card', ['m' => $a['member']])
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
