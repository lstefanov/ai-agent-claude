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
    <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        @foreach($categoryModels as $model)
        <div x-data="modelRow({{ $model->id }}, '{{ addslashes($model->ollama_tag) }}', '{{ $model->pull_status ?? 'idle' }}', {{ $model->pull_progress ?? 0 }}, {{ $model->is_available ? 'true' : 'false' }})"
             x-init="init()"
             class="px-6 py-4">

            {{-- Main row --}}
            <div class="flex items-center gap-4">
                {{-- Status dot --}}
                <div :class="isAvailable ? 'bg-green-500' : (status === 'pulling' ? 'bg-blue-400 animate-pulse' : (status === 'failed' ? 'bg-red-400' : 'bg-gray-300'))"
                     class="w-2.5 h-2.5 rounded-full shrink-0"></div>

                {{-- Model info --}}
                <div class="flex-1 min-w-0">
                    <span class="font-medium text-gray-900">{{ $model->display_name }}</span>
                    <span class="text-xs font-mono text-gray-400 ml-2">{{ $model->ollama_tag }}</span>
                    <p class="text-sm text-gray-500 mt-0.5">{{ $model->description }}</p>
                </div>

                {{-- Size --}}
                @if($model->size_mb)
                <span class="text-xs text-gray-400 shrink-0 tabular-nums">
                    @if($model->size_mb >= 1000)
                        {{ number_format($model->size_mb / 1024, 1) }} GB
                    @else
                        {{ $model->size_mb }} MB
                    @endif
                </span>
                @endif

                {{-- RAM --}}
                <span class="text-xs text-gray-400 shrink-0">{{ $model->ram_required_gb }} GB RAM</span>

                {{-- Actions --}}
                <div class="flex items-center gap-2 shrink-0">

                    {{-- Pull / status badge --}}
                    <template x-if="isAvailable">
                        <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">наличен</span>
                    </template>

                    <template x-if="!isAvailable && status !== 'pulling'">
                        <button @click="startPull()"
                                :disabled="status === 'pulling'"
                                class="text-xs bg-indigo-50 hover:bg-indigo-100 text-indigo-600 px-3 py-1 rounded-full transition font-medium">
                            ⬇ Изтегли
                        </button>
                    </template>

                    <template x-if="status === 'pulling'">
                        <span class="text-xs text-blue-600 font-medium" x-text="progress + '%'"></span>
                    </template>

                    <template x-if="status === 'failed' && !isAvailable">
                        <span class="text-xs bg-red-100 text-red-600 px-2 py-1 rounded-full">грешка</span>
                    </template>

                    {{-- Test button (shown when available) --}}
                    <template x-if="isAvailable">
                        <button @click="runTest()"
                                :disabled="testing"
                                class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-1 rounded-full transition"
                                :class="testing ? 'opacity-50 cursor-wait' : ''">
                            <span x-show="!testing">▶ Тест</span>
                            <span x-show="testing">⏳</span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Progress bar (shown while pulling) --}}
            <template x-if="status === 'pulling'">
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs text-gray-400 mb-1">
                        <span>Изтегляне...</span>
                        <span x-text="progress + '%'"></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="bg-indigo-500 h-1.5 rounded-full transition-all duration-500"
                             :style="'width: ' + progress + '%'"></div>
                    </div>
                </div>
            </template>

            {{-- Test result --}}
            <template x-if="testResult !== ''">
                <div class="mt-3 flex items-start gap-2">
                    <div :class="testOk ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'"
                         class="flex-1 text-xs rounded-lg border px-3 py-2 font-mono">
                        <span x-text="testResult"></span>
                    </div>
                    <button @click="testResult = ''" class="text-gray-300 hover:text-gray-500 text-xs mt-1">✕</button>
                </div>
            </template>
        </div>
        @endforeach
    </div>
</div>
@endforeach

<script>
function modelRow(id, tag, initialStatus, initialProgress, initialAvailable) {
    return {
        id:          id,
        tag:         tag,
        status:      initialStatus,
        progress:    initialProgress,
        isAvailable: initialAvailable,
        testing:     false,
        testResult:  '',
        testOk:      false,
        pollTimer:   null,
        csrf:        document.querySelector('meta[name="csrf-token"]').content,

        init() {
            if (this.status === 'pulling') {
                this.startPolling();
            }
        },

        async startPull() {
            this.status   = 'pulling';
            this.progress = 0;

            await fetch(`/models/${this.id}/pull`, {
                method:  'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf },
            });

            this.startPolling();
        },

        startPolling() {
            if (this.pollTimer) return;
            this.pollTimer = setInterval(() => this.checkStatus(), 2000);
        },

        async checkStatus() {
            try {
                const res  = await fetch(`/models/${this.id}/pull/status`);
                const data = await res.json();

                this.status      = data.status;
                this.progress    = data.progress;
                this.isAvailable = data.is_available;

                if (data.status === 'completed' || data.status === 'failed' || data.is_available) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }
            } catch (e) {
                // network blip — keep polling
            }
        },

        async runTest() {
            this.testing    = true;
            this.testResult = '';

            try {
                const res  = await fetch(`/models/${this.id}/test`, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf },
                });
                const data = await res.json();
                this.testOk     = data.success;
                this.testResult = data.response;
            } catch (e) {
                this.testOk     = false;
                this.testResult = 'Грешка при свързване.';
            }

            this.testing = false;
        },
    };
}
</script>
@endsection
