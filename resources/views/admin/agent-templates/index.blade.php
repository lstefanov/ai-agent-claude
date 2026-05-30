@extends('admin.layouts.admin')

@section('title', 'Системни агент шаблони')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">⚙ Системни агент шаблони</h1>
        <p class="text-sm text-gray-500 mt-1">Видими за всички компании в picker-а</p>
    </div>
    <a href="{{ route('admin.agent-templates.create') }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
        ＋ Нов шаблон
    </a>
</div>

@if($templates->isEmpty())
    <div class="bg-white border border-dashed border-gray-300 rounded-xl p-12 text-center text-gray-400">
        <p class="text-3xl mb-3">🤖</p>
        <p class="text-sm">Няма системни шаблони. <a href="{{ route('admin.agent-templates.create') }}" class="text-indigo-600 underline">Добави първия.</a></p>
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        @foreach($templates as $template)
        <div class="flex items-center gap-4 px-5 py-4 border-b border-gray-100 last:border-0 hover:bg-gray-50">
            <span class="text-2xl w-8 text-center">{{ $template->icon }}</span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900 text-sm">{{ $template->name }}</span>
                    <span class="text-xs bg-violet-100 text-violet-700 px-2 py-0.5 rounded font-mono">{{ $template->type }}</span>
                </div>
                <p class="text-xs text-gray-500 truncate">{{ $template->description }}</p>
            </div>
            <div class="flex gap-2 shrink-0">
                <a href="{{ route('admin.agent-templates.edit', $template) }}"
                   class="border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs">
                    ✏ Редактирай
                </a>
                <form action="{{ route('admin.agent-templates.destroy', $template) }}" method="POST"
                      onsubmit="return confirm('Изтрий шаблон {{ $template->name }}?')">
                    @csrf @method('DELETE')
                    <button class="border border-red-200 text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg text-xs">
                        ✕ Изтрий
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
