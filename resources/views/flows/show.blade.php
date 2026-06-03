@extends('layouts.app')

@section('title', $flow->name)

@php
$triggeredByLabel = ['manual' => '▶ Ръчно', 'scheduler' => '⏰ Планиран'];
$langFlag = ['bg' => '🇧🇬', 'en' => '🇬🇧', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸', 'ru' => '🇷🇺'];
$qaThresholdOptions = range(0, 100, 5);
@endphp

{{-- Clear the create-form draft for this company on successful save --}}
<script>sessionStorage.removeItem('flowai_draft_{{ $flow->company_id }}');</script>

@section('content')
{{-- Header --}}
<div class="mb-6">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('companies.show', $flow->company) }}" class="text-indigo-600 hover:underline text-sm inline-flex items-center gap-1">
                ← {{ $flow->company->name }}
            </a>
            <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3 flex-wrap">
                {{ $flow->name }}
                @include('partials.status-badge', ['status' => $flow->status, 'class' => 'text-sm px-3'])
            </h1>
        </div>
        <div class="flex items-start gap-2 shrink-0">
            <a href="{{ route('flows.edit', $flow) }}"
               class="inline-flex items-center justify-center bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                ✏ Редактирай
            </a>
            <form action="{{ route('flow-runs.store', $flow) }}" method="POST" class="flex items-start gap-2">
                @csrf
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-bold transition flex items-center gap-2">
                    ▶ Стартирай
                </button>
            </form>
        </div>
    </div>
    <p class="text-gray-500 mt-1">{!! nl2br(e($flow->description)) !!}</p>
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

{{-- Webhook / n8n Integration Panel --}}
<div class="bg-white rounded-xl border border-gray-200 mb-6 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
            <span>🔗</span> Webhook / n8n интеграция
        </h2>
        @if($flow->webhook_secret)
            <span class="inline-flex items-center gap-1.5 text-xs bg-green-100 text-green-700 px-2.5 py-1 rounded-full font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span> Активен
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 text-xs bg-gray-100 text-gray-500 px-2.5 py-1 rounded-full font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-gray-400 inline-block"></span> Неактивен
            </span>
        @endif
    </div>

    <div class="px-6 py-5">
        @if($flow->webhook_secret)
            <p class="text-sm text-gray-500 mb-4">
                Изпрати <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">POST</code> заявка към URL-а по-долу, за да стартираш flow-а от n8n, Zapier, Make или друга платформа. Добави JSON тяло с произволни данни — те ще бъдат достъпни като <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">&#123;&#123;webhook_payload&#125;&#125;</code> в промптовете на агентите.
            </p>

            {{-- Webhook URL --}}
            @php
                $webhookUrl = url('/api/webhook/flows/' . $flow->id . '/run') . '?token=' . $flow->webhook_secret;
            @endphp
            <div class="flex items-center gap-2 mb-4">
                <input type="text"
                       id="webhookUrlInput"
                       value="{{ $webhookUrl }}"
                       readonly
                       class="flex-1 font-mono text-xs bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-gray-700 select-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-300"
                       onclick="this.select()" />
                <button onclick="copyWebhookUrl()"
                        class="shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium px-3 py-2.5 rounded-lg transition">
                    Копирай
                </button>
            </div>

            {{-- n8n example hint --}}
            <details class="mb-4">
                <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700 select-none">
                    Как да настроиш в n8n?
                </summary>
                <div class="mt-3 bg-gray-50 rounded-lg p-4 text-xs text-gray-600 space-y-2 border border-gray-100">
                    <p><strong>Вариант 1 — n8n изпраща към FlowAI (trigger):</strong></p>
                    <ol class="list-decimal list-inside space-y-1 ml-1">
                        <li>В n8n добави нов нод <strong>HTTP Request</strong></li>
                        <li>Метод: <code class="bg-white px-1 rounded border border-gray-200">POST</code> | URL: горният webhook адрес</li>
                        <li>Body: JSON с данните, които искаш да подадеш на агентите</li>
                        <li>Свържи нода след trigger (Gmail, Google Drive, Schedule и др.)</li>
                    </ol>
                    <p class="pt-1"><strong>Вариант 2 — FlowAI изпраща към n8n (action):</strong></p>
                    <ol class="list-decimal list-inside space-y-1 ml-1">
                        <li>В n8n добави нод <strong>Webhook</strong> и копирай неговия URL</li>
                        <li>В FlowAI добави агент тип <strong>webhook_sender</strong> като последен агент</li>
                        <li>В конфигурацията на агента постави URL-а на n8n webhook</li>
                        <li>n8n получава резултата и го разпраща: Gmail, Sheets, Twitter и т.н.</li>
                    </ol>
                </div>
            </details>

            {{-- Revoke button --}}
            <form action="{{ route('flows.webhook.revoke', $flow) }}" method="POST"
                  onsubmit="return confirm('Деактивирай webhook? Всички n8n connections с текущия URL ще спрат да работят.')">
                @csrf
                <button type="submit"
                        class="text-xs text-red-500 hover:text-red-700 underline transition">
                    Деактивирай webhook URL
                </button>
            </form>
        @else
            <p class="text-sm text-gray-500 mb-4">
                Генерирай webhook URL, за да можеш да стартираш този flow автоматично от <strong>n8n</strong>, Zapier, Make или друга платформа — при нов email, файл в Google Drive, Facebook коментар и т.н.
            </p>
            <form action="{{ route('flows.webhook.generate', $flow) }}" method="POST">
                @csrf
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
                    🔗 Генерирай Webhook URL
                </button>
            </form>
        @endif
    </div>
</div>

<script>
function copyWebhookUrl() {
    const input = document.getElementById('webhookUrlInput');
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = event.target;
        const orig = btn.textContent;
        btn.textContent = 'Копирано ✓';
        btn.classList.replace('bg-indigo-600', 'bg-green-600');
        btn.classList.replace('hover:bg-indigo-700', 'hover:bg-green-700');
        setTimeout(() => {
            btn.textContent = orig;
            btn.classList.replace('bg-green-600', 'bg-indigo-600');
            btn.classList.replace('hover:bg-green-700', 'hover:bg-indigo-700');
        }, 2000);
    });
}
</script>

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
                            <span class="text-lg leading-none" x-text="agent.icon || '🤖'"></span>
                            <span class="font-semibold text-gray-900 text-sm" x-text="agent.name"></span>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-mono" x-text="agent.type"></span>
                            <span x-show="agent.is_verifier" class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">QA verifier</span>
                            <span x-show="stepQaEnabled(agent)" class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">QA след стъпката</span>
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

                    {{-- Tab switcher --}}
                    <div class="flex border-b border-indigo-200 mb-4 -mx-4 px-4">
                        <button type="button" @click="editTab = 'agent'"
                                :class="editTab === 'agent' ? 'border-b-2 border-indigo-600 text-indigo-700 font-semibold' : 'text-gray-500 hover:text-gray-700'"
                                class="px-4 py-2 text-sm transition">Агент</button>
                        <button type="button" x-show="!agent.is_verifier && agent.type !== 'qa_verifier'" @click="editTab = 'qa'"
                                :class="editTab === 'qa' ? 'border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-500 hover:text-gray-700'"
                                class="px-4 py-2 text-sm transition">QA Верификация</button>
                    </div>

                    {{-- Tab: Агент --}}
                    <div x-show="editTab === 'agent'" x-cloak>
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
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-medium text-gray-600">Роля / Описание</label>
                                    <button type="button" @click="generateField('role', agent)"
                                            :disabled="agent._generating_role || !agent.name"
                                            class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                                        <span x-text="agent._generating_role ? '⏳' : '✨'"></span>
                                        <span x-text="agent._generating_role ? 'Генерира...' : 'Генерирай с AI'"></span>
                                    </button>
                                </div>
                                <textarea x-model="agent.role" rows="2"
                                          class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                            </div>
                            <div class="col-span-2">
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-medium text-gray-600">System промпт</label>
                                    <button type="button" @click="generateField('system_prompt', agent)"
                                            :disabled="agent._generating_system_prompt || !agent.name"
                                            class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                                        <span x-text="agent._generating_system_prompt ? '⏳' : '✨'"></span>
                                        <span x-text="agent._generating_system_prompt ? 'Генерира...' : 'Генерирай с AI'"></span>
                                    </button>
                                </div>
                                <textarea x-model="agent.system_prompt" :id="'sp-show-' + index" rows="3"
                                          class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                          placeholder="Ти си специализиран агент за..."></textarea>
                                @include('partials.token-helper', [
                                    'textareaId'    => null,
                                    'xTextareaId'   => "'sp-show-' + index",
                                    'agents'        => null,
                                    'xAgents'       => 'agents',
                                    'xCurrentOrder' => 'agent.order',
                                ])
                            </div>
                            <div class="col-span-2">
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-medium text-gray-600">Промпт шаблон</label>
                                    <button type="button" @click="generateField('prompt_template', agent)"
                                            :disabled="agent._generating_prompt_template || !agent.name"
                                            class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                                        <span x-text="agent._generating_prompt_template ? '⏳' : '✨'"></span>
                                        <span x-text="agent._generating_prompt_template ? 'Генерира...' : 'Генерирай с AI'"></span>
                                    </button>
                                </div>
                                <textarea x-model="agent.prompt_template" :id="'pt-show-' + index" rows="5"
                                          class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                          placeholder="Инструкции за агента с @{{placeholder}}-и..."></textarea>
                                @include('partials.token-helper', [
                                    'textareaId'    => null,
                                    'xTextareaId'   => "'pt-show-' + index",
                                    'agents'        => null,
                                    'xAgents'       => 'agents',
                                    'xCurrentOrder' => 'agent.order',
                                ])
                            </div>
                            <div :class="agent.is_verifier || agent.type === 'qa_verifier' ? 'col-span-1' : 'col-span-2'">
                                <label class="block text-xs font-medium text-gray-600 mb-1">Модел</label>
                                <select :id="'show-model-ts-' + index"></select>
                            </div>
                            <div x-show="agent.is_verifier || agent.type === 'qa_verifier'" x-cloak class="col-span-1">
                                <label class="block text-xs font-medium text-gray-600 mb-1">QA праг (%)</label>
                                <select x-model.number="agent.qa_threshold"
                                        class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    @foreach($qaThresholdOptions as $threshold)
                                        <option value="{{ $threshold }}">{{ $threshold }}%</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- Tab: QA Верификация --}}
                    <div x-show="editTab === 'qa'">
                        {{-- Toggle row --}}
                        <div class="flex items-center justify-between mb-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg">
                            <div>
                                <p class="text-sm font-semibold text-emerald-800">QA след тази стъпка</p>
                                <p class="text-xs text-emerald-700/80">Ако QA не мине, агентът ще се изпълни отново до зададения лимит.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" x-model="agent.config.qa.enabled" @change="ensureStepQaDefaults(agent)" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-emerald-600 after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                            </label>
                        </div>

                        {{-- Config fields (shown when enabled) --}}
                        <div x-show="agent.config.qa.enabled" x-cloak class="grid grid-cols-3 gap-3 mb-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">QA Агент</label>
                                <select x-model.number="agent.config.qa.verifier_agent_id"
                                        class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    <option value="">Избери QA</option>
                                    <template x-for="verifier in verifierOptions(agent)" :key="verifier.id">
                                        <option :value="verifier.id" x-text="verifier.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Прагова стойност</label>
                                <select x-model.number="agent.config.qa.threshold"
                                        class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    @foreach($qaThresholdOptions as $threshold)
                                        <option value="{{ $threshold }}">{{ $threshold }}%</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Макс. повторения</label>
                                <input type="number" min="0" max="10" x-model.number="agent.config.qa.max_retries"
                                       class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>

                        {{-- Custom prompt --}}
                        <div x-show="agent.config.qa.enabled" x-cloak>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-medium text-gray-600">
                                    Какво да проверява QA-то
                                    <span class="font-normal text-gray-400">(по избор — оставете празно за дефолтна проверка)</span>
                                </label>
                                <button type="button" @click="generateField('qa_custom_prompt', agent)"
                                        :disabled="agent._generating_qa_custom_prompt || !agent.name"
                                        class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition ml-2 shrink-0">
                                    <span x-text="agent._generating_qa_custom_prompt ? '⏳' : '✨'"></span>
                                    <span x-text="agent._generating_qa_custom_prompt ? 'Генерира...' : 'Генерирай с AI'"></span>
                                </button>
                            </div>
                            <textarea x-model="agent.config.qa.custom_prompt" rows="4" maxlength="2000"
                                      class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                      placeholder="Провери дали резултатът съдържа..."></textarea>
                            <p class="text-xs text-gray-400 mt-1">Този промпт се изпраща на QA агента заедно с изхода на стъпката.</p>
                        </div>

                        {{-- No verifier warning --}}
                        <p x-show="agent.config.qa.enabled && verifierOptions(agent).length === 0" x-cloak
                           class="text-xs text-red-600 mt-2">
                            Добави активен QA verifier агент, за да включиш тази проверка.
                        </p>
                    </div>

                    <div class="flex justify-end gap-2 mt-4">
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


</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
const FLOW_ID          = {{ $flow->id }};
const FLOW_DESCRIPTION = @json($flow->description ?? '');
const AGENTS_DATA  = @json($agentsJson);
const MODELS_DATA  = @json($models);
const QA_THRESHOLD_OPTIONS = @json($qaThresholdOptions);
const CSRF_TOKEN   = document.querySelector('meta[name="csrf-token"]').content;

function flowAgentManager() {
    return {
        agents: [],
        models: [],
        editingIndex: null,
        editTab: 'agent',
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
            this.agents = AGENTS_DATA.map(a => this.normalizeAgent({ ...a }));
            this.models = MODELS_DATA;
            this.$nextTick(() => this.initSortable());
        },

        normalizeAgent(agent) {
            agent.icon = agent.icon || '🤖';
            agent.config = agent.config && typeof agent.config === 'object' ? agent.config : {};
            agent.config.qa = agent.config.qa && typeof agent.config.qa === 'object'
                ? agent.config.qa
                : { enabled: false };
            agent.config.qa.enabled = Boolean(agent.config.qa.enabled);
            agent.config.qa.verifier_agent_id = agent.config.qa.verifier_agent_id ? Number(agent.config.qa.verifier_agent_id) : null;
            agent.config.qa.threshold = Number(agent.config.qa.threshold ?? 60);
            agent.config.qa.max_retries = Number(agent.config.qa.max_retries ?? 3);
            agent.config.qa.custom_prompt = agent.config.qa.custom_prompt || '';

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
                candidate.id !== agent.id
                && candidate.is_active
                && (candidate.is_verifier || candidate.type === 'qa_verifier')
            );
        },

        ensureStepQaDefaults(agent) {
            agent.config = agent.config && typeof agent.config === 'object' ? agent.config : {};
            agent.config.qa = agent.config.qa && typeof agent.config.qa === 'object' ? agent.config.qa : {};
            agent.config.qa.enabled = Boolean(agent.config.qa.enabled);

            if (!agent.config.qa.enabled) return;

            const firstVerifier = this.verifierOptions(agent)[0];
            if (!agent.config.qa.verifier_agent_id && firstVerifier) {
                agent.config.qa.verifier_agent_id = firstVerifier.id;
            }
            agent.config.qa.threshold = Number(agent.config.qa.threshold ?? 60);
            agent.config.qa.max_retries = Number(agent.config.qa.max_retries ?? 3);
            agent.config.qa.custom_prompt = agent.config.qa.custom_prompt || '';
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
            this.editTab = 'agent';
            this.$nextTick(() => {
                initAgentTypeSelect('show-type-ts-' + index, this.agents[index].type, v => {
                    this.agents[index].type = v;
                    this.agents[index].is_verifier = v === 'qa_verifier';
                    if (this.agents[index].is_verifier && (this.agents[index].qa_threshold === null || this.agents[index].qa_threshold === undefined || this.agents[index].qa_threshold === '')) {
                        this.agents[index].qa_threshold = 60;
                    }
                    if (this.agents[index].is_verifier) {
                        this.agents[index].config.qa = { enabled: false };
                        this.editTab = 'agent';
                    }
                    this.initShowModelSelect(index);
                });
                this.initShowModelSelect(index);
            });
        },

        closeEdit() {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            this.editingIndex = null;
            this.editTab = 'agent';
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
                icon:  '🤖',
                model: firstModel ? firstModel.ollama_tag : '',
                type:  'content_bg',
            };
            this.saving = true;
            const result = await this.ajax('POST', `/flows/${FLOW_ID}/agents`, data);
            this.saving = false;
            if (result) {
                this.agents.push(this.normalizeAgent(result.agent));
                const newIndex = this.agents.length - 1;
                this.$nextTick(() => {
                    this.initSortable();
                    this.openEdit(newIndex);
                });
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
                icon:             tpl ? (tpl.icon            || '🤖')           : '🤖',
                model:            resolvedModel,
                type:             tpl ? (tpl.type            || 'content_bg')  : 'content_bg',
                role:             tpl ? (tpl.role            || '')             : '',
                system_prompt:    tpl ? (tpl.system_prompt   || '')             : '',
                prompt_template:  tpl ? (tpl.prompt_template || '')             : '',
                is_verifier:      tpl ? Boolean(tpl.is_verifier || tpl.type === 'qa_verifier') : false,
                qa_threshold:     tpl && (tpl.is_verifier || tpl.type === 'qa_verifier') ? (tpl.qa_threshold ?? 60) : null,
                config:           tpl && tpl.config ? tpl.config : { temperature: 0.7, num_predict: 1000, qa: { enabled: false } },
            };

            this.showPicker = false;
            this.saving = true;
            const result = await this.ajax('POST', `/flows/${FLOW_ID}/agents`, data);
            this.saving = false;
            if (result) {
                this.agents.push(this.normalizeAgent(result.agent));
                const newIndex = this.agents.length - 1;
                this.$nextTick(() => {
                    this.initSortable();
                    this.openEdit(newIndex);
                });
            }
        },

        async saveEdit(agent) {
            if (this._modelTS) { this._modelTS.destroy(); this._modelTS = null; }
            this.saving = true;
            const result = await this.ajax('PUT', `/flows/${FLOW_ID}/agents/${agent.id}`, {
                name:             agent.name,
                icon:             agent.icon || '🤖',
                role:             agent.role || '',
                type:             agent.type,
                system_prompt:    agent.system_prompt || '',
                prompt_template:  agent.prompt_template || '',
                model:            agent.model,
                is_verifier:      agent.is_verifier || agent.type === 'qa_verifier',
                qa_threshold:     agent.qa_threshold,
                config:           agent.config,
            });
            this.saving = false;
            if (result) {
                const idx = this.agents.findIndex(a => a.id === agent.id);
                if (idx !== -1) this.agents[idx] = this.normalizeAgent({ ...this.agents[idx], ...result.agent });
                this.editingIndex = null;
                this.editTab = 'agent';
            }
        },

        async generateField(field, agent) {
            const key = '_generating_' + field.replace('.', '_');
            agent[key] = true;
            try {
                const res = await this.ajax('POST', '/ai/generate-agent-field', {
                    field,
                    agent_name:       agent.name || '',
                    agent_type:       agent.type || '',
                    flow_description: FLOW_DESCRIPTION,
                    role:             agent.role || '',
                    system_prompt:    agent.system_prompt || '',
                    prompt_template:  agent.prompt_template || '',
                });
                if (res && res.generated) {
                    if (field === 'qa_custom_prompt') {
                        agent.config.qa.custom_prompt = res.generated;
                    } else {
                        agent[field] = res.generated;
                    }
                }
            } finally {
                agent[key] = false;
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
