@extends('layouts.client')

@section('title', 'Моите Flows')

@section('content')
<div class="flex items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-display font-bold text-ink">Моите Flows</h1>
        <p class="text-sm text-muted mt-1">Стартирай готов Flow или създай нов.</p>
    </div>
    <a href="{{ route('client.flows.create') }}"
       class="hidden sm:inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover shadow-card transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
        <x-icon name="plus" size="4" /> Създай нов Flow
    </a>
</div>

@if($flows->isEmpty())
    <x-card>
        <x-empty-state icon="bolt" title="Още нямаш Flows"
                       message="Създай първия си Flow с помощта на разговорния асистент — той ще те преведе през няколко въпроса.">
            <a href="{{ route('client.flows.create') }}"
               class="inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover transition">
                <x-icon name="plus" size="4" /> Създай нов Flow
            </a>
        </x-empty-state>
    </x-card>
@else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($flows as $flow)
            @include('client.partials.flow-run-card')
        @endforeach
    </div>
@endif
@endsection
