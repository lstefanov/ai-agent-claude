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
        <form action="{{ route('companies.destroy', $company) }}" method="POST"
              onsubmit="return confirm('Сигурен ли си? Ще се изтрият и всички flows!')">
            @csrf @method('DELETE')
            <button class="bg-white border border-red-200 hover:bg-red-50 text-red-600 px-4 py-2 rounded-lg text-sm font-medium transition">
                Изтрий
            </button>
        </form>
    </div>
</div>

@if($company->description)
<div class="bg-white rounded-xl border border-gray-200 px-6 py-4 mb-8">
    <p class="text-gray-700 text-sm">{{ $company->description }}</p>
</div>
@endif

{{-- Flows --}}
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
    <div class="text-center py-14 bg-white rounded-xl border border-dashed border-gray-300">
        <p class="text-2xl mb-2">⚡</p>
        <p class="text-gray-500 font-medium mb-2">Няма добавени flows</p>
        <p class="text-gray-400 text-sm mb-4">Създай flow и FlowAI ще генерира агентите автоматично</p>
        <a href="{{ route('companies.flows.create', $company) }}"
           class="inline-flex items-center gap-2 text-indigo-600 hover:underline text-sm font-medium">
            Създай първия flow с AI →
        </a>
    </div>
@else
    <div class="space-y-2">
        @foreach($flows as $flow)
        <div class="bg-white rounded-xl border border-gray-200 px-6 py-4 flex items-center gap-4 hover:shadow-sm transition">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-0.5 flex-wrap">
                    <h3 class="font-semibold text-gray-900">{{ $flow->name }}</h3>
                    @include('partials.status-badge', ['status' => $flow->status])
                </div>
                <p class="text-sm text-gray-500 line-clamp-1">{{ $flow->description }}</p>
            </div>
            <div class="flex items-center gap-4 shrink-0 text-sm text-gray-400">
                <span>{{ $flow->agents_count }} агенти</span>
                @if($flow->last_run_at)
                    <span class="text-xs">{{ $flow->last_run_at->diffForHumans() }}</span>
                @endif
                <a href="{{ route('flows.show', $flow) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium text-sm">
                    Отвори →
                </a>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
