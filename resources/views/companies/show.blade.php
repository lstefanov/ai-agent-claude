@extends('layouts.app')

@section('title', $company->name)

@section('content')
<div class="flex items-start justify-between mb-6">
    <div>
        <a href="{{ route('companies.index') }}" class="text-indigo-600 hover:underline text-sm">← Всички фирми</a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3 flex-wrap">
            {{ $company->name }}
            <span class="text-sm bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full font-medium uppercase">
                {{ $company->language }}
            </span>
        </h1>
        <p class="text-gray-500 mt-1">{{ $company->industry }}</p>
    </div>
    <div class="flex gap-2 shrink-0">
        <a href="{{ route('companies.edit', $company) }}"
           class="bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
            ✏ Редактирай
        </a>
        <a href="{{ route('companies.agent-templates.index', $company) }}"
           class="bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
            🤖 Агенти
        </a>
        <form action="{{ route('companies.destroy', $company) }}" method="POST"
              onsubmit="return confirm('Сигурен ли си? Ще се изтрят и всички flows!')">
            @csrf @method('DELETE')
            <button class="bg-white border border-red-200 hover:bg-red-50 text-red-600 px-4 py-2 rounded-lg text-sm font-medium transition">
                Изтрий
            </button>
        </form>
    </div>
</div>

@if($company->description)
<div class="bg-white rounded-xl border border-gray-200 px-6 py-4 mb-4">
    <p class="text-gray-700 text-sm">{{ $company->description }}</p>
</div>
@endif

{{-- ──────────────── База знания ──────────────── --}}
<a href="{{ route('companies.knowledge.index', $company) }}"
   class="group flex items-center gap-3 bg-white rounded-xl border border-gray-200 px-6 py-4 mb-8 hover:shadow-sm hover:border-indigo-200 transition">
    <span class="text-xl">📚</span>
    <span class="font-semibold text-gray-900">База знания</span>
    <span class="text-sm text-gray-500">
        {{ $knowledgeStats['documents'] }} ресурса · {{ $knowledgeStats['chunks'] }} откъса · {{ $knowledgeStats['facts'] }} факта
    </span>
    <span class="ml-auto text-indigo-600 text-sm font-medium group-hover:translate-x-0.5 transition-transform">Отвори →</span>
</a>

{{-- Flash message --}}
@if(session('success'))
<div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
     class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm flex items-center gap-2">
    <span>✓</span> {{ session('success') }}
</div>
@endif

{{-- ──────────────── Active Flows ──────────────── --}}
<div class="flex items-center justify-between mb-4">
    <h2 class="text-xl font-semibold text-gray-900">
        Flows
        <span class="ml-2 text-sm font-normal text-gray-400">({{ $flows->count() }})</span>
    </h2>
    <a href="{{ route('companies.flows.create', $company) }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2">
        ＋ Нов flow
    </a>
</div>

@if($flows->isEmpty())
    <div class="text-center py-14 bg-white rounded-xl border border-dashed border-gray-300 mb-6">
        <p class="text-2xl mb-2">⚡</p>
        <p class="text-gray-500 font-medium mb-2">Няма добавени flows</p>
        <p class="text-gray-400 text-sm mb-4">Създай flow и FlowAI ще генерира агентите автоматично</p>
        <a href="{{ route('companies.flows.create', $company) }}"
           class="inline-flex items-center gap-2 text-indigo-600 hover:underline text-sm font-medium">
            Създай първия flow с AI →
        </a>
    </div>
@else
    <div class="space-y-2 mb-6">
        @foreach($flows as $flow)
        <div class="group bg-white rounded-xl border border-gray-200 px-6 py-4 flex items-center gap-4 hover:shadow-sm transition">
            {{-- Info --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-0.5 flex-wrap">
                    <h3 class="font-semibold text-gray-900">{{ $flow->name }}</h3>
                    @include('partials.status-badge', ['status' => $flow->status])
                </div>
                <p class="text-sm text-gray-500 line-clamp-1">{{ $flow->description }}</p>
            </div>

            {{-- Meta --}}
            <div class="flex items-center gap-4 shrink-0 text-sm text-gray-400">
                <span>{{ $flow->nodes_count }} агенти</span>
                @if($flow->last_run_at)
                    <span class="text-xs">{{ $flow->last_run_at->diffForHumans() }}</span>
                @endif
                <a href="{{ route('flows.show', $flow) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium text-sm">
                    Отвори →
                </a>
            </div>

            {{-- Actions — visible on hover --}}
            <div class="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                {{-- Archive --}}
                <form action="{{ route('flows.archive', $flow) }}" method="POST">
                    @csrf
                    <button type="submit"
                            title="Архивирай"
                            onclick="return confirm('Архивирай „{{ $flow->name }}"?')"
                            class="p-2 rounded-lg text-gray-400 hover:text-amber-600 hover:bg-amber-50 transition"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                        </svg>
                    </button>
                </form>

                {{-- Delete --}}
                <form action="{{ route('flows.destroy', $flow) }}" method="POST">
                    @csrf @method('DELETE')
                    <button type="submit"
                            title="Изтрий"
                            onclick="return confirm('Изтрий „{{ $flow->name }}" завинаги? Всички агенти и runs ще се изтрият!')"
                            class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
@endif

{{-- ──────────────── Archived Flows ──────────────── --}}
@if($archivedFlows->isNotEmpty())
<div x-data="{ open: false }" class="mt-2">
    <button @click="open = !open"
            class="flex items-center gap-2 text-sm text-gray-400 hover:text-gray-600 transition mb-3 select-none">
        <svg xmlns="http://www.w3.org/2000/svg"
             class="h-4 w-4 transition-transform"
             :class="open ? 'rotate-90' : ''"
             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
        </svg>
        Архивирани flows
        <span class="bg-amber-100 text-amber-700 text-xs font-medium px-1.5 py-0.5 rounded-full">
            {{ $archivedFlows->count() }}
        </span>
    </button>

    <div x-show="open" x-transition class="space-y-2">
        @foreach($archivedFlows as $flow)
        <div class="group bg-gray-50 rounded-xl border border-gray-200 px-6 py-4 flex items-center gap-4 opacity-70 hover:opacity-100 transition">
            {{-- Info --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-0.5 flex-wrap">
                    <h3 class="font-semibold text-gray-500">{{ $flow->name }}</h3>
                    <span class="text-xs bg-amber-100 text-amber-600 px-2 py-0.5 rounded-full font-medium">Архивиран</span>
                </div>
                <p class="text-sm text-gray-400 line-clamp-1">{{ $flow->description }}</p>
                @if($flow->archived_at)
                    <p class="text-xs text-gray-400 mt-0.5">Архивиран {{ $flow->archived_at->diffForHumans() }}</p>
                @endif
            </div>

            {{-- Meta --}}
            <div class="flex items-center gap-4 shrink-0 text-sm text-gray-400">
                <span>{{ $flow->nodes_count }} агенти</span>
                <a href="{{ route('flows.show', $flow) }}"
                   class="text-indigo-500 hover:text-indigo-700 font-medium text-sm">
                    Преглед →
                </a>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                {{-- Unarchive --}}
                <form action="{{ route('flows.unarchive', $flow) }}" method="POST">
                    @csrf
                    <button type="submit"
                            title="Възстанови"
                            class="p-2 rounded-lg text-gray-400 hover:text-green-600 hover:bg-green-50 transition"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </form>

                {{-- Delete permanently --}}
                <form action="{{ route('flows.destroy', $flow) }}" method="POST">
                    @csrf @method('DELETE')
                    <button type="submit"
                            title="Изтрий завинаги"
                            onclick="return confirm('Изтрий „{{ $flow->name }}" завинаги? Действието е необратимо!')"
                            class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

@endsection
