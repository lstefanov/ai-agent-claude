@extends('layouts.app')

@section('title', 'Фирми')

@section('content')
<div class="flex items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-display font-bold text-ink tracking-tight">Фирми</h1>
        <p class="text-muted mt-1 text-sm">Управление на фирми и техните AI flows</p>
    </div>
    <x-button :href="route('companies.create')" icon="plus">Добави фирма</x-button>
</div>

@if($companies->isEmpty())
    <x-card :padding="false">
        <x-empty-state icon="building-office-2" title="Все още няма добавени фирми"
            message="Добави фирма и създай първия си AI flow">
            <x-button :href="route('companies.create')" icon="plus">Добави първата фирма</x-button>
        </x-empty-state>
    </x-card>
@else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($companies as $company)
        <a href="{{ route('companies.show', $company) }}"
           class="group flex flex-col bg-surface border border-line rounded-xl shadow-card hover:border-line-strong hover:shadow-popover transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
            <div class="p-5 flex flex-col flex-1">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <h2 class="font-display font-semibold text-ink leading-tight">{{ $company->name }}</h2>
                    <x-badge color="neutral" class="uppercase shrink-0">{{ $company->language }}</x-badge>
                </div>
                @if($company->industry)
                    <p class="text-xs font-medium text-primary mb-2">{{ $company->industry }}</p>
                @endif
                <p class="text-sm text-muted line-clamp-2 flex-1">{{ $company->description ?: 'Без описание' }}</p>
            </div>
            <div class="flex items-center justify-between px-5 py-3 border-t border-line">
                <span class="inline-flex items-center gap-1.5 text-sm text-subtle">
                    <x-icon name="bolt" size="4" /><span class="tabular-nums">{{ $company->flows_count }}</span> {{ $company->flows_count === 1 ? 'flow' : 'flows' }}
                </span>
                <span class="inline-flex items-center gap-1 text-sm font-medium text-primary">
                    Отвори <x-icon name="arrow-right" size="4" />
                </span>
            </div>
        </a>
        @endforeach
    </div>
@endif
@endsection
