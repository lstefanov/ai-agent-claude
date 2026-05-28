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
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cron разписание <span class="text-gray-400">(по избор)</span></label>
                        <input type="text" name="schedule_cron"
                               placeholder="напр. 0 10 * * *"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-sm">
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
                                    <select :name="'agents['+index+'][model]'" x-model="agent.model"
                                            class="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                        @foreach($models as $m)
                                            <option value="{{ $m->ollama_tag }}"
                                                    @if(!$m->is_available) disabled @endif>
                                                {{ $m->display_name }} ({{ $m->ollama_tag }}){{ !$m->is_available ? ' — недостъпен' : '' }}
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
function flowCreator() {
    return {
        flowName: '',
        flowDescription: '',
        agents: [],
        isGenerating: false,
        errorMessage: '',

        init() {
            // Pre-fill if old values exist (after validation error)
            const nameEl = document.querySelector('input[name="name"]');
            const descEl = document.querySelector('textarea[name="description"]');
            if (nameEl) this.flowName = nameEl.value;
            if (descEl) this.flowDescription = descEl.value;
        },

        async generateAgents() {
            this.isGenerating  = true;
            this.errorMessage  = '';
            this.agents        = [];

            try {
                const response = await fetch('{{ route('flows.generate-agents') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        company_id:  {{ $company->id }},
                        name:        this.flowName,
                        description: this.flowDescription,
                    }),
                });

                let data;
                try {
                    data = await response.json();
                } catch (parseErr) {
                    // Server returned non-JSON — likely PHP timeout (MAMP max_execution_time)
                    this.errorMessage = `Сървърът не отговори навреме (HTTP ${response.status}). Генерацията отнема 60-120 секунди — опитай отново.`;
                    return;
                }

                if (!response.ok) {
                    this.errorMessage = data.error || `Грешка при генериране (HTTP ${response.status}).`;
                    return;
                }

                this.agents = data.agents || [];

                if (this.agents.length === 0) {
                    this.errorMessage = 'AI не върна агенти. Опитай с по-подробно описание.';
                }
            } catch (e) {
                this.errorMessage = 'Мрежова грешка: ' + e.message;
            } finally {
                this.isGenerating = false;
            }
        },
    };
}
</script>
@endsection
