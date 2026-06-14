@extends('layouts.app')

@section('title', 'Агенти — ' . $company->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.show', $company) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
        <x-icon name="arrow-left" size="4" /> {{ $company->name }}
    </a>
</div>
<div class="flex items-start justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-display font-bold text-ink">Агенти на компанията</h1>
        <p class="text-sm text-muted mt-1">Шаблони достъпни само за {{ $company->name }}</p>
    </div>
    <x-button size="sm" icon="plus" :href="route('companies.agent-templates.create', $company)">Нов агент шаблон</x-button>
</div>

@if($templates->isEmpty())
    <x-card :padding="false">
        <x-empty-state icon="cpu-chip" title="Няма агент шаблони за тази компания">
            <x-button size="sm" :href="route('companies.agent-templates.create', $company)" icon="plus">Добави първия</x-button>
        </x-empty-state>
    </x-card>
@else
    <div class="bg-surface border border-line rounded-xl overflow-hidden">
        @foreach($templates as $template)
        <div class="flex items-center gap-4 px-5 py-4 border-b border-line last:border-0 hover:bg-surface-subtle transition">
            <span class="text-2xl w-8 text-center shrink-0">{{ $template->icon }}</span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-medium text-ink text-sm">{{ $template->name }}</span>
                    <span class="text-xs font-mono bg-info-soft text-info-strong px-1.5 py-0.5 rounded">{{ $template->type }}</span>
                </div>
                <p class="text-xs text-muted truncate">{{ $template->description }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <x-button size="sm" variant="secondary" icon="pencil-square" :href="route('companies.agent-templates.edit', [$company, $template])">Редактирай</x-button>
                <form action="{{ route('companies.agent-templates.destroy', [$company, $template]) }}" method="POST"
                      onsubmit="return confirm('Изтрий шаблон {{ $template->name }}?')">
                    @csrf @method('DELETE')
                    <x-button size="sm" variant="danger-outline" type="submit" icon="trash">Изтрий</x-button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
