@extends('layouts.app')

@section('title', 'Фирми')

@section('content')
<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Фирми</h1>
        <p class="text-gray-500 mt-1">Управление на фирми и техните AI flows</p>
    </div>
    <a href="{{ route('companies.create') }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
        + Добави фирма
    </a>
</div>

@if($companies->isEmpty())
    <div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
        <p class="text-gray-400 text-lg mb-4">Няма добавени фирми</p>
        <a href="{{ route('companies.create') }}" class="text-indigo-600 hover:underline font-medium">
            Добави първата фирма →
        </a>
    </div>
@else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($companies as $company)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 hover:shadow-md transition overflow-hidden">
            <div class="p-6">
                <div class="flex items-start justify-between mb-3">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $company->name }}</h2>
                    <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full font-medium uppercase">
                        {{ $company->language }}
                    </span>
                </div>
                <p class="text-sm text-gray-500 mb-1">{{ $company->industry }}</p>
                <p class="text-gray-600 text-sm line-clamp-2 mb-4">{{ $company->description }}</p>
                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <span class="text-sm text-gray-400">
                        {{ $company->flows_count }} {{ Str::plural('flow', $company->flows_count) }}
                    </span>
                    <a href="{{ route('companies.show', $company) }}"
                       class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                        Виж →
                    </a>
                </div>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
