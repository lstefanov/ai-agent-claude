@extends('layouts.app')

@section('title', $company->name)

@section('content')
<div class="flex items-start justify-between mb-6">
    <div>
        <a href="{{ route('companies.index') }}" class="text-indigo-600 hover:underline text-sm">← Всички фирми</a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3">
            {{ $company->name }}
            <span class="text-sm bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full font-medium uppercase">
                {{ $company->language }}
            </span>
        </h1>
        <p class="text-gray-500 mt-1">{{ $company->industry }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('companies.edit', $company) }}"
           class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
            Редактирай
        </a>
        <form action="{{ route('companies.destroy', $company) }}" method="POST"
              onsubmit="return confirm('Сигурен ли си? Ще се изтрият и всички flows!')">
            @csrf @method('DELETE')
            <button class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-lg text-sm font-medium transition">
                Изтрий
            </button>
        </form>
    </div>
</div>

{{-- Description --}}
<div class="bg-white rounded-xl border border-gray-200 p-6 mb-8">
    <h2 class="text-sm font-medium text-gray-500 uppercase tracking-wide mb-2">Описание</h2>
    <p class="text-gray-700">{{ $company->description }}</p>
</div>

{{-- Flows --}}
<div class="flex items-center justify-between mb-4">
    <h2 class="text-xl font-semibold text-gray-900">Flows ({{ $flows->count() }})</h2>
    <a href="{{ route('companies.flows.create', $company) }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        + Нов flow
    </a>
</div>

@if($flows->isEmpty())
    <div class="text-center py-12 bg-white rounded-xl border border-dashed border-gray-300">
        <p class="text-gray-400 mb-3">Няма добавени flows</p>
        <a href="{{ route('companies.flows.create', $company) }}" class="text-indigo-600 hover:underline text-sm">
            Създай първия flow с AI →
        </a>
    </div>
@else
    <div class="space-y-3">
        @foreach($flows as $flow)
        <div class="bg-white rounded-xl border border-gray-200 px-6 py-4 flex items-center justify-between hover:shadow-sm transition">
            <div class="flex items-center gap-4">
                <div>
                    <h3 class="font-semibold text-gray-900">{{ $flow->name }}</h3>
                    <p class="text-sm text-gray-500 line-clamp-1">{{ $flow->description }}</p>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <span @class([
                    'text-xs px-2 py-1 rounded-full font-medium',
                    'bg-green-100 text-green-700'  => $flow->status === 'active',
                    'bg-yellow-100 text-yellow-700' => $flow->status === 'draft',
                    'bg-gray-100 text-gray-500'     => $flow->status === 'paused',
                ])>{{ $flow->status }}</span>
                <span class="text-sm text-gray-400">{{ $flow->agents_count }} агенти</span>
                @if($flow->last_run_at)
                    <span class="text-xs text-gray-400">{{ $flow->last_run_at->diffForHumans() }}</span>
                @endif
                <a href="{{ route('flows.show', $flow) }}"
                   class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                    Детайли →
                </a>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
