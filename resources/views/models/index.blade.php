@extends('layouts.app')

@section('title', 'LLM Модели')

@section('content')
<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">LLM Модели</h1>
        <p class="text-gray-500 mt-1">Ollama модели — наличност, изтегляне и тест</p>
    </div>
    <div class="flex gap-2">
        <button onclick="document.getElementById('add-model-form').classList.toggle('hidden')"
                class="bg-white border border-gray-300 hover:border-indigo-400 text-gray-700 px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
            ＋ Добави модел
        </button>
        <form action="{{ route('models.sync') }}" method="POST">
            @csrf
            <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
                🔄 Синхронизирай
            </button>
        </form>
    </div>
</div>

{{-- Add model form --}}
<div id="add-model-form" class="hidden mb-6 bg-white rounded-xl border border-indigo-200 p-6">
    <h3 class="text-sm font-semibold text-gray-700 mb-4">Добави нов модел</h3>
    <form action="{{ route('models.store') }}" method="POST">
        @csrf
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Ollama Tag <span class="text-red-400">*</span></label>
                <input type="text" name="ollama_tag" placeholder="llama3.2:3b"
                       value="{{ old('ollama_tag') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                @error('ollama_tag') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Показвано Име <span class="text-red-400">*</span></label>
                <input type="text" name="display_name" placeholder="LLaMA 3.2 3B"
                       value="{{ old('display_name') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                @error('display_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Категория <span class="text-red-400">*</span></label>
                <select name="category" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    @foreach(['general','json','reasoning','qa','code','multilingual','vision','bulgarian','other'] as $cat)
                        <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">RAM (GB)</label>
                <input type="number" name="ram_required_gb" step="0.5" min="0" placeholder="5.0"
                       value="{{ old('ram_required_gb') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Размер (MB)</label>
                <input type="number" name="size_mb" min="0" placeholder="4700"
                       value="{{ old('size_mb') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Описание</label>
                <input type="text" name="description" placeholder="Кратко описание..."
                       value="{{ old('description') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>
        <div class="mt-4 flex gap-3">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Добави
            </button>
            <button type="button" onclick="document.getElementById('add-model-form').classList.add('hidden')"
                    class="text-gray-500 hover:text-gray-700 text-sm">Отказ</button>
        </div>
    </form>
</div>

@foreach($models as $category => $categoryModels)
<div class="mb-8">
    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-widest mb-3">{{ $category }}</h2>
    <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        @foreach($categoryModels as $model)
        <div x-data="modelRow({{ $model->id }}, '{{ addslashes($model->ollama_tag) }}', '{{ $model->pull_status ?? 'idle' }}', {{ $model->pull_progress ?? 0 }}, {{ $model->is_available ? 'true' : 'false' }}, @js($model->pull_error))"
             x-init="init()"
             :class="!{{ $model->is_enabled ? 'true' : 'false' }} ? 'opacity-50' : ''"
             class="px-6 py-4 transition-opacity">

            {{-- Main row --}}
            <div class="flex items-center gap-4">
                {{-- Status dot --}}
                <div :class="isAvailable ? 'bg-green-500' : (status === 'pulling' ? 'bg-blue-400 animate-pulse' : (status === 'failed' ? 'bg-red-400' : 'bg-gray-300'))"
                     class="w-2.5 h-2.5 rounded-full shrink-0"></div>

                {{-- Model info --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium {{ $model->is_enabled ? 'text-gray-900' : 'text-gray-400' }}">{{ $model->display_name }}</span>
                        <span class="text-xs font-mono text-gray-400">{{ $model->ollama_tag }}</span>
                        @if(!$model->is_enabled)
                            <span class="text-xs bg-gray-100 text-gray-400 px-1.5 py-0.5 rounded">изключен</span>
                        @endif
                    </div>
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
                @if($model->ram_required_gb)
                <span class="text-xs text-gray-400 shrink-0">{{ $model->ram_required_gb }} GB RAM</span>
                @endif

                {{-- Actions --}}
                <div class="flex items-center gap-2 shrink-0">

                    {{-- Available badge --}}
                    <template x-if="isAvailable">
                        <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">наличен</span>
                    </template>

                    {{-- Pull button --}}
                    <template x-if="!isAvailable && status !== 'pulling'">
                        <button @click="startPull()"
                                class="text-xs bg-indigo-50 hover:bg-indigo-100 text-indigo-600 px-3 py-1 rounded-full transition font-medium">
                            ⬇ Изтегли
                        </button>
                    </template>

                    {{-- Pulling progress % --}}
                    <template x-if="status === 'pulling'">
                        <span class="text-xs text-blue-600 font-medium tabular-nums" x-text="progress + '%'"></span>
                    </template>

                    {{-- Failed --}}
                    <template x-if="status === 'failed' && !isAvailable">
                        <span class="text-xs bg-red-100 text-red-600 px-2 py-1 rounded-full">грешка</span>
                    </template>

                    {{-- Retry button --}}
                    <template x-if="status === 'failed' && !isAvailable">
                        <button @click="retryPull()"
                                class="text-xs bg-orange-50 hover:bg-orange-100 text-orange-600 px-3 py-1 rounded-full transition font-medium">
                            🔁 Retry
                        </button>
                    </template>

                    {{-- Test button --}}
                    <template x-if="isAvailable">
                        <button @click="runTest()"
                                :disabled="testing"
                                class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-1 rounded-full transition"
                                :class="testing ? 'opacity-50 cursor-wait' : ''">
                            <span x-show="!testing">▶ Тест</span>
                            <span x-show="testing">⏳</span>
                        </button>
                    </template>

                    {{-- Toggle enable/disable --}}
                    <form action="{{ route('models.toggle', $model) }}" method="POST">
                        @csrf
                        <button type="submit"
                                title="{{ $model->is_enabled ? 'Изключи модела от FlowAI' : 'Включи модела в FlowAI' }}"
                                class="text-xs px-2 py-1 rounded-full transition
                                    {{ $model->is_enabled
                                        ? 'bg-gray-100 hover:bg-red-50 text-gray-400 hover:text-red-500'
                                        : 'bg-green-50 hover:bg-green-100 text-green-600' }}">
                            {{ $model->is_enabled ? '⏸' : '▶' }}
                        </button>
                    </form>
                </div>
            </div>

            {{-- Progress bar --}}
            <template x-if="status === 'pulling'">
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs text-gray-400 mb-1">
                        <span x-text="pullPhase || 'Изтегляне…'" class="animate-pulse"></span>
                        <span x-text="progress + '%'" class="tabular-nums"></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="bg-indigo-500 h-1.5 rounded-full transition-all duration-500"
                             :style="'width: ' + (progress || 1) + '%'"></div>
                    </div>
                </div>
            </template>

            {{-- Pull error block --}}
            <template x-if="status === 'failed' && pullError">
                <div class="mt-3 flex items-start gap-2">
                    <div class="flex-1 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                        <p class="text-xs font-semibold text-red-700 mb-1">⚠ Грешка при изтегляне</p>
                        <pre class="text-xs text-red-800 font-mono whitespace-pre-wrap break-all" x-text="pullError"></pre>
                        <p class="text-xs text-red-500 mt-1.5">
                            Провери дали тагът е верен на <a href="https://ollama.com/library" target="_blank" class="underline hover:text-red-700">ollama.com/library</a>
                        </p>
                    </div>
                    <button @click="pullError = ''" class="text-gray-300 hover:text-gray-500 text-xs mt-1">✕</button>
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
function modelRow(id, tag, initialStatus, initialProgress, initialAvailable, initialPullError) {
    return {
        id:          id,
        tag:         tag,
        status:      initialStatus,
        progress:    initialProgress,
        isAvailable: initialAvailable,
        pullError:   initialPullError || '',
        pullPhase:   '',
        testing:     false,
        testResult:  '',
        testOk:      false,
        pollTimer:   null,
        csrf:        document.querySelector('meta[name="csrf-token"]').content,

        init() {
            if (this.status === 'pulling') this.startPolling();
        },

        async startPull() {
            this.status    = 'pulling';
            this.progress  = 0;
            this.pullError = '';
            this.pullPhase = '';
            await fetch(`/models/${this.id}/pull`, {
                method:  'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf },
            });
            this.startPolling();
        },

        async retryPull() {
            this.status    = 'idle';
            this.pullError = '';
            await this.$nextTick();
            this.startPull();
        },

        startPolling() {
            if (this.pollTimer) return;
            this.pollTimer = setInterval(() => this.checkStatus(), 2000);
        },

        async checkStatus() {
            try {
                const data = await (await fetch(`/models/${this.id}/pull/status`)).json();
                this.status      = data.status;
                this.progress    = data.progress;
                this.isAvailable = data.is_available;
                if (data.pull_error)  this.pullError  = data.pull_error;
                if (data.pull_phase)  this.pullPhase  = data.pull_phase;
                if (data.status === 'completed' || data.status === 'failed' || data.is_available) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }
            } catch (e) { /* network blip */ }
        },

        async runTest() {
            this.testing    = true;
            this.testResult = '';
            try {
                const data = await (await fetch(`/models/${this.id}/test`, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf },
                })).json();
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
