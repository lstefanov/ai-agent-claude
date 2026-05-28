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
        ＋ Добави фирма
    </a>
</div>

@if($companies->isEmpty())
    <div class="text-center py-20 bg-white rounded-xl border border-dashed border-gray-300">
        <p class="text-4xl mb-4">🏢</p>
        <p class="text-gray-500 font-medium text-lg mb-2">Все още няма добавени фирми</p>
        <p class="text-gray-400 text-sm mb-6">Добави фирма и създай първия си AI flow</p>
        <a href="{{ route('companies.create') }}"
           class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg font-medium transition">
            ＋ Добави първата фирма
        </a>
    </div>
@else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($companies as $company)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 hover:shadow-md transition overflow-hidden flex flex-col">
            {{-- Card header accent --}}
            <div class="h-1.5 {{ $company->language === 'bg' ? 'bg-gradient-to-r from-indigo-500 to-purple-500' : 'bg-gradient-to-r from-sky-500 to-cyan-500' }}"></div>

            <div class="p-6 flex flex-col flex-1">
                <div class="flex items-start justify-between mb-3">
                    <h2 class="text-lg font-semibold text-gray-900 leading-tight">{{ $company->name }}</h2>
                    <span class="text-xs bg-gray-100 text-gray-500 px-2 py-1 rounded-full font-medium uppercase shrink-0 ml-2">
                        {{ $company->language }}
                    </span>
                </div>

                @if($company->industry)
                    <p class="text-xs font-medium text-indigo-600 mb-2">{{ $company->industry }}</p>
                @endif

                <p class="text-gray-500 text-sm line-clamp-2 flex-1 mb-4">
                    {{ $company->description ?: 'Без описание' }}
                </p>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <div class="flex items-center gap-1.5 text-sm text-gray-400">
                        <span class="text-base">⚡</span>
                        <span>{{ $company->flows_count }}
                            {{ $company->flows_count === 1 ? 'flow' : 'flows' }}
                        </span>
                    </div>
                    <a href="{{ route('companies.show', $company) }}"
                       class="text-indigo-600 hover:text-indigo-800 text-sm font-medium transition">
                        Отвори →
                    </a>
                </div>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
