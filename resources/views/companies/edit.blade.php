@extends('layouts.app')

@section('title', 'Редактирай ' . $company->name)

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('companies.show', $company) }}" class="text-indigo-600 hover:underline text-sm">← Обратно</a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2">Редактирай фирма</h1>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
        <form action="{{ route('companies.update', $company) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Наименование</label>
                <input type="text" name="name" value="{{ old('name', $company->name) }}" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Сектор / Индустрия</label>
                <input type="text" name="industry" value="{{ old('industry', $company->industry) }}" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Основен език</label>
                <select name="language"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="bg" {{ old('language', $company->language) === 'bg' ? 'selected' : '' }}>🇧🇬 Български</option>
                    <option value="en" {{ old('language', $company->language) === 'en' ? 'selected' : '' }}>🇬🇧 English</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Описание</label>
                <textarea name="description" rows="4" required
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ old('description', $company->description) }}</textarea>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg font-medium transition">
                    Запази промените
                </button>
                <a href="{{ route('companies.show', $company) }}"
                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg font-medium transition">
                    Откажи
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
