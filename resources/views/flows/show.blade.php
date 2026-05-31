@extends('layouts.app')

@section('title', $flow->name)

@php
$triggeredByLabel = ['manual' => '▶ Ръчно', 'scheduler' => '⏰ Планиран'];
$langFlag = ['bg' => '🇧🇬', 'en' => '🇬🇧', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸', 'ru' => '🇷🇺'];
@endphp

{{-- Clear the create-form draft for this company on successful save --}}
<script>sessionStorage.removeItem('flowai_draft_{{ $flow->company_id }}');</script>

@section('content')
{{-- Header --}}
<div class="flex items-start justify-between mb-6">
    <div>
        <a href="{{ route('companies.show', $flow->company) }}" class="text-indigo-600 hover:underline text-sm inline-flex items-center gap-1">
            ← {{ $flow->company->name }}
        </a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3 flex-wrap">
            {{ $flow->name }}
            @include('partials.status-badge', ['status' => $flow->status, 'class' => 'text-sm px-3'])
        </h1>
        <p class="text-gray-500 mt-1 max-w-2xl">{{ $flow->description }}</p>
    </div>
    <div class="flex items-start gap-2 shrink-0">
        <a href="{{ route('flows.edit', $flow) }}"
           class="inline-flex items-center justify-center bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
            ✏ Редактирай
        </a>
        <form action="{{ route('flow-runs.store', $flow) }}" method="POST">
            @csrf
            <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-bold transition flex items-center gap-2">
                ▶ Стартирай
            </button>
        </form>
    </div>
</div>

{{-- Schedule info --}}
@if($flow->schedule_cron)
<div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-3 mb-6 flex items-center gap-3 text-sm text-blue-800">
    <span class="text-lg">📅</span>
    <div>
        <span class="font-medium">Cron разписание:</span>
        <code class="font-mono bg-blue-100 px-2 py-0.5 rounded ml-1 text-xs">{{ $flow->schedule_cron }}</code>
        @if($flow->last_run_at)
            <span class="text-blue-500 ml-3">Последно: {{ $flow->last_run_at->diffForHumans() }}</span>
        @endif
    </div>
</div>
@endif

{{-- Agents Manager --}}
@php $agentsJson = $flow->agents->sortBy('order')->values(); @endphp
<div x-data="flowAgentManager()" x-init="init()" class="bg-white rounded-xl border border-gray-200 mb-8 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
        <h2 class="text-base font-semibold text-gray-900">
            Агенти
            <span class="ml-2 text-xs bg-gray-200 text-gray-600 px-2 py-0.5 rounded-full" x-text="agents.length"></span>
        </h2>
        <div class="flex items-center gap-3">
            <span x-show="saving" x-cloak class="text-xs text-gray-400">Записва...</span>
            <span x-show="saveStatus === 'ok'" x-cloak class="text-xs text-green-600">Записано ✓</span>
            <span x-show="saveStatus === 'error'" x-cloak class="text-xs text-red-500">Грешка при записване</span>
            <span class="text-xs text-gray-400 hidden sm:inline">Влачи за пренареждане или използвай ↑↓</span>
        </div>
    </div>

    <div x-show="agents.length === 0" x-cloak class="px-6 py-8 text-center text-gray-400 text-sm">
        Няма добавени агенти към този flow.
    </div>

    <div id="agent-sortable-list" class="divide-y divide-gray-50">
        <template x-for="(agent, index) in agents" :key="agent.id">
            <div>
                {{-- Agent card row --}}
                <div class="p-4 flex gap-3 items-start" :class="!agent.is_active ? 'opacity-50' : ''">
                    {{-- Drag handle --}}
                    <div class="drag-handle flex flex-col gap-1 cursor-grab pt-1.5 opacity-30 hover:opacity-100 transition shrink-0"
                         title="Влачи за пренареждане">
                        <span class="block w-4 h-0.5 bg-gray-600 rounded"></span>
                        <span class="block w-4 h-0.5 bg-gray-600 rounded"></span>
                        <span class="block w-4 h-0.5 bg-gray-600 rounded"></span>
                    </div>

                    {{-- Order badge --}}
                    <span class="w-7 h-7 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center text-xs font-bold shrink-0 mt-0.5"
                          x-text="agent.order"></span>

                    {{-- Info --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <span class="font-semibold text-gray-900 text-sm" x-text="agent.name"></span>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-mono" x-text="agent.type"></span>
                            <span x-show="agent.is_verifier" class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">QA verifier</span>
                            <span x-show="!agent.is_active" class="text-xs bg-gray-100 text-gray-400 px-1.5 py-0.5 rounded-full">неактивен</span>
                        </div>
                        <p class="text-xs text-gray-500 leading-relaxed" x-text="(agent.role || '').substring(0,120) + ((agent.role||'').length > 120 ? '...' : '')"></p>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1 shrink-0">
                        <button type="button" @click="moveAgent(index, -1)" :disabled="index === 0"
                                class="text-gray-400 hover:text-gray-700 disabled:opacity-20 px-1.5 py-1 rounded text-sm transition"
                                title="Премести нагоре">↑</button>
                        <button type="button" @click="moveAgent(index, 1)" :disabled="index === agents.length - 1"
                                class="text-gray-400 hover:text-gray-700 disabled:opacity-20 px-1.5 py-1 rounded text-sm transition"
                                title="Премести надолу">↓</button>
                        <button type="button" @click="editingIndex === index ? closeEdit() : openEdit(index)"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-2.5 py-1.5 rounded-lg transition">
                            <span x-text="editingIndex === index ? '✕ Затвори' : '✏ Редактирай'"></span>
                        </button>
                        <a :href="'{{ url('flows/' . $flow->id . '/agents') }}/' + agent.id + '/edit'"
                           class="text-gray-300 hover:text-indigo-600 text-base px-1.5 py-1 transition" title="Пълно редактиране">⚙</a>
                        <button type="button" @click="deleteAgent(index)"
                                class="text-red-400 hover:text-red-600 px-1.5 py-1 text-sm transition"
                                title="Изтрий агент">✕</button>
                    </div>
                </div>

                {{-- Inline edit panel --}}
                <div x-show="editingIndex === index" x-cloak
                     class="mx-4 mb-4 bg-indigo-50 border border-indigo-200 rounded-xl p-4">
                    <h4 class="text-xs font-semibold text-indigo-700 uppercase tracking-wide mb-3">Редактиране на агент</h4>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Име</label>
                            <input type="text" x-model="agent.name"
                                   class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Тип</label>
                            @include('partials.agent-type-select', [
                                'xIdExpr' => "'show-type-ts-' + index",
                            ])
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Роля / Описание</label>
                            <textarea x-model="agent.role" rows="2"
                                      class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">System промпт</label>
                            <textarea x-model="agent.system_prompt" rows="3"
                                      class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                      placeholder="Ти си специализиран агент за..."></textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Промпт шаблон</label>
                            <textarea x-model="agent.prompt_template" rows="5"
                                      class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                      placeholder="Инструкции за агента с @{{placeholder}}-и..."></textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Модел</label>
                            <select :id="'show-model-ts-' + index"></select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeEdit()"
                                class="bg-white border border-gray-300 text-gray-600 text-sm px-3 py-1.5 rounded-lg hover:bg-gray-50 transition">
                            Откажи
                        </button>
                        <button type="button" @click="saveEdit(agent)"
                                class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                            ✓ Запази
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Add agent --}}
    <div class="px-6 py-3 border-t border-dashed border-gray-200 flex justify-center">
        <button type="button" @click="openAgentPicker()"
                class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold flex items-center gap-1.5 px-4 py-2 rounded-lg hover:bg-indigo-50 transition">
            ＋ Добави агент
        </button>
    </div>

    {{-- Agent Picker Modal --}}
    <div x-show="showPicker" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="showPicker = false">
        <div class="absolute inset-0 bg-black/40" @click="showPicker = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-[820px] overflow-hidden"
             @click.stop>
            {{-- Header --}}
            <div class="px-6 pt-5 pb-0 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900">Добави агент</h3>
                <button @click="showPicker = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>

            {{-- Tabs --}}
            <div class="flex px-6 pt-3 pb-0 border-b border-gray-200 gap-1">
                <template x-for="tab in pickerTabs" :key="tab.id">
                    <button type="button"
                            @click="activePickerTab = tab.id"
                            :class="activePickerTab === tab.id
                                ? 'border-indigo-600 text-indigo-700 font-semibold bg-indigo-50'
                                : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="px-4 py-2 text-sm border-b-2 -mb-px rounded-t-lg transition whitespace-nowrap"
                            x-text="tab.label">
                    </button>
                </template>
            </div>

            {{-- Body --}}
            <div class="p-6 max-h-[480px] overflow-y-auto">
                {{-- Search --}}
                <div class="mb-4">
                    <input type="text" x-model="pickerSearch"
                           placeholder="🔍 Търси по ime или тип..."
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                {{-- Loading --}}
                <div x-show="pickerLoading" class="text-center py-8 text-gray-400 text-sm">
                    <span class="inline-block w-5 h-5 border-2 border-indigo-400 border-t-transparent rounded-full animate-spin mr-2"></span>
                    Зарежда шаблони...
                </div>

                {{-- "Всички" tab --}}
                <div x-show="!pickerLoading && activePickerTab === 'all'">
                    <div class="mb-4">
                        <div @click="selectTemplate(null)"
                             class="flex items-center gap-4 p-4 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                            <span class="text-3xl">➕</span>
                            <div class="flex-1">
                                <div class="font-semibold text-sm text-gray-900">Нов празен агент</div>
                                <div class="text-xs text-gray-500">Започни от нулата — всички полета са празни</div>
                            </div>
                            <span class="text-indigo-600 text-sm font-semibold">Избери →</span>
                        </div>
                    </div>

                    <template x-if="filteredCompanyTemplates.length > 0">
                        <div class="mb-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">🏢 Моите агенти</p>
                            <div class="grid grid-cols-4 gap-2">
                                <template x-for="tpl in filteredCompanyTemplates" :key="tpl.id">
                                    <div @click="selectTemplate(tpl)"
                                         class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                        <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                        <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                        <div class="text-[12px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                        <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-green-100 text-green-700" x-text="tpl.type"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <template x-if="filteredSystemTemplates.length > 0">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">⚙ Системни агенти</p>
                            <div class="grid grid-cols-4 gap-2">
                                <template x-for="tpl in filteredSystemTemplates" :key="tpl.id">
                                    <div @click="selectTemplate(tpl)"
                                         class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                        <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                        <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                        <div class="text-[12px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                        <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-violet-100 text-violet-700" x-text="tpl.type"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div x-show="filteredCompanyTemplates.length === 0 && filteredSystemTemplates.length === 0 && pickerSearch"
                         class="text-center py-8 text-gray-400 text-sm">
                        Няма резултати за "<span x-text="pickerSearch"></span>"
                    </div>
                </div>

                {{-- "Моите агенти" tab --}}
                <div x-show="!pickerLoading && activePickerTab === 'mine'">
                    <div x-show="filteredCompanyTemplates.length === 0" class="text-center py-8 text-gray-400 text-sm">
                        <p class="text-3xl mb-2">🏢</p>
                        Нямате запазени агент шаблони.
                        <a href="{{ route('companies.agent-templates.index', $flow->company) }}"
                           class="text-indigo-600 underline block mt-1">Управлявай агентите на компанията →</a>
                    </div>
                    <div x-show="filteredCompanyTemplates.length > 0" class="grid grid-cols-4 gap-2">
                        <template x-for="tpl in filteredCompanyTemplates" :key="tpl.id">
                            <div @click="selectTemplate(tpl)"
                                 class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                <div class="text-[12px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-green-100 text-green-700" x-text="tpl.type"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- "Системни агенти" tab --}}
                <div x-show="!pickerLoading && activePickerTab === 'system'">
                    <div x-show="filteredSystemTemplates.length === 0" class="text-center py-8 text-gray-400 text-sm">
                        Няма системни агент шаблони.
                    </div>
                    <div x-show="filteredSystemTemplates.length > 0" class="grid grid-cols-4 gap-2">
                        <template x-for="tpl in filteredSystemTemplates" :key="tpl.id">
                            <div @click="selectTemplate(tpl)"
                                 class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                <div class="text-[12px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-violet-100 text-violet-700" x-text="tpl.type"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- QA position warning --}}
    <div x-show="agents.length > 0 && agents[agents.length-1] && agents[agents.length-1].type !== 'qa_verifier' && agents.some(a => a.type === 'qa_verifier')"
         x-cloak
         class="mx-4 mb-4 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 text-xs text-amber-700">
        ⚠ QA verifier агентът не е последен в pipeline-а. Препоръчително е да е на последна позиция.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
const FLOW_ID      = {{ $flow->id }};
const AGENTS_DATA  = @json($agentsJson);
const MODELS_DATA  = @json($models);
const CSRF_TOKEN   = document.querySelector('meta[name="csrf-token"]').content;

function flowAgentManager() {
    return {
        agents: [],
        models: [],
        editingIndex: null,
        saving: false,
        saveStatus: '',
        _sortable: null,
        _reorderTimer: null,
        _modelTS: null,

        // ── Agent Picker ─────────────────────────────────────────
        showPicker: false,
        activePickerTab: 'all',
        pickerSearch: '',
        pickerLoading: false,
        pickerLoaded: false,
        pickerTemplates: { system: [], company: [] },
        pickerTabs: [
            { id: 'all',    label: 'Всички' },
            { id: 'mine',   label: '🏢 Моите агенти' },
            { id: 'system', label: '⚙ Системни агенти' },
        ],

        init() {
            this.agents = AGENTS_DATA.map(a => ({ ...a }));
            this.models = MODELS_DATA;
            this.$nextTick(() => this.initSortable());
        },

        initSortable() {
            const el = document.getElementById('agent-sortable-list');
            if (!el || typeof Sortable === 'undefined') return;
            if (this._sortable) { this._sortable.destroy(); this._sortable = null; }
            this._sortable = Sortable.create(el, {
                handle: '.drag-handle',
                animation: 150,
                onEnd: (evt) => {
                    if (evt.oldIndex === evt.newIndex) return;
                    const moved = this.agents.splice(evt.oldIndex, 1)[0];
                    this.agents.splice(evt.newIndex, 0, moved);
                    this.renumberAgents();
                    this.reorderRemote();
                    this.$nextTick(() => this.initSortable());
                },
            });
        },

        buildModelOptions(agentType) {
            const recommended = MODELS_DATA.filter(m => (m.is_default_for || []).includes(agentType));
            const others      = MODELS_DATA.filter(m => !(m.is_default_for || []).includes(agentType));
            const options = [], optgroups = [];
            if (recommended.length) {
                optgroups.push({ value: 'recommended', label: 'Препоръчани за ' + agentType });
                recommended.forEach(m => options.push({ value: m.ollama_tag, text: m.display_name, description: m.description || '', optgroup: 'recommended' }));
            }
            if (others.length) {
                optgroups.push({ value: 'others', label: 'Останали' });
                others.forEach(m => options.push({ value: m.ollama_tag, text: m.display_name, description: m.description || '', optgroup: 'others' }));
            }
            return { options, optgroups };
        },

        initShowModelSelect(index) {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            const agent = this.agents[index];
            if (!agent) return;
            const sel = document.getElementById('show-model-ts-' + index);
            if (!sel) return;
            const { options, optgroups } = this.buildModelOptions(agent.type || '');
            const self = this;
            this._modelTS = new TomSelect(sel, {
                options,
                optgroups,
                optgroupField: 'optgroup',
                items: [agent.model],
                maxItems: 1,
                create: false,
                onChange(value) { self.agents[index].model = value; },
                render: {
                    option: (data, escape) =>
                        `<div class="py-0.5">
                            <div class="font-medium text-gray-800">${escape(data.text)}</div>
                            ${data.description ? `<div class="text-xs text-gray-400 mt-0.5 leading-tight">${escape(data.description)}</div>` : ''}
                        </div>`,
                    item: (data, escape) => `<div>${escape(data.text)}</div>`,
                },
            });
        },

        openEdit(index) {
            this.editingIndex = index;
            this.$nextTick(() => {
                initAgentTypeSelect('show-type-ts-' + index, this.agents[index].type, v => {
                    this.agents[index].type = v;
                    this.initShowModelSelect(index);
                });
                this.initShowModelSelect(index);
            });
        },

        closeEdit() {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            this.editingIndex = null;
        },

        moveAgent(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= this.agents.length) return;
            const tmp = this.agents[index];
            this.agents[index] = this.agents[newIndex];
            this.agents[newIndex] = tmp;
            this.renumberAgents();
            if (this.editingIndex === index) this.editingIndex = newIndex;
            this.reorderRemote();
        },

        renumberAgents() {
            this.agents.forEach((a, i) => { a.order = i + 1; });
        },

        reorderRemote() {
            clearTimeout(this._reorderTimer);
            this._reorderTimer = setTimeout(async () => {
                const ids = this.agents.map(a => a.id);
                await this.ajax('POST', `/flows/${FLOW_ID}/agents/reorder`, { ids });
            }, 400);
        },

        async addAgent() {
            const firstModel = this.models.find(m => m.is_available) || this.models[0];
            const data = {
                name:  'Нов агент',
                model: firstModel ? firstModel.ollama_tag : '',
                type:  'content_bg',
            };
            this.saving = true;
            const result = await this.ajax('POST', `/flows/${FLOW_ID}/agents`, data);
            this.saving = false;
            if (result) {
                this.agents.push(result.agent);
                this.editingIndex = this.agents.length - 1;
                this.$nextTick(() => this.initSortable());
            }
        },

        get filteredSystemTemplates() {
            const q = this.pickerSearch.toLowerCase();
            return this.pickerTemplates.system.filter(t =>
                !q || t.name.toLowerCase().includes(q) || t.type.toLowerCase().includes(q)
            );
        },

        get filteredCompanyTemplates() {
            const q = this.pickerSearch.toLowerCase();
            return this.pickerTemplates.company.filter(t =>
                !q || t.name.toLowerCase().includes(q) || t.type.toLowerCase().includes(q)
            );
        },

        async openAgentPicker() {
            this.showPicker = true;
            this.activePickerTab = 'all';
            this.pickerSearch = '';

            if (this.pickerLoaded) return;

            this.pickerLoading = true;
            try {
                const resp = await fetch(`{{ route('agent-templates.picker') }}?company_id={{ $flow->company_id }}`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (resp.ok) {
                    this.pickerTemplates = await resp.json();
                    this.pickerLoaded = true;
                } else {
                    console.error('Failed to load templates, status:', resp.status);
                }
            } catch (e) {
                console.error('Failed to load templates', e);
            } finally {
                this.pickerLoading = false;
            }
        },

        async selectTemplate(tpl) {
            const firstModel = this.models.find(m => m.is_available) || this.models[0];
            const resolvedModel = tpl && tpl.model && this.models.find(m => m.ollama_tag === tpl.model)
                ? tpl.model
                : (firstModel ? firstModel.ollama_tag : '');

            const data = {
                name:             tpl ? (tpl.name            || 'Нов агент')   : 'Нов агент',
                model:            resolvedModel,
                type:             tpl ? (tpl.type            || 'content_bg')  : 'content_bg',
                role:             tpl ? (tpl.role            || '')             : '',
                system_prompt:    tpl ? (tpl.system_prompt   || '')             : '',
                prompt_template:  tpl ? (tpl.prompt_template || '')             : '',
            };

            this.showPicker = false;
            this.saving = true;
            const result = await this.ajax('POST', `/flows/${FLOW_ID}/agents`, data);
            this.saving = false;
            if (result) {
                this.agents.push(result.agent);
                this.editingIndex = this.agents.length - 1;
                this.$nextTick(() => this.initSortable());
            }
        },

        async saveEdit(agent) {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            this.saving = true;
            const result = await this.ajax('PUT', `/flows/${FLOW_ID}/agents/${agent.id}`, {
                name:             agent.name,
                role:             agent.role || '',
                type:             agent.type,
                system_prompt:    agent.system_prompt || '',
                prompt_template:  agent.prompt_template || '',
                model:            agent.model,
            });
            this.saving = false;
            if (result) {
                const idx = this.agents.findIndex(a => a.id === agent.id);
                if (idx !== -1) this.agents[idx] = { ...this.agents[idx], ...result.agent };
                this.editingIndex = null;
            }
        },

        async deleteAgent(index) {
            if (!confirm('Изтрий агент "' + this.agents[index].name + '"?')) return;
            const agent = this.agents[index];
            this.saving = true;
            const ok = await this.ajax('DELETE', `/flows/${FLOW_ID}/agents/${agent.id}`);
            this.saving = false;
            if (ok !== null) {
                this.agents.splice(index, 1);
                this.renumberAgents();
                if (this.editingIndex === index) {
                    this.editingIndex = null;
                } else if (this.editingIndex !== null && this.editingIndex > index) {
                    this.editingIndex--;
                }
                this.$nextTick(() => this.initSortable());
            }
        },

        async ajax(method, url, data = null) {
            this.saveStatus = '';
            const opts = {
                method,
                headers: {
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
            };
            if (data) opts.body = JSON.stringify(data);
            try {
                const resp = await fetch(url, opts);
                if (resp.status === 204) { this.flashOk(); return {}; }
                const json = await resp.json();
                if (!resp.ok) {
                    this.saveStatus = 'error';
                    console.error('Agent API error', json);
                    return null;
                }
                this.flashOk();
                return json;
            } catch (e) {
                this.saveStatus = 'error';
                console.error('Agent API network error', e);
                return null;
            }
        },

        flashOk() {
            this.saveStatus = 'ok';
            setTimeout(() => { if (this.saveStatus === 'ok') this.saveStatus = ''; }, 2000);
        },
    };
}
</script>

{{-- Run History --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900">История на изпълненията</h2>
        @if($runs->isNotEmpty())
            <span class="text-xs text-gray-400">Последни {{ $runs->count() }}</span>
        @endif
    </div>

    @if($runs->isEmpty())
        <div class="px-6 py-10 text-center">
            <p class="text-gray-400 text-sm mb-3">Все още няма изпълнения</p>
            <p class="text-xs text-gray-300">Натисни ▶ Стартирай за първото изпълнение</p>
        </div>
    @else
        <div class="divide-y divide-gray-50">
            @foreach($runs as $run)
            <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50/60 transition">
                <div class="flex items-center gap-3">
                    {{-- Status dot --}}
                    <span @class([
                        'w-2 h-2 rounded-full shrink-0',
                        'bg-green-500'                   => $run->status === 'completed',
                        'bg-red-500'                     => $run->status === 'failed',
                        'bg-blue-500 animate-pulse'      => $run->status === 'running',
                        'bg-gray-300'                    => $run->status === 'pending',
                    ])></span>
                    @include('partials.status-badge', ['status' => $run->status])
                    <span class="text-xs text-gray-400">
                        {{ $triggeredByLabel[$run->triggered_by] ?? $run->triggered_by }}
                    </span>
                </div>

                <div class="flex items-center gap-4">
                    @if($run->started_at)
                        <span class="text-xs text-gray-400">{{ $run->started_at->format('d.m.Y H:i') }}</span>
                    @endif
                    @if($run->started_at && $run->completed_at)
                        @php $secs = $run->started_at->diffInSeconds($run->completed_at); @endphp
                        <span class="text-xs text-gray-400 tabular-nums">
                            {{ $secs >= 60 ? floor($secs/60).'м '.($secs%60).'с' : $secs.'с' }}
                        </span>
                    @endif
                    <a href="{{ route('flow-runs.show', $run) }}"
                       class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Детайли →</a>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
