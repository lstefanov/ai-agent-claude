@extends('layouts.app')

@section('title', 'LLM Модели')

@section('content')
<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">LLM Модели</h1>
        <p class="text-gray-500 mt-1">Ollama модели и тяхната наличност</p>
    </div>
    <form action="{{ route('models.sync') }}" method="POST">
        @csrf
        <button type="submit"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
            🔄 Синхронизирай от Ollama
        </button>
    </form>
</div>

@foreach($models as $category => $categoryModels)
<div class="mb-8">
    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-widest mb-3">{{ $category }}</h2>
    <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-50">
        @foreach($categoryModels as $model)
        <div class="px-6 py-4 flex items-center gap-4">
            <div class="w-2.5 h-2.5 rounded-full shrink-0 {{ $model->is_available ? 'bg-green-500' : 'bg-gray-300' }}"></div>
            <div class="flex-1 min-w-0">
                <span class="font-medium text-gray-900">{{ $model->display_name }}</span>
                <span class="text-xs font-mono text-gray-400 ml-2">{{ $model->ollama_tag }}</span>
                <p class="text-sm text-gray-500 mt-0.5">{{ $model->description }}</p>
            </div>
            <span class="text-xs text-gray-400 shrink-0">{{ $model->ram_required_gb }} GB RAM</span>
            @if($model->is_available)
                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full shrink-0">наличен</span>
            @else
                <span class="text-xs bg-gray-100 text-gray-400 px-2 py-1 rounded-full shrink-0">недостъпен</span>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endforeach
@endsection
