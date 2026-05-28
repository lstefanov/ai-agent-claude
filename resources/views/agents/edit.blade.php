@extends('layouts.app')

@section('title', 'Редактирай агент — ' . $agent->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('flows.show', $flow) }}" class="text-indigo-600 hover:underline text-sm">
        ← {{ $flow->name }}
    </a>
    <h1 class="text-3xl font-bold text-gray-900 mt-2">Редактирай агент</h1>
    <p class="text-gray-500 mt-1">{{ $agent->name }}</p>
</div>

<form action="{{ route('agents.update', [$flow, $agent]) }}" method="POST" class="space-y-6 max-w-3xl">
    @csrf
    @method('PUT')

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Име</label>
            <input type="text" name="name" value="{{ old('name', $agent->name) }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                   required>
            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Роля</label>
            <textarea name="role" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      required>{{ old('role', $agent->role) }}</textarea>
            @error('role') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Промпт шаблон</label>
            <textarea name="prompt_template" rows="8"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      required>{{ old('prompt_template', $agent->prompt_template) }}</textarea>
            @error('prompt_template') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Модел</label>
            <select name="model"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                @foreach($models as $model)
                    <option value="{{ $model->ollama_tag }}"
                            {{ old('model', $agent->model) === $model->ollama_tag ? 'selected' : '' }}>
                        {{ $model->display_name }}
                        @if(!$model->is_available) (недостъпен) @endif
                    </option>
                @endforeach
            </select>
            @error('model') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        @if($agent->is_verifier)
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">QA праг (%)</label>
            <input type="number" name="qa_threshold" min="0" max="100"
                   value="{{ old('qa_threshold', $agent->qa_threshold) }}"
                   class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            @error('qa_threshold') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        @endif

        <div class="flex items-center gap-3">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" id="is_active" value="1"
                   {{ old('is_active', $agent->is_active) ? 'checked' : '' }}
                   class="w-4 h-4 text-indigo-600 border-gray-300 rounded">
            <label for="is_active" class="text-sm font-medium text-gray-700">Активен</label>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">
            Запази промените
        </button>
        <a href="{{ route('flows.show', $flow) }}"
           class="text-gray-500 hover:text-gray-700 text-sm">Отказ</a>
    </div>
</form>
@endsection
