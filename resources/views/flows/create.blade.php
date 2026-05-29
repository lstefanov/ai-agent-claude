@extends('layouts.app')

@section('title', 'Нов flow — ' . $company->name)

@section('content')
<div x-data="flowCreator()" x-init="init()">

    <div class="mb-6">
        <a href="{{ route('companies.show', $company) }}" class="text-indigo-600 hover:underline text-sm">
            ← {{ $company->name }}
        </a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2">Нов flow</h1>
        <p class="text-gray-500 mt-1">Опиши flow-а и AI ще генерира агентите автоматично.</p>
    </div>

    <form action="{{ route('companies.flows.store', $company) }}" method="POST" id="flow-form">
        @csrf

        {{-- Step 1: Basic Info --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">1. Основна информация</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Наименование на flow-а</label>
                    <input type="text" name="name" x-model="flowName" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="напр. Ежедневен Facebook пост">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Описание на flow-а</label>
                    <textarea name="description" x-model="flowDescription" rows="4" required
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                              placeholder="Опиши подробно какво трябва да прави flow-ът. Колкото по-детайлно, толкова по-добри агенти ще генерира AI."></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Статус</label>
                        <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                        </select>
                    </div>
                </div>

                {{-- Schedule picker (full width) --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        График на изпълнение
                        <span class="text-gray-400 font-normal text-xs ml-1">(по избор)</span>
                    </label>

                    {{-- Hidden input carries the cron value for form submission --}}
                    <input type="hidden" name="schedule_cron" :value="cronValue">

                    {{-- Preset buttons --}}
                    <div class="grid grid-cols-5 gap-2 mb-3">
                        <template x-for="preset in [
                            { id: 'none',    icon: '—',  label: 'Никога',   sub: 'само ръчно' },
                            { id: 'hourly',  icon: '🕐', label: 'На час',   sub: 'всеки час' },
                            { id: 'daily',   icon: '📅', label: 'Дневно',   sub: 'веднъж/ден' },
                            { id: 'weekly',  icon: '📆', label: 'Седмично', sub: 'веднъж/седм.' },
                            { id: 'monthly', icon: '🗓', label: 'Месечно',  sub: 'веднъж/месец' },
                        ]" :key="preset.id">
                            <button type="button"
                                    @click="schedule.preset = preset.id; schedule.showCustom = false"
                                    :class="schedule.preset === preset.id
                                        ? 'bg-indigo-600 border-indigo-600 text-white'
                                        : 'bg-white border-gray-200 text-gray-700 hover:border-indigo-300'"
                                    class="border rounded-xl p-2.5 text-center cursor-pointer transition text-sm">
                                <span class="block text-lg leading-none mb-1" x-text="preset.icon"></span>
                                <span class="block font-semibold text-xs" x-text="preset.label"></span>
                                <span class="block text-[10px] opacity-70" x-text="preset.sub"></span>
                            </button>
                        </template>
                    </div>

                    {{-- Time picker — shown for daily/weekly/monthly --}}
                    <div x-show="['daily','weekly','monthly'].includes(schedule.preset)" x-cloak
                         class="flex flex-wrap items-center gap-3 mb-3">

                        <template x-if="schedule.preset === 'weekly'">
                            <div class="flex items-center gap-2">
                                <label class="text-sm text-gray-600 whitespace-nowrap">Ден от седмицата:</label>
                                <select x-model="schedule.dayOfWeek"
                                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <option value="1">Понеделник</option>
                                    <option value="2">Вторник</option>
                                    <option value="3">Сряда</option>
                                    <option value="4">Четвъртък</option>
                                    <option value="5">Петък</option>
                                    <option value="6">Събота</option>
                                    <option value="0">Неделя</option>
                                </select>
                            </div>
                        </template>

                        <template x-if="schedule.preset === 'monthly'">
                            <div class="flex items-center gap-2">
                                <label class="text-sm text-gray-600 whitespace-nowrap">Ден от месеца:</label>
                                <select x-model="schedule.dayOfMonth"
                                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <template x-for="d in Array.from({length:31},(_,i)=>i+1)" :key="d">
                                        <option :value="d" x-text="d"></option>
                                    </template>
                                </select>
                            </div>
                        </template>

                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600 whitespace-nowrap">В колко часа:</label>
                            <select x-model="schedule.hour"
                                    class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <template x-for="h in Array.from({length:24},(_,i)=>i)" :key="h">
                                    <option :value="h" x-text="String(h).padStart(2,'0') + ':00'"></option>
                                </template>
                            </select>
                        </div>
                    </div>

                    {{-- Human-readable summary --}}
                    <div x-show="schedule.preset !== 'none'" x-cloak
                         class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-xs text-gray-500 flex items-center gap-2 mb-2">
                        <span>📋</span>
                        <span>
                            <template x-if="schedule.preset === 'hourly'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всеки час</strong></span>
                            </template>
                            <template x-if="schedule.preset === 'daily'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всеки ден в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                            </template>
                            <template x-if="schedule.preset === 'weekly'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всяка седмица в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                            </template>
                            <template x-if="schedule.preset === 'monthly'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всеки месец на <span x-text="schedule.dayOfMonth"></span>-ти в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                            </template>
                            <template x-if="schedule.preset === 'custom'">
                                <span>Cron: <code class="font-mono" x-text="schedule.customCron"></code></span>
                            </template>
                            · <code class="font-mono text-gray-400" x-text="cronValue"></code>
                        </span>
                    </div>

                    {{-- Advanced / custom cron --}}
                    <button type="button" @click="schedule.showCustom = !schedule.showCustom; if(schedule.showCustom) schedule.preset = 'custom'"
                            class="text-xs text-gray-400 hover:text-gray-600 underline transition">
                        ⚙ По избор (напреднали)
                    </button>
                    <div x-show="schedule.showCustom" x-cloak class="mt-2">
                        <input type="text" x-model="schedule.customCron"
                               placeholder="напр. 0 10 * * 1-5"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <p class="text-xs text-gray-400 mt-1">Стандартен cron синтаксис: минута час ден-от-месец месец ден-от-седмица</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 2: AI Agent Generator --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-2">2. Генерирай агенти с AI</h2>
            <p class="text-sm text-gray-500 mb-4">AI ще анализира описанието и ще предложи оптималните агенти.</p>

            <button type="button" @click="generateAgents"
                    :disabled="isGenerating || !flowDescription.trim() || !flowName.trim()"
                    class="bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                <span x-show="isGenerating" class="inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                <span x-text="isGenerating ? 'Анализирам и генерирам агенти... (60-120 сек)' : '✨ Генерирай агенти с AI'"></span>
            </button>
            <p x-show="isGenerating" x-cloak class="mt-2 text-xs text-gray-400 animate-pulse">
                AI анализира описанието и проектира пълния pipeline — изчакай, не затваряй страницата.
            </p>

            <div x-show="errorMessage" x-cloak class="mt-4 bg-red-50 border border-red-300 text-red-700 px-4 py-3 rounded-lg text-sm">
                <span x-text="errorMessage"></span>
            </div>
        </div>

        {{-- Step 3: Agent Preview --}}
        <div x-show="agents.length > 0" x-cloak class="bg-white rounded-xl border border-gray-200 mb-6">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">
                    3. Преглед на агентите (<span x-text="agents.length"></span>)
                </h2>
                <span class="text-sm text-gray-400">Можеш да промениш модела на всеки агент</span>
            </div>

            <div class="divide-y divide-gray-50">
                <template x-for="(agent, index) in agents" :key="index">
                    <div class="p-6" x-data="{ expanded: false }">
                        {{-- Hidden inputs for form submission --}}
                        <input type="hidden" :name="'agents['+index+'][name]'"             :value="agent.name">
                        <input type="hidden" :name="'agents['+index+'][type]'"             :value="agent.type">
                        <input type="hidden" :name="'agents['+index+'][role]'"             :value="agent.role">
                        <input type="hidden" :name="'agents['+index+'][strengths]'"        :value="agent.strengths">
                        <input type="hidden" :name="'agents['+index+'][limitations]'"      :value="agent.limitations">
                        <input type="hidden" :name="'agents['+index+'][input_description]'" :value="agent.input_description">
                        <input type="hidden" :name="'agents['+index+'][output_description]'" :value="agent.output_description">
                        <input type="hidden" :name="'agents['+index+'][prompt_template]'"  :value="agent.prompt_template">
                        <input type="hidden" :name="'agents['+index+'][model_reason]'"     :value="agent.model_reason">
                        <input type="hidden" :name="'agents['+index+'][order]'"            :value="agent.order">
                        <input type="hidden" :name="'agents['+index+'][is_verifier]'"      :value="agent.is_verifier ? '1' : '0'">
                        <input type="hidden" :name="'agents['+index+'][qa_threshold]'"     :value="agent.qa_threshold">
                        <input type="hidden" :name="'agents['+index+'][config][temperature]'" :value="agent.config ? agent.config.temperature : 0.7">
                        <input type="hidden" :name="'agents['+index+'][config][max_tokens]'"  :value="agent.config ? agent.config.max_tokens : 500">
                        <template x-if="agent.capabilities">
                            <template x-for="(cap, ci) in agent.capabilities" :key="ci">
                                <input type="hidden" :name="'agents['+index+'][capabilities]['+ci+']'" :value="cap">
                            </template>
                        </template>

                        <div class="flex items-start gap-4">
                            <span class="w-8 h-8 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center text-sm font-bold shrink-0 mt-0.5"
                                  x-text="agent.order"></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1 flex-wrap">
                                    <span class="font-semibold text-gray-900" x-text="agent.name"></span>
                                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-mono" x-text="agent.type"></span>
                                    <span x-show="agent.is_verifier" class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">QA verifier</span>
                                </div>
                                <p class="text-sm text-gray-600 mb-3" x-text="agent.role.substring(0, 140) + (agent.role.length > 140 ? '...' : '')"></p>

                                {{-- Model selector --}}
                                <div class="flex items-center gap-3 flex-wrap">
                                    <label class="text-xs font-medium text-gray-500">Модел:</label>
                                    {{-- Hidden input always carries the value — select updates it --}}
                                    <input type="hidden" :name="'agents['+index+'][model]'" :value="agent.model">
                                    <select x-model="agent.model"
                                            class="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                        @foreach($models as $m)
                                            <option value="{{ $m->ollama_tag }}">
                                                {{ !$m->is_available ? '⚠ ' : '' }}{{ $m->display_name }} ({{ $m->ollama_tag }}){{ !$m->is_available ? ' — не е изтеглен' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <span class="text-xs text-gray-400 italic" x-text="'AI: ' + (agent.model_reason || '').substring(0, 60)"></span>
                                </div>
                            </div>
                            <button type="button" @click="expanded = !expanded"
                                    class="text-gray-400 hover:text-gray-600 text-xs transition shrink-0">
                                <span x-text="expanded ? '▲ Скрий' : '▼ Покажи всичко'"></span>
                            </button>
                        </div>

                        {{-- Expanded details --}}
                        <div x-show="expanded" x-cloak class="mt-4 ml-12 space-y-3 text-sm">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase mb-1">Вход</p>
                                    <p class="text-gray-700" x-text="agent.input_description"></p>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase mb-1">Изход</p>
                                    <p class="text-gray-700" x-text="agent.output_description"></p>
                                </div>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase mb-1">Силни страни</p>
                                <p class="text-gray-700" x-text="agent.strengths"></p>
                            </div>
                            <div x-show="agent.qa_threshold">
                                <p class="text-xs font-medium text-gray-500 uppercase mb-1">QA праг</p>
                                <p class="text-gray-700">Минимален score: <strong x-text="agent.qa_threshold"></strong>/100</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase mb-1">Prompt шаблон</p>
                                <pre class="bg-gray-50 border border-gray-200 rounded p-3 text-xs text-gray-600 overflow-auto max-h-32 whitespace-pre-wrap" x-text="agent.prompt_template"></pre>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Submit --}}
        <div x-show="agents.length > 0" x-cloak class="flex gap-3">
            <button type="submit"
                    class="bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-lg font-bold text-base transition">
                💾 Запази flow с агентите
            </button>
            <a href="{{ route('companies.show', $company) }}"
               class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-medium transition">
                Откажи
            </a>
        </div>

    </form>
</div>

<script>
// Available model tags from server (for fallback logic)
const AVAILABLE_MODELS = @json($models->where('is_available', true)->pluck('ollama_tag')->values());
const ALL_MODEL_TAGS   = @json($models->pluck('ollama_tag')->values());
const STORAGE_KEY      = 'flowai_draft_{{ $company->id }}';

function flowCreator() {
    return {
        flowName: '',
        flowDescription: '',
        agents: [],
        isGenerating: false,
        errorMessage: '',
        schedule: {
            preset: 'none',
            hour: '10',
            dayOfWeek: '1',
            dayOfMonth: '1',
            customCron: '',
            showCustom: false,
        },

        get cronValue() {
            const s = this.schedule;
            if (s.preset === 'none') return '';
            if (s.preset === 'hourly') return '0 * * * *';
            if (s.preset === 'daily') return `0 ${s.hour} * * *`;
            if (s.preset === 'weekly') return `0 ${s.hour} * * ${s.dayOfWeek}`;
            if (s.preset === 'monthly') return `0 ${s.hour} ${s.dayOfMonth} * *`;
            if (s.preset === 'custom') return s.customCron;
            return '';
        },

        init() {
            // ── Restore from sessionStorage (after validation error redirect) ──
            const saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved) {
                try {
                    const draft = JSON.parse(saved);
                    this.flowName        = draft.name        || '';
                    this.flowDescription = draft.description || '';
                    this.agents          = draft.agents      || [];
                    this.schedule        = draft.schedule    || this.schedule;
                    // Clear only after successful restore; keep for next reload if needed
                    // sessionStorage.removeItem(STORAGE_KEY); // cleared on success in store()
                } catch (e) { sessionStorage.removeItem(STORAGE_KEY); }
            }

            // Also sync text inputs with x-model values (Blade old() doesn't work with Alpine)
            if (!this.flowName) {
                const nameEl = document.querySelector('input[name="name"]');
                if (nameEl?.value) this.flowName = nameEl.value;
            }
            if (!this.flowDescription) {
                const descEl = document.querySelector('textarea[name="description"]');
                if (descEl?.value) this.flowDescription = descEl.value;
            }

            // ── Save to sessionStorage before any form submit ─────────────
            document.getElementById('flow-form').addEventListener('submit', () => {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                    name:        this.flowName,
                    description: this.flowDescription,
                    agents:      this.agents,
                    schedule:    this.schedule,
                }));
            });
        },

        async generateAgents() {
            this.isGenerating = true;
            this.errorMessage = '';
            this.agents       = [];

            const csrf = document.querySelector('meta[name="csrf-token"]').content;

            // ── Step 1: start the background job, get token ───────────────
            let token;
            try {
                const resp = await fetch('{{ route('flows.generate-agents') }}', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        company_id:  {{ $company->id }},
                        name:        this.flowName,
                        description: this.flowDescription,
                    }),
                });
                const data = await resp.json();
                if (!resp.ok) { this.errorMessage = data.error || 'Грешка при стартиране.'; this.isGenerating = false; return; }
                token = data.token;
            } catch (e) {
                this.errorMessage = 'Мрежова грешка: ' + e.message;
                this.isGenerating = false;
                return;
            }

            // ── Step 2: poll every 2 seconds until completed/failed ───────
            const maxWait = 300; // seconds
            const started = Date.now();

            const poll = async () => {
                if ((Date.now() - started) / 1000 > maxWait) {
                    this.errorMessage = 'Генерацията отне прекалено дълго. Провери дали Ollama работи и опитай отново.';
                    this.isGenerating = false;
                    return;
                }

                try {
                    const resp = await fetch(`/flows/generation-status/${token}`, { headers: { 'Accept': 'application/json' } });
                    const data = await resp.json();

                    if (data.status === 'pending') {
                        setTimeout(poll, 2000); // still running — check again
                        return;
                    }

                    if (data.status === 'failed') {
                        this.errorMessage = data.error || 'Генерацията се провали. Опитай отново.';
                        this.isGenerating = false;
                        return;
                    }

                    if (data.status === 'completed') {
                        this.agents = (data.agents || []).map(agent => {
                            // If AI suggested a model that isn't in our list at all,
                            // or the model tag doesn't exist, fall back to first available.
                            if (!ALL_MODEL_TAGS.includes(agent.model)) {
                                const fallback = AVAILABLE_MODELS[0] || ALL_MODEL_TAGS[0];
                                agent.model_reason = `(Оригинален модел "${agent.model}" не е в списъка — заменен с ${fallback}) ${agent.model_reason || ''}`;
                                agent.model = fallback;
                            }
                            return agent;
                        });
                        if (this.agents.length === 0) {
                            this.errorMessage = 'AI не върна агенти. Опитай с по-подробно описание.';
                        }
                        this.isGenerating = false;
                        return;
                    }

                    // Expired or unknown
                    this.errorMessage = data.error || 'Неочаквана грешка.';
                    this.isGenerating = false;

                } catch (e) {
                    this.errorMessage = 'Грешка при polling: ' + e.message;
                    this.isGenerating = false;
                }
            };

            setTimeout(poll, 2000);
        },
    };
}
</script>
@endsection
