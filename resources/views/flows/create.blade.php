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
                    <div class="relative">
                        <textarea name="description" x-model="flowDescription" rows="4" required
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 pb-10 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                  placeholder="Опиши подробно какво трябва да прави flow-ът. Колкото по-детайлно, толкова по-добри агенти ще генерира AI."></textarea>
                        <button type="button"
                                @click="improveDescription"
                                :disabled="isImproving || !flowDescription.trim()"
                                class="absolute bottom-2 right-2 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition">
                            <span x-show="isImproving" class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                            <span x-text="isImproving ? 'Подобрявам...' : '✨ Подобри с AI'"></span>
                        </button>
                    </div>

                    {{-- AI preview panel --}}
                    <div x-show="showImprovePreview" x-cloak
                         class="mt-3 bg-indigo-50 border border-indigo-200 rounded-xl p-4">
                        <p class="text-xs font-semibold text-indigo-700 mb-2">✨ AI предлага подобрено описание:</p>
                        <p class="text-sm text-gray-700 leading-relaxed mb-3" x-text="improvedDescription"></p>
                        <div class="flex gap-2">
                            <button type="button" @click="acceptImprovedDescription"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                                ✓ Приеми
                            </button>
                            <button type="button" @click="showImprovePreview = false"
                                    class="bg-white border border-gray-300 text-gray-600 text-sm px-4 py-1.5 rounded-lg hover:bg-gray-50 transition">
                                ✕ Откажи
                            </button>
                        </div>
                    </div>
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

        {{-- Step 3: Agent Preview + Inline Editor --}}
        <div x-show="agents.length > 0" x-cloak class="bg-white rounded-xl border border-gray-200 mb-6">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">
                    3. Агенти (<span x-text="agents.length"></span>)
                </h2>
                <span class="text-sm text-gray-400">Влачи за пренареждане или използвай ↑↓</span>
            </div>

            <div id="agent-sortable-list" class="divide-y divide-gray-50">
                <template x-for="(agent, index) in agents" :key="agent._uid || index">
                    <div>
                        {{-- Hidden inputs for form submission --}}
                        <input type="hidden" :name="'agents['+index+'][_uid]'"              :value="agent._uid">
                        <input type="hidden" :name="'agents['+index+'][name]'"              :value="agent.name">
                        <input type="hidden" :name="'agents['+index+'][type]'"              :value="agent.type">
                        <input type="hidden" :name="'agents['+index+'][role]'"              :value="agent.role">
                        <input type="hidden" :name="'agents['+index+'][system_prompt]'"     :value="agent.system_prompt">
                        <input type="hidden" :name="'agents['+index+'][strengths]'"         :value="agent.strengths">
                        <input type="hidden" :name="'agents['+index+'][limitations]'"       :value="agent.limitations">
                        <input type="hidden" :name="'agents['+index+'][input_description]'" :value="agent.input_description">
                        <input type="hidden" :name="'agents['+index+'][output_description]'" :value="agent.output_description">
                        <input type="hidden" :name="'agents['+index+'][prompt_template]'"   :value="agent.prompt_template">
                        <input type="hidden" :name="'agents['+index+'][model_reason]'"      :value="agent.model_reason">
                        <input type="hidden" :name="'agents['+index+'][order]'"             :value="agent.order">
                        <input type="hidden" :name="'agents['+index+'][is_verifier]'"       :value="agent.is_verifier ? '1' : '0'">
                        <input type="hidden" :name="'agents['+index+'][qa_threshold]'"      :value="agent.qa_threshold">
                        <input type="hidden" :name="'agents['+index+'][config][temperature]'" :value="agent.config ? agent.config.temperature : 0.7">
                        <input type="hidden" :name="'agents['+index+'][config][num_predict]'" :value="agent.config ? agent.config.num_predict : 1000">
                        <input type="hidden" :name="'agents['+index+'][config][qa][enabled]'" :value="agent.config && agent.config.qa && agent.config.qa.enabled ? '1' : '0'">
                        <input type="hidden" :name="'agents['+index+'][config][qa][verifier_agent_uid]'" :value="agent.config && agent.config.qa ? agent.config.qa.verifier_agent_uid : ''">
                        <input type="hidden" :name="'agents['+index+'][config][qa][verifier_agent_order]'" :value="selectedVerifierOrder(agent)">
                        <input type="hidden" :name="'agents['+index+'][config][qa][threshold]'" :value="agent.config && agent.config.qa ? agent.config.qa.threshold : 75">
                        <input type="hidden" :name="'agents['+index+'][config][qa][max_retries]'" :value="agent.config && agent.config.qa ? agent.config.qa.max_retries : 3">
                        <input type="hidden" :name="'agents['+index+'][config][qa][custom_prompt]'" :value="agent.config && agent.config.qa ? (agent.config.qa.custom_prompt || '') : ''">
                        <input type="hidden" :name="'agents['+index+'][model]'"             :value="agent.model">
                        <template x-if="agent.capabilities">
                            <template x-for="(cap, ci) in agent.capabilities" :key="ci">
                                <input type="hidden" :name="'agents['+index+'][capabilities]['+ci+']'" :value="cap">
                            </template>
                        </template>

                        {{-- Agent card row --}}
                        <div class="p-4 flex gap-3 items-start">
                            {{-- Drag handle --}}
                            <div class="drag-handle flex flex-col gap-1 cursor-grab pt-1 opacity-30 hover:opacity-100 transition shrink-0"
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
                                    <span x-show="stepQaEnabled(agent)" class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">QA след стъпката</span>
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
                                        'xIdExpr' => "'flow-type-ts-' + index",
                                    ])
                                </div>
                                <div class="col-span-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-medium text-gray-600">Роля / Описание (BG)</label>
                                        <button type="button" @click="generateField('role', agent)"
                                                :disabled="agent._generating_role || !agent.name"
                                                class="text-xs text-indigo-500 hover:text-indigo-700 flex items-center gap-1 disabled:opacity-40 transition">
                                            <span x-text="agent._generating_role ? '⏳' : '✨'"></span>
                                            <span x-text="agent._generating_role ? 'Генерира...' : 'AI'"></span>
                                        </button>
                                    </div>
                                    <textarea x-model="agent.role" rows="2"
                                              class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                                </div>
                                <div class="col-span-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-medium text-gray-600">System промпт (BG)</label>
                                        <button type="button" @click="generateField('system_prompt', agent)"
                                                :disabled="agent._generating_system_prompt || !agent.name"
                                                class="text-xs text-indigo-500 hover:text-indigo-700 flex items-center gap-1 disabled:opacity-40 transition">
                                            <span x-text="agent._generating_system_prompt ? '⏳' : '✨'"></span>
                                            <span x-text="agent._generating_system_prompt ? 'Генерира...' : 'AI'"></span>
                                        </button>
                                    </div>
                                    <textarea x-model="agent.system_prompt" rows="3"
                                              class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                              placeholder="Ти си специализиран агент за..."></textarea>
                                </div>
                                <div class="col-span-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-medium text-gray-600">Промпт шаблон (BG)</label>
                                        <button type="button" @click="generateField('prompt_template', agent)"
                                                :disabled="agent._generating_prompt_template || !agent.name"
                                                class="text-xs text-indigo-500 hover:text-indigo-700 flex items-center gap-1 disabled:opacity-40 transition">
                                            <span x-text="agent._generating_prompt_template ? '⏳' : '✨'"></span>
                                            <span x-text="agent._generating_prompt_template ? 'Генерира...' : 'AI'"></span>
                                        </button>
                                    </div>
                                    <textarea x-model="agent.prompt_template" rows="5"
                                              class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                              placeholder="Инструкции за агента с @{{placeholder}}-и..."></textarea>
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Модел</label>
                                    <select :id="'flow-model-ts-' + index"></select>
                                </div>
                                <div x-show="agent.is_verifier || agent.type === 'qa_verifier'" x-cloak>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">QA праг (%)</label>
                                    <select x-model.number="agent.qa_threshold"
                                            class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <template x-for="threshold in [0,5,10,15,20,25,30,35,40,45,50,55,60,65,70,75,80,85,90,95,100]" :key="threshold">
                                            <option :value="threshold" x-text="threshold + '%'"></option>
                                        </template>
                                    </select>
                                </div>
                                <div x-show="!agent.is_verifier && agent.type !== 'qa_verifier'" x-cloak class="col-span-2 border border-emerald-200 bg-emerald-50 rounded-lg p-3">
                                    <label class="flex items-center gap-2 text-sm font-semibold text-emerald-800">
                                        <input type="checkbox"
                                               x-model="agent.config.qa.enabled"
                                               @change="ensureStepQaDefaults(agent)"
                                               class="w-4 h-4 text-emerald-600 border-gray-300 rounded">
                                        QA след тази стъпка
                                    </label>
                                    <p class="text-xs text-emerald-700/80 mt-1">
                                        Ако QA не мине, този агент ще се изпълни отново до зададения лимит.
                                    </p>
                                    <div x-show="agent.config.qa.enabled" x-cloak class="grid grid-cols-3 gap-3 mt-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">QA агент</label>
                                            <select x-model="agent.config.qa.verifier_agent_uid"
                                                    class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                <option value="">Избери QA</option>
                                                <template x-for="verifier in verifierOptions(agent)" :key="verifier._uid">
                                                    <option :value="verifier._uid" x-text="verifier.name"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">Праг (%)</label>
                                            <select x-model.number="agent.config.qa.threshold"
                                                    class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                <template x-for="threshold in [0,5,10,15,20,25,30,35,40,45,50,55,60,65,70,75,80,85,90,95,100]" :key="threshold">
                                                    <option :value="threshold" x-text="threshold + '%'"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">Повторения</label>
                                            <input type="number"
                                                   min="0"
                                                   max="10"
                                                   x-model.number="agent.config.qa.max_retries"
                                                   class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                        </div>
                                    </div>
                                    <div x-show="agent.config.qa.enabled" x-cloak class="mt-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="block text-xs font-medium text-gray-600">
                                                Какво да проверява QA-то
                                                <span class="font-normal text-gray-400">(по избор — оставете празно за дефолтна проверка)</span>
                                            </label>
                                            <button type="button" @click="generateField('qa_custom_prompt', agent)"
                                                    :disabled="agent._generating_qa_custom_prompt || !agent.name"
                                                    class="text-xs text-indigo-500 hover:text-indigo-700 flex items-center gap-1 disabled:opacity-40 transition ml-2 shrink-0">
                                                <span x-text="agent._generating_qa_custom_prompt ? '⏳' : '✨'"></span>
                                                <span x-text="agent._generating_qa_custom_prompt ? 'Генерира...' : 'AI'"></span>
                                            </button>
                                        </div>
                                        <textarea x-model="agent.config.qa.custom_prompt" rows="4" maxlength="2000"
                                                  class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                                  placeholder="Провери дали резултатът съдържа..."></textarea>
                                    </div>
                                    <p x-show="agent.config.qa.enabled && verifierOptions(agent).length === 0"
                                       x-cloak
                                       class="text-xs text-red-600 mt-2">
                                        Добави QA verifier агент, за да включиш тази проверка.
                                    </p>
                                </div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="closeEdit"
                                        class="bg-white border border-gray-300 text-gray-600 text-sm px-3 py-1.5 rounded-lg hover:bg-gray-50 transition">
                                    Откажи
                                </button>
                                <button type="button" @click="saveEdit"
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
                <button type="button" @click="openAgentPicker"
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
                            {{-- Blank agent --}}
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

                            {{-- Company templates section --}}
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

                            {{-- System templates section --}}
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
                                <a href="{{ route('companies.agent-templates.index', $company) }}"
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
            <div x-show="agents.length > 0 && agents[agents.length-1].type !== 'qa_verifier' && agents.some(a => a.type === 'qa_verifier')"
                 x-cloak
                 class="mx-4 mb-4 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 text-xs text-amber-700">
                ⚠ QA verifier агентът не е последен в pipeline-а. Препоръчително е да е на последна позиция.
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

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
// Available model tags from server (for fallback logic)
const AVAILABLE_MODELS = @json($models->pluck('ollama_tag')->values());
const ALL_MODEL_TAGS   = @json($models->pluck('ollama_tag')->values());
const ALL_MODELS_DATA  = @json($models->toArray());
const STORAGE_KEY      = 'flowai_draft_{{ $company->id }}';

function flowCreator() {
    return {
        flowName: '',
        flowDescription: '',
        agents: [],
        isGenerating: false,
        errorMessage: '',
        isImproving: false,
        improvedDescription: '',
        showImprovePreview: false,
        editingIndex: null,
        _sortable: null,
        _modelTS: null,
        // ── Agent Picker ─────────────────────────────────────────
        showPicker: false,
        activePickerTab: 'all',
        pickerSearch: '',
        pickerLoading: false,
        pickerTemplates: { system: [], company: [] },
        pickerTabs: [
            { id: 'all',    label: 'Всички' },
            { id: 'mine',   label: '🏢 Моите агенти' },
            { id: 'system', label: '⚙ Системни агенти' },
        ],
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

        init() {
            // ── Restore from sessionStorage (after validation error redirect) ──
            const saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved) {
                try {
                    const draft = JSON.parse(saved);
                    this.flowName        = draft.name        || '';
                    this.flowDescription = draft.description || '';
                    this.agents          = (draft.agents || []).map(agent => this.normalizeAgent(agent));
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

            this.$nextTick(() => this.initSortable());
        },

        async improveDescription() {
            if (!this.flowDescription.trim()) return;
            this.isImproving = true;
            this.showImprovePreview = false;
            this.improvedDescription = '';

            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            try {
                const resp = await fetch('{{ route('flows.improve-description') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        description: this.flowDescription,
                        name: this.flowName,
                        company_id: {{ $company->id }},
                    }),
                });
                const data = await resp.json();
                if (resp.ok && data.improved) {
                    this.improvedDescription = data.improved;
                    this.showImprovePreview = true;
                } else {
                    alert(data.error || 'Грешка при подобряването. Опитай отново.');
                }
            } catch (e) {
                alert('Мрежова грешка: ' + e.message);
            } finally {
                this.isImproving = false;
            }
        },

        acceptImprovedDescription() {
            this.flowDescription = this.improvedDescription;
            this.showImprovePreview = false;
            this.improvedDescription = '';
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
                            // normalizeAgent uses agent.uid to set _uid, so preserve it before calling
                            return this.normalizeAgent(agent);
                        });
                        // Wire up verifier UIDs from AI generation:
                        // The qa_verifier has uid="qa_main" so _uid is "qa_main".
                        // Non-verifiers reference verifier_agent_uid="qa_main" — replace with actual _uid.
                        const qaMainAgent = this.agents.find(a => a.uid === 'qa_main' || a._uid === 'qa_main' || a.is_verifier);
                        if (qaMainAgent) {
                            this.agents.forEach(a => {
                                if (!a.is_verifier && a.config && a.config.qa && a.config.qa.verifier_agent_uid === 'qa_main') {
                                    a.config.qa.verifier_agent_uid = qaMainAgent._uid;
                                }
                            });
                        }
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

        buildModelOptions(agentType) {
            const recommended = ALL_MODELS_DATA.filter(m => (m.is_default_for || []).includes(agentType));
            const others      = ALL_MODELS_DATA.filter(m => !(m.is_default_for || []).includes(agentType));
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

        initFlowModelSelect(index) {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            const agent = this.agents[index];
            if (!agent) return;
            const sel = document.getElementById('flow-model-ts-' + index);
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

        normalizeAgent(agent) {
            agent._uid = agent.uid || agent._uid || ((typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : Date.now() + '-' + Math.random());
            agent.is_verifier = Boolean(agent.is_verifier || agent.type === 'qa_verifier');
            if (agent.is_verifier && !agent.qa_threshold) {
                agent.qa_threshold = 75;
            }
            agent.config = agent.config && typeof agent.config === 'object' ? agent.config : {};
            agent.config.qa = agent.config.qa && typeof agent.config.qa === 'object'
                ? agent.config.qa
                : { enabled: false };
            agent.config.qa.enabled = Boolean(agent.config.qa.enabled);
            agent.config.qa.verifier_agent_uid = agent.config.qa.verifier_agent_uid || '';
            agent.config.qa.threshold = Number(agent.config.qa.threshold ?? 75);
            agent.config.qa.max_retries = Number(agent.config.qa.max_retries ?? 3);
            agent.config.qa.custom_prompt = agent.config.qa.custom_prompt || '';

            if (agent.is_verifier) {
                agent.config.qa = { enabled: false, verifier_agent_uid: '', threshold: 75, max_retries: 3, custom_prompt: '' };
            }

            agent._generating_role             = false;
            agent._generating_system_prompt    = false;
            agent._generating_prompt_template  = false;
            agent._generating_qa_custom_prompt = false;

            return agent;
        },

        stepQaEnabled(agent) {
            return Boolean(agent?.config?.qa?.enabled);
        },

        verifierOptions(agent) {
            return this.agents.filter(candidate =>
                candidate._uid !== agent._uid
                && (candidate.is_verifier || candidate.type === 'qa_verifier')
            );
        },

        ensureStepQaDefaults(agent) {
            agent.config = agent.config && typeof agent.config === 'object' ? agent.config : {};
            agent.config.qa = agent.config.qa && typeof agent.config.qa === 'object' ? agent.config.qa : {};
            agent.config.qa.enabled = Boolean(agent.config.qa.enabled);

            if (!agent.config.qa.enabled) return;

            const firstVerifier = this.verifierOptions(agent)[0];
            if (!agent.config.qa.verifier_agent_uid && firstVerifier) {
                agent.config.qa.verifier_agent_uid = firstVerifier._uid;
            }
            agent.config.qa.threshold = Number(agent.config.qa.threshold ?? 75);
            agent.config.qa.max_retries = Number(agent.config.qa.max_retries ?? 3);
            agent.config.qa.custom_prompt = agent.config.qa.custom_prompt || '';
        },

        selectedVerifierOrder(agent) {
            const uid = agent?.config?.qa?.verifier_agent_uid;
            if (!uid) return '';

            const verifier = this.agents.find(candidate => candidate._uid === uid);
            return verifier ? verifier.order : '';
        },

        openEdit(index) {
            this.editingIndex = index;
            this.$nextTick(() => {
                initAgentTypeSelect('flow-type-ts-' + index, this.agents[index].type, v => {
                    this.agents[index].type = v;
                    this.agents[index].is_verifier = v === 'qa_verifier';
                    if (this.agents[index].is_verifier && !this.agents[index].qa_threshold) {
                        this.agents[index].qa_threshold = 75;
                    }
                    if (this.agents[index].is_verifier) {
                        this.agents[index].config.qa = { enabled: false, verifier_agent_uid: '', threshold: 75, max_retries: 3 };
                    }
                    this.initFlowModelSelect(index);
                });
                this.initFlowModelSelect(index);
            });
        },

        closeEdit() {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            this.editingIndex = null;
        },

        async generateField(field, agent) {
            const key = '_generating_' + field.replace('.', '_');
            agent[key] = true;
            try {
                const resp = await fetch('/ai/generate-agent-field', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        field,
                        agent_name:       agent.name || '',
                        agent_type:       agent.type || '',
                        flow_description: this.flowDescription || '',
                        role:             agent.role || '',
                        system_prompt:    agent.system_prompt || '',
                        prompt_template:  agent.prompt_template || '',
                    }),
                });
                const res = await resp.json();
                if (!resp.ok) {
                    this.errorMessage = res.error || 'Грешка при AI генерация. Провери дали Ollama работи.';
                } else if (res && res.generated) {
                    if (field === 'qa_custom_prompt') {
                        agent.config.qa.custom_prompt = res.generated;
                    } else {
                        agent[field] = res.generated;
                    }
                }
            } catch (e) {
                console.error('generateField error', e);
                this.errorMessage = 'Мрежова грешка при AI генерация.';
            } finally {
                agent[key] = false;
            }
        },

        saveEdit() {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            this.renumberAgents();
            this.editingIndex = null;
        },

        deleteAgent(index) {
            if (confirm('Изтрий агент "' + this.agents[index].name + '"?')) {
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

        async openAgentPicker() {
            this.showPicker = true;
            this.activePickerTab = 'all';
            this.pickerSearch = '';

            if (this.pickerTemplates.system.length > 0 || this.pickerTemplates.company.length > 0) {
                return; // already loaded
            }

            this.pickerLoading = true;
            try {
                const resp = await fetch(`{{ route('agent-templates.picker') }}?company_id={{ $company->id }}`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (resp.ok) {
                    this.pickerTemplates = await resp.json();
                } else {
                    console.error('Failed to load templates, status:', resp.status);
                }
            } catch (e) {
                console.error('Failed to load templates', e);
            } finally {
                this.pickerLoading = false;
            }
        },

        selectTemplate(tpl) {
            const defaults = {
                _uid: (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : Date.now() + '-' + Math.random(),
                name: 'Нов агент',
                type: 'content_bg',
                role: '',
                system_prompt: '',
                prompt_template: '',
                model: AVAILABLE_MODELS[0] || ALL_MODEL_TAGS[0] || '',
                model_reason: '',
                order: this.agents.length + 1,
                is_verifier: false,
                qa_threshold: null,
                capabilities: [],
                strengths: '',
                limitations: '',
                input_description: '',
                output_description: '',
                config: { temperature: 0.7, num_predict: 1000, qa: { enabled: false, verifier_agent_uid: '', threshold: 75, max_retries: 3 } },
            };

            if (tpl) {
                Object.assign(defaults, {
                    name:               tpl.name              || defaults.name,
                    type:               tpl.type              || defaults.type,
                    role:               tpl.role              || '',
                    system_prompt:      tpl.system_prompt     || '',
                    prompt_template:    tpl.prompt_template   || '',
                    model:              this._resolveModel(tpl.model),
                    is_verifier:        Boolean(tpl.is_verifier || tpl.type === 'qa_verifier'),
                    qa_threshold:       (tpl.is_verifier || tpl.type === 'qa_verifier') ? (tpl.qa_threshold || 75) : null,
                    capabilities:       tpl.capabilities      || [],
                    strengths:          tpl.strengths         || '',
                    limitations:        tpl.limitations       || '',
                    input_description:  tpl.input_description || '',
                    output_description: tpl.output_description|| '',
                    config:             tpl.config            || defaults.config,
                });
            }

            this.agents.push(this.normalizeAgent(defaults));
            this.renumberAgents();
            this.editingIndex = this.agents.length - 1;
            this.showPicker = false;
            this.$nextTick(() => this.initSortable());
        },

        _resolveModel(suggestedModel) {
            if (suggestedModel && ALL_MODEL_TAGS.includes(suggestedModel)) return suggestedModel;
            return AVAILABLE_MODELS[0] || ALL_MODEL_TAGS[0] || '';
        },

        moveAgent(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= this.agents.length) return;
            const tmp = this.agents[index];
            this.agents[index] = this.agents[newIndex];
            this.agents[newIndex] = tmp;
            this.renumberAgents();
            if (this.editingIndex === index) this.editingIndex = newIndex;
        },

        renumberAgents() {
            this.agents.forEach((a, i) => { a.order = i + 1; });
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
                    this.$nextTick(() => this.initSortable());
                },
            });
        },
    };
}
</script>
@endsection
