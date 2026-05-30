@extends('layouts.app')

@section('title', 'Агенти — ' . $company->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.show', $company) }}" class="text-indigo-600 hover:underline text-sm">← {{ $company->name }}</a>
</div>
<div class="flex items-start justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">🤖 Агенти на компанията</h1>
        <p class="text-sm text-gray-500 mt-1">Шаблони достъпни само за {{ $company->name }}</p>
    </div>
    <a href="{{ route('companies.agent-templates.create', $company) }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
        ＋ Нов агент шаблон
    </a>
</div>

@if(session('success'))
    <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">✓ {{ session('success') }}</div>
@endif

@if($templates->isEmpty())
    <div class="bg-white border border-dashed border-gray-300 rounded-xl p-12 text-center text-gray-400">
        <p class="text-3xl mb-3">🤖</p>
        <p class="text-sm">Няма агент шаблони за тази компания.</p>
        <a href="{{ route('companies.agent-templates.create', $company) }}" class="text-indigo-600 underline text-sm mt-2 inline-block">Добави първия →</a>
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        @foreach($templates as $template)
        <div class="flex items-center gap-4 px-5 py-4 border-b border-gray-100 last:border-0 hover:bg-gray-50">
            <span class="text-2xl w-8 text-center">{{ $template->icon }}</span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900 text-sm">{{ $template->name }}</span>
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded font-mono">{{ $template->type }}</span>
                </div>
                <p class="text-xs text-gray-500 truncate">{{ $template->description }}</p>
            </div>
            <div class="flex gap-2 shrink-0">
                <a href="{{ route('companies.agent-templates.edit', [$company, $template]) }}"
                   class="border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs">
                    ✏ Редактирай
                </a>
                <form action="{{ route('companies.agent-templates.destroy', [$company, $template]) }}" method="POST"
                      onsubmit="return confirm('Изтрий шаблон {{ $template->name }}?')">
                    @csrf @method('DELETE')
                    <button class="border border-red-200 text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg text-xs">✕ Изтрий</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
