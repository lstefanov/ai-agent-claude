@extends('layouts.app')

@section('title', $company->name)

@section('content')
<div class="flex items-start justify-between gap-4 mb-6">
    <div class="min-w-0">
        <a href="{{ route('companies.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
            <x-icon name="arrow-left" size="4" /> Всички фирми
        </a>
        <h1 class="text-2xl font-display font-bold text-ink mt-2 flex items-center gap-3 flex-wrap">
            {{ $company->name }}
            <x-badge color="info" class="uppercase">{{ $company->language }}</x-badge>
        </h1>
        <p class="text-muted mt-1 text-sm">{{ $company->industry }}</p>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <x-button variant="secondary" size="sm" icon="pencil-square" :href="route('companies.edit', $company)">Редактирай</x-button>
        <x-button variant="secondary" size="sm" icon="cpu-chip" :href="route('companies.agent-templates.index', $company)">Агенти</x-button>
        <form action="{{ route('companies.destroy', $company) }}" method="POST"
              onsubmit="return confirm('Сигурен ли си? Ще се изтрият и всички flows!')">
            @csrf @method('DELETE')
            <x-button variant="danger-outline" size="sm" type="submit" icon="trash">Изтрий</x-button>
        </form>
    </div>
</div>

{{-- Вход към AI организацията: порталът е на отделен субдомейн с отделна сесия,
     затова подписан auto-login като owner → право в org онбординга/roster-а. --}}
<a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('client.enter', now()->addMinutes(30), ['company' => $company->id]) }}"
   target="_blank" rel="noopener"
   class="group flex items-center gap-4 bg-primary text-primary-fg rounded-xl px-6 py-4 mb-6 hover:bg-primary-hover shadow-card transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
    <span class="flex items-center justify-center w-11 h-11 rounded-lg bg-white/15 shrink-0">
        <x-icon name="users" size="6" />
    </span>
    <span class="min-w-0">
        <span class="block font-display font-semibold text-base">Отвори организацията</span>
        <span class="block text-sm text-primary-fg/80">Управител, директори, асистенти и задачи в клиентския портал →</span>
    </span>
    <x-icon name="arrow-right" size="5" class="ml-auto opacity-80 group-hover:translate-x-0.5 transition" />
</a>

@if($company->description)
<div class="bg-surface border border-line rounded-xl px-6 py-4 mb-6">
    <p class="text-sm text-muted">{{ $company->description }}</p>
</div>
@endif

{{-- База знания + Свързани системи --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
    <a href="{{ route('companies.knowledge.index', $company) }}"
       class="group flex items-center gap-3 bg-surface border border-line rounded-xl px-5 py-4 hover:border-line-strong hover:shadow-card transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
        <span class="flex items-center justify-center w-10 h-10 rounded-lg bg-info-soft text-info-strong shrink-0">
            <x-icon name="book-open" size="5" />
        </span>
        <span class="min-w-0">
            <span class="block font-medium text-ink">База знания</span>
            <span class="block text-xs text-muted truncate">{{ $knowledgeStats['documents'] }} ресурса · {{ $knowledgeStats['chunks'] }} откъса · {{ $knowledgeStats['facts'] }} факта</span>
        </span>
        <x-icon name="arrow-right" size="4" class="ml-auto text-subtle group-hover:text-primary transition" />
    </a>

    <a href="{{ route('companies.connectors.index', $company) }}"
       class="group flex items-center gap-3 bg-surface border border-line rounded-xl px-5 py-4 hover:border-line-strong hover:shadow-card transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
        <span class="flex items-center justify-center w-10 h-10 rounded-lg bg-accent-soft text-accent-strong shrink-0">
            <x-icon name="puzzle-piece" size="5" />
        </span>
        <span class="min-w-0">
            <span class="block font-medium text-ink">Свързани системи</span>
            <span class="block text-xs text-muted truncate">Gmail, Notion, HTTP API — агентите действат в реални системи</span>
        </span>
        <x-icon name="arrow-right" size="4" class="ml-auto text-subtle group-hover:text-primary transition" />
    </a>
</div>

{{-- Flows --}}
<div class="flex items-center justify-between gap-4 mb-4">
    <h2 class="text-lg font-display font-semibold text-ink">
        Flows <span class="ml-1 text-sm font-normal text-subtle tabular-nums">({{ $flows->count() }})</span>
    </h2>
    <x-button size="sm" icon="plus" :href="route('companies.flows.create', $company)">Нов flow</x-button>
</div>

@if($flows->isEmpty())
    <x-card :padding="false" class="mb-6">
        <x-empty-state icon="bolt" title="Няма добавени flows"
            message="Създай flow и FlowAI ще генерира агентите автоматично">
            <x-button size="sm" :href="route('companies.flows.create', $company)" icon="sparkles">Създай първия flow с AI</x-button>
        </x-empty-state>
    </x-card>
@else
    <div class="space-y-2 mb-6">
        @foreach($flows as $flow)
        <div class="group bg-surface border border-line rounded-xl px-5 py-4 flex items-start gap-4 hover:border-line-strong hover:shadow-card transition">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-0.5 flex-wrap">
                    <h3 class="font-medium text-ink">{{ $flow->name }}</h3>
                    @include('partials.status-badge', ['status' => $flow->status])
                </div>
                <p class="text-sm text-muted line-clamp-1">{{ $flow->description }}</p>

                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-subtle">
                    <span><span class="font-medium text-muted tabular-nums">{{ $flow->versions_count }}</span> {{ $flow->versions_count == 1 ? 'шаблон' : 'шаблона' }}</span>
                    <span><span class="font-medium text-success tabular-nums">{{ $flow->successful_runs_count }}</span> успешни</span>
                    <span><span class="font-medium text-danger tabular-nums">{{ $flow->failed_runs_count }}</span> неуспешни</span>
                    <span>Създаден: {{ $flow->created_at->format('d.m.Y') }}</span>
                    <span>Последен run: {{ $flow->last_run_at ? $flow->last_run_at->diffForHumans() : '—' }}</span>
                    <span>Разход: <span class="font-medium text-muted tabular-nums">${{ number_format($flow->total_cost_usd ?? 0, 4) }}</span></span>
                </div>
            </div>

            <div class="flex items-center gap-1 shrink-0">
                <x-button size="sm" :href="route('flows.show', $flow)">Отвори</x-button>
                <form action="{{ route('flows.archive', $flow) }}" method="POST" onsubmit="return confirm('Архивирай „{{ $flow->name }}“?')">
                    @csrf
                    <button type="submit" title="Архивирай" aria-label="Архивирай"
                            class="p-2 rounded-md text-subtle hover:text-warning hover:bg-warning-soft transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                        <x-icon name="archive-box" size="4" />
                    </button>
                </form>
                <form action="{{ route('flows.destroy', $flow) }}" method="POST" onsubmit="return confirm('Изтрий „{{ $flow->name }}“ завинаги? Всички агенти и runs ще се изтрият!')">
                    @csrf @method('DELETE')
                    <button type="submit" title="Изтрий" aria-label="Изтрий"
                            class="p-2 rounded-md text-subtle hover:text-danger hover:bg-danger-soft transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                        <x-icon name="trash" size="4" />
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
@endif

{{-- Архивирани flows --}}
@if($archivedFlows->isNotEmpty())
<div x-data="{ open: false }" class="mt-2">
    <button @click="open = !open"
            class="flex items-center gap-2 text-sm text-subtle hover:text-ink transition mb-3 select-none">
        <x-icon name="chevron-right" size="4" ::class="open ? 'rotate-90' : ''" class="transition-transform" />
        <x-icon name="archive-box" size="4" class="text-warning" />
        Архивирани flows
        <x-badge color="warning" class="tabular-nums">{{ $archivedFlows->count() }}</x-badge>
    </button>

    <div x-show="open" x-cloak x-transition class="space-y-2">
        @foreach($archivedFlows as $flow)
        <div class="group bg-surface-subtle border border-line rounded-xl px-5 py-4 flex items-start gap-4 opacity-80 hover:opacity-100 transition">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-0.5 flex-wrap">
                    <h3 class="font-medium text-muted">{{ $flow->name }}</h3>
                    <x-badge color="warning">Архивиран</x-badge>
                </div>
                <p class="text-sm text-subtle line-clamp-1">{{ $flow->description }}</p>
                @if($flow->archived_at)
                    <p class="text-xs text-subtle mt-0.5">Архивиран {{ $flow->archived_at->diffForHumans() }}</p>
                @endif

                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-subtle">
                    <span><span class="font-medium text-muted tabular-nums">{{ $flow->versions_count }}</span> {{ $flow->versions_count == 1 ? 'шаблон' : 'шаблона' }}</span>
                    <span><span class="font-medium text-success tabular-nums">{{ $flow->successful_runs_count }}</span> успешни</span>
                    <span><span class="font-medium text-danger tabular-nums">{{ $flow->failed_runs_count }}</span> неуспешни</span>
                    <span>Създаден: {{ $flow->created_at->format('d.m.Y') }}</span>
                    <span>Последен run: {{ $flow->last_run_at ? $flow->last_run_at->diffForHumans() : '—' }}</span>
                    <span>Разход: <span class="font-medium text-muted tabular-nums">${{ number_format($flow->total_cost_usd ?? 0, 4) }}</span></span>
                </div>
            </div>

            <div class="flex items-center gap-1 shrink-0">
                <x-button size="sm" variant="secondary" :href="route('flows.show', $flow)">Преглед</x-button>
                <form action="{{ route('flows.unarchive', $flow) }}" method="POST">
                    @csrf
                    <button type="submit" title="Възстанови" aria-label="Възстанови"
                            class="p-2 rounded-md text-subtle hover:text-success hover:bg-success-soft transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                        <x-icon name="arrow-uturn-left" size="4" />
                    </button>
                </form>
                <form action="{{ route('flows.destroy', $flow) }}" method="POST" onsubmit="return confirm('Изтрий „{{ $flow->name }}“ завинаги? Действието е необратимо!')">
                    @csrf @method('DELETE')
                    <button type="submit" title="Изтрий завинаги" aria-label="Изтрий завинаги"
                            class="p-2 rounded-md text-subtle hover:text-danger hover:bg-danger-soft transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                        <x-icon name="trash" size="4" />
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

@endsection
