@extends('layouts.app')

@section('title', 'LLM Модели')

@section('content')
<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-3xl font-bold text-ink">LLM Модели</h1>
        <p class="text-muted mt-1">Ollama модели — наличност, изтегляне и тест</p>
    </div>
    <div class="flex gap-2">
        <button onclick="document.getElementById('add-model-form').classList.toggle('hidden')"
                class="bg-surface border border-line hover:border-primary text-ink px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
            ＋ Добави модел
        </button>
        <form action="{{ route('models.sync') }}" method="POST">
            @csrf
            <button type="submit"
                    class="bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
                🔄 Синхронизирай
            </button>
        </form>
    </div>
</div>

{{-- Add model form --}}
<div id="add-model-form" class="hidden mb-6 bg-surface rounded-xl border border-info p-6">
    <h3 class="text-sm font-semibold text-ink mb-4">Добави нов модел</h3>
    <form action="{{ route('models.store') }}" method="POST">
        @csrf
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-muted mb-1">Ollama Tag <span class="text-red-400">*</span></label>
                <input type="text" name="ollama_tag" placeholder="llama3.2:3b"
                       value="{{ old('ollama_tag') }}"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary/40">
                @error('ollama_tag') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-muted mb-1">Показвано Име <span class="text-red-400">*</span></label>
                <input type="text" name="display_name" placeholder="LLaMA 3.2 3B"
                       value="{{ old('display_name') }}"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
                @error('display_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-muted mb-1">Категория <span class="text-red-400">*</span></label>
                <select name="category" class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
                    @foreach(['general','json','reasoning','qa','code','multilingual','vision','bulgarian','other'] as $cat)
                        <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-muted mb-1">RAM (GB)</label>
                <input type="number" name="ram_required_gb" step="0.5" min="0" placeholder="5.0"
                       value="{{ old('ram_required_gb') }}"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
            <div>
                <label class="block text-xs font-medium text-muted mb-1">Размер (MB)</label>
                <input type="number" name="size_mb" min="0" placeholder="4700"
                       value="{{ old('size_mb') }}"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
            <div>
                <label class="block text-xs font-medium text-muted mb-1">Описание</label>
                <input type="text" name="description" placeholder="Кратко описание..."
                       value="{{ old('description') }}"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
        </div>
        <div class="mt-4 flex gap-3">
            <button type="submit" class="bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Добави
            </button>
            <button type="button" onclick="document.getElementById('add-model-form').classList.add('hidden')"
                    class="text-muted hover:text-ink text-sm">Отказ</button>
        </div>
    </form>
</div>

@foreach($models as $category => $categoryModels)
<div class="mb-8">
    <h2 class="text-sm font-semibold text-muted uppercase tracking-widest mb-3">{{ $category }}</h2>
    <div class="bg-surface rounded-xl border border-line divide-y divide-line">
        @foreach($categoryModels as $model)
        <div x-data="modelRow({{ $model->id }}, '{{ addslashes($model->ollama_tag) }}', '{{ $model->pull_status ?? 'idle' }}', {{ $model->pull_progress ?? 0 }}, {{ $model->is_available ? 'true' : 'false' }}, @js($model->pull_error))"
             x-init="init()"
             :class="!{{ $model->is_enabled ? 'true' : 'false' }} ? 'opacity-50' : ''"
             class="px-6 py-4 transition-opacity">

            {{-- Main row --}}
            <div class="flex items-center gap-4">
                {{-- Status dot --}}
                <div :class="isAvailable ? 'bg-green-500' : (status === 'pulling' ? 'bg-blue-400 animate-pulse' : (status === 'failed' ? 'bg-red-400' : 'bg-line-strong'))"
                     class="w-2.5 h-2.5 rounded-full shrink-0"></div>

                {{-- Model info --}}
                @php
                $agentTypes = config('agent_types');
                $roleColors = [
                    // Researchers
                    'researcher'          => 'bg-violet-100 text-violet-700',
                    'trend_researcher'    => 'bg-violet-100 text-violet-700',
                    'competitor_profiler' => 'bg-violet-100 text-violet-700',
                    'review_analyzer'     => 'bg-violet-100 text-violet-700',
                    'keyword_extractor'   => 'bg-violet-100 text-violet-700',
                    'image_describer'     => 'bg-purple-100 text-purple-700',
                    'scraper'             => 'bg-violet-100 text-violet-700',
                    // Analyzers
                    'analyzer'            => 'bg-blue-100 text-blue-700',
                    'swot_builder'        => 'bg-blue-100 text-blue-700',
                    'data_extractor'      => 'bg-blue-100 text-blue-700',
                    'classifier'          => 'bg-blue-100 text-blue-700',
                    'sentiment_analyzer'  => 'bg-blue-100 text-blue-700',
                    'summarizer'          => 'bg-blue-100 text-blue-700',
                    'decision'            => 'bg-info-soft text-primary',
                    // Content writers
                    'content_bg'          => 'bg-emerald-100 text-emerald-700',
                    'content_en'          => 'bg-emerald-100 text-emerald-700',
                    'writer'              => 'bg-emerald-100 text-emerald-700',
                    'caption_writer'      => 'bg-green-100 text-green-700',
                    'hook_writer'         => 'bg-green-100 text-green-700',
                    'ad_copywriter'       => 'bg-orange-100 text-orange-700',
                    'report_writer'       => 'bg-teal-100 text-teal-700',
                    'newsletter_writer'   => 'bg-teal-100 text-teal-700',
                    'email_composer'      => 'bg-sky-100 text-sky-700',
                    'seo_writer'          => 'bg-green-100 text-green-700',
                    'offer_builder'       => 'bg-orange-100 text-orange-700',
                    'translator'          => 'bg-pink-100 text-pink-700',
                    'publisher'           => 'bg-teal-100 text-teal-700',
                    'formatter'           => 'bg-slate-100 text-slate-700',
                    // Appendix generators
                    'hashtag'             => 'bg-rose-100 text-rose-700',
                    'hashtags'            => 'bg-rose-100 text-rose-700',
                    'hashtag_generator'   => 'bg-rose-100 text-rose-700',
                    'tags'                => 'bg-rose-100 text-rose-700',
                    'seo'                 => 'bg-yellow-100 text-yellow-700',
                    'faq_generator'       => 'bg-yellow-100 text-yellow-700',
                    'meta_generator'      => 'bg-yellow-100 text-yellow-700',
                    'email'               => 'bg-sky-100 text-sky-700',
                    'image_prompt'        => 'bg-purple-100 text-purple-700',
                    // Integrations
                    'webhook_sender'      => 'bg-neutral-soft text-muted',
                    'slack_notifier'      => 'bg-neutral-soft text-muted',
                    'google_sheets_writer'=> 'bg-neutral-soft text-muted',
                    // Quality
                    'qa_verifier'         => 'bg-red-100 text-red-700',
                    'verifier'            => 'bg-red-100 text-red-700',
                    // Special
                    'orchestrator'        => 'bg-info-soft text-primary',
                    'code'                => 'bg-zinc-100 text-zinc-700',
                    'vision'              => 'bg-purple-100 text-purple-700',
                ];
                @endphp
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium {{ $model->is_enabled ? 'text-ink' : 'text-subtle' }}">{{ $model->display_name }}</span>
                        <span class="text-xs font-mono text-subtle">{{ $model->ollama_tag }}</span>
                        @if(!$model->is_enabled)
                            <span class="text-xs bg-neutral-soft text-subtle px-1.5 py-0.5 rounded">изключен</span>
                        @endif
                    </div>
                    <p class="text-sm text-muted mt-0.5">{{ $model->description }}</p>
                    @if(!empty($model->is_default_for))
                    <div class="flex flex-wrap gap-1 mt-1.5">
                        @foreach($model->is_default_for as $role)
                            @if(isset($agentTypes[$role]) && isset($roleColors[$role]))
                            <div class="relative group inline-block">
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium cursor-default select-none {{ $roleColors[$role] }}">
                                    {{ $agentTypes[$role]['label'] }} <span class="opacity-50 font-normal">({{ $role }})</span>
                                </span>
                                @if(!empty($agentTypes[$role]['description']))
                                <div class="absolute bottom-full left-0 mb-2 z-50 w-64 px-3 py-2 bg-ink text-white text-xs rounded-lg shadow-xl
                                            invisible opacity-0 group-hover:visible group-hover:opacity-100 transition-opacity duration-150 pointer-events-none whitespace-normal">
                                    {{ $agentTypes[$role]['description'] }}
                                    <div class="absolute top-full left-4 border-4 border-transparent border-t-ink"></div>
                                </div>
                                @endif
                            </div>
                            @endif
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- Size --}}
                @if($model->size_mb)
                <span class="text-xs text-subtle shrink-0 tabular-nums">
                    @if($model->size_mb >= 1000)
                        {{ number_format($model->size_mb / 1024, 1) }} GB
                    @else
                        {{ $model->size_mb }} MB
                    @endif
                </span>
                @endif

                {{-- RAM --}}
                @if($model->ram_required_gb)
                <span class="text-xs text-subtle shrink-0">{{ $model->ram_required_gb }} GB RAM</span>
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
                                class="text-xs bg-info-soft hover:bg-info-soft text-primary px-3 py-1 rounded-full transition font-medium">
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
                                class="text-xs bg-neutral-soft hover:bg-surface-subtle text-muted px-2 py-1 rounded-full transition"
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
                                        ? 'bg-neutral-soft hover:bg-red-50 text-subtle hover:text-red-500'
                                        : 'bg-green-50 hover:bg-green-100 text-green-600' }}">
                            {{ $model->is_enabled ? '⏸' : '▶' }}
                        </button>
                    </form>
                </div>
            </div>

            {{-- Progress bar --}}
            <template x-if="status === 'pulling'">
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs text-subtle mb-1">
                        <span x-text="pullPhase || 'Изтегляне…'" class="animate-pulse"></span>
                        <span x-text="progress + '%'" class="tabular-nums"></span>
                    </div>
                    <div class="w-full bg-neutral-soft rounded-full h-1.5">
                        <div class="bg-info-soft0 h-1.5 rounded-full transition-all duration-500"
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
                    <button @click="pullError = ''" class="text-subtle hover:text-muted text-xs mt-1">✕</button>
                </div>
            </template>

            {{-- Test result --}}
            <template x-if="testResult !== ''">
                <div class="mt-3 flex items-start gap-2">
                    <div :class="testOk ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'"
                         class="flex-1 text-xs rounded-lg border px-3 py-2 font-mono">
                        <span x-text="testResult"></span>
                    </div>
                    <button @click="testResult = ''" class="text-subtle hover:text-muted text-xs mt-1">✕</button>
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
        testPollTimer: null,
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
                const resp = await fetch(`/models/${this.id}/test`, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });

                if (!resp.ok) {
                    throw new Error((await resp.text()) || `HTTP ${resp.status}`);
                }

                const data = await resp.json();
                if (data.status === 'testing') {
                    this.pollTestStatus();
                    return;
                }

                this.testOk     = !!data.success;
                this.testResult = data.response || data.error || '';
            } catch (e) {
                this.testOk     = false;
                this.testResult = e.message || 'Грешка при свързване.';
                this.testing = false;
            }
        },

        async pollTestStatus() {
            try {
                const resp = await fetch(`/models/${this.id}/test/status`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!resp.ok) {
                    throw new Error((await resp.text()) || `HTTP ${resp.status}`);
                }

                const data = await resp.json();
                if (data.status === 'testing') {
                    this.testPollTimer = setTimeout(() => this.pollTestStatus(), 2000);
                    return;
                }

                // Terminal state (completed / failed). A successful test can come
                // back with an empty response, so fall back to a status line — never
                // leave the box blank, since blank reads as "still testing".
                this.testOk     = !!data.success;
                this.testResult = data.response
                    || data.error
                    || (data.success ? 'Празен отговор от модела.' : 'Тестът не успя.');
            } catch (e) {
                this.testOk     = false;
                this.testResult = e.message || 'Грешка при свързване.';
            }

            // Reached only after a terminal status or an error (the 'testing'
            // branch returns above). Stop the spinner based on STATUS, not on
            // whether there is text to show.
            this.testing = false;
            if (this.testPollTimer) {
                clearTimeout(this.testPollTimer);
                this.testPollTimer = null;
            }
        },
    };
}
</script>
@endsection
