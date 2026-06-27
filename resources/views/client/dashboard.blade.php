@extends('layouts.client')

@section('title', 'Табло')

@section('content')
@php
    use Illuminate\Support\Carbon;
    $stat = [
        ['label' => 'Брой Flows', 'value' => $stats['flows_count'], 'icon' => 'bolt'],
        ['label' => 'Общо изпълнения', 'value' => $stats['runs_count'], 'icon' => 'play'],
        ['label' => 'Последно изпълнение', 'value' => $stats['last_run_at'] ? Carbon::parse($stats['last_run_at'])->diffForHumans() : '—', 'icon' => 'clock'],
    ];
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-display font-bold text-ink">Здравей, {{ $currentCompany->name }}</h1>
    <p class="text-sm text-muted mt-1">Това е твоето табло. Стартирай Flow или създай нов.</p>
</div>

{{-- Стат-карти --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
    @foreach($stat as $s)
        <x-card>
            <div class="flex items-center gap-3">
                <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-info-soft text-primary shrink-0">
                    <x-icon :name="$s['icon']" size="5" />
                </div>
                <div class="min-w-0">
                    <p class="text-xs text-subtle">{{ $s['label'] }}</p>
                    <p class="text-lg font-display font-semibold text-ink truncate">{{ $s['value'] }}</p>
                </div>
            </div>
        </x-card>
    @endforeach
</div>

{{-- Последни Flows --}}
<div class="flex items-center justify-between gap-4 mb-4">
    <h2 class="text-lg font-display font-semibold text-ink">Последни Flows</h2>
    @if($recentFlows->isNotEmpty())
        <a href="{{ route('client.flows.index') }}" class="text-sm font-medium text-primary hover:text-primary-hover inline-flex items-center gap-1">
            Всички <x-icon name="arrow-right" size="4" />
        </a>
    @endif
</div>

@if($recentFlows->isEmpty())
    <x-card>
        <x-empty-state icon="bolt" title="Още нямаш Flows"
                       message="Създай първия си Flow с разговорния асистент — само за минути.">
            <a href="{{ route('client.org.tasks.new') }}"
               class="inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover transition">
                <x-icon name="plus" size="4" /> Нова задача
            </a>
        </x-empty-state>
    </x-card>
@else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($recentFlows as $flow)
            @include('client.partials.flow-run-card')
        @endforeach
    </div>
@endif
@endsection
