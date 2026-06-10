@extends('layouts.app')

@section('title', 'A/B сравнение на плана — ' . $flow->name)

@section('content')
<div class="max-w-7xl mx-auto" x-data="planAb()">
    <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
        <div>
            <div class="text-sm text-gray-400 mb-1">
                <a href="{{ route('companies.show', $flow->company) }}" class="hover:text-indigo-600">{{ $flow->company->name }}</a>
                <span class="mx-1">/</span>
                <a href="{{ route('flows.show', $flow) }}" class="hover:text-indigo-600">{{ $flow->name }}</a>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">⚖️ A/B сравнение на плана</h1>
            <p class="text-sm text-gray-500 mt-1">
                Едно и също описание → план от всеки провайдър (и хибрид по фази). Прегледай агентите и запази най-добрите като шаблони.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('flows.builder', $flow) }}" class="text-sm text-gray-500 hover:text-indigo-600">← Към builder-а</a>
            <button @click="start()" :disabled="anyRunning()"
                    class="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
                <span x-show="!anyRunning()">Генерирай всички</span>
                <span x-show="anyRunning()" class="animate-pulse">Планиране…</span>
            </button>
        </div>
    </div>

    @if (count($available) < count($availability))
        <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            @if (! in_array('ollama', $available)) Ollama сървърът не отговаря — локалната карта е недостъпна. @endif
            @if (! in_array('openai', $available)) Липсва <code>OPENAI_API_KEY</code> — OpenAI картата е недостъпна. @endif
            @if (! in_array('anthropic', $available)) Липсва <code>ANTHROPIC_API_KEY</code> — Anthropic картата е недостъпна. @endif
            @if (! in_array('deepseek', $available)) Липсва <code>DEEPSEEK_API_KEY</code> — DeepSeek картата е недостъпна. @endif
            @if (! in_array('gemini', $available)) Липсва <code>GEMINI_API_KEY</code> — Gemini картата е недостъпна. @endif
            @if (! in_array('xai', $available)) Липсва <code>XAI_API_KEY</code> — Grok картата е недостъпна. @endif
            @if (! in_array('qwen', $available)) Липсва <code>QWEN_API_KEY</code> — Qwen картата е недостъпна. @endif
        </div>
    @endif

    <template x-if="error">
        <div class="mb-6 rounded-lg bg-red-50 border border-red-100 px-4 py-3 text-sm text-red-700" x-text="error"></div>
    </template>

    {{-- 6 генератора: 2 реда × 3 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <template x-for="label in labels" :key="label">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden flex flex-col"
                 :class="!isAvailable(label) ? 'opacity-60' : ''">
                <div class="px-4 py-3.5 border-b border-gray-100 flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-gray-900 truncate" x-text="meta[label].title"></div>
                        <template x-if="label === 'hybrid'">
                            <div class="text-xs text-gray-400 truncate" x-text="result(label).model || meta[label].model"></div>
                        </template>
                        <template x-if="label !== 'hybrid'">
                            <div class="mt-1">
                                {{-- :selected — опциите от x-for се щамповат след x-model bind-а;
                                     без него селектът визуално пада на първата опция. --}}
                                <select x-model="cardModel[label]" :disabled="running[label]"
                                        class="w-full border border-gray-300 rounded-lg px-2 py-1 text-xs bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50">
                                    <template x-for="m in picker.singleModelOptions(label, cardModel[label])" :key="m.value">
                                        <option :value="m.value" :selected="cardModel[label] === m.value"
                                                :title="m.title || ''" x-text="m.label"></option>
                                    </template>
                                </select>
                                <p class="text-[11px] text-gray-400 mt-1 truncate"
                                   :title="picker.singleModelHint(label, cardModel[label])"
                                   x-text="picker.singleModelHint(label, cardModel[label])"></p>
                            </div>
                        </template>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <template x-if="result(label).status === 'running'">
                            <span class="text-xs text-indigo-500 animate-pulse">планира…</span>
                        </template>
                        <template x-if="result(label).status === 'failed'">
                            <span class="text-xs text-red-500">✗ провал</span>
                        </template>
                        <template x-if="result(label).status === 'skipped'">
                            <span class="text-xs text-gray-400">пропуснат</span>
                        </template>
                        <button x-show="label === 'hybrid'" type="button" @click="hybridCfgOpen = true"
                                class="text-xs bg-white border border-gray-300 hover:border-violet-500 text-gray-600 hover:text-violet-700 font-medium px-2.5 py-1 rounded-lg transition"
                                title="Избери провайдър/модел за всяка фаза">
                            ⚙ Конфигурирай
                        </button>
                        <button @click="start(label)"
                                :disabled="running[label] || !isAvailable(label)"
                                class="text-xs bg-white border border-indigo-300 hover:border-indigo-500 text-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed font-medium px-2.5 py-1 rounded-lg transition"
                                :title="'Генерирай план с ' + meta[label].title">
                            <span x-show="!running[label]" x-text="result(label).status === 'completed' ? '↻ Отново' : '▶ Генерирай'"></span>
                            <span x-show="running[label]" class="animate-pulse">…</span>
                        </button>
                    </div>
                </div>

                {{-- Stats row --}}
                <template x-if="result(label).status === 'completed'">
                    <div class="px-4 py-2 bg-gray-50/70 border-b border-gray-100 text-xs text-gray-500 tabular-nums flex items-center gap-3">
                        <span class="font-semibold text-gray-700" x-text="result(label).agents.length + ' агента'"></span>
                        <span x-text="(result(label).duration_ms/1000).toFixed(1) + 's'"></span>
                        <span class="font-medium"
                              :class="(result(label).cost_usd > 0) ? 'text-amber-600' : 'text-green-600'"
                              x-text="(result(label).cost_usd > 0) ? ('$' + result(label).cost_usd) : 'безплатно'"></span>
                        <span class="ml-auto text-violet-600" x-show="uniqueTypes(label).length"
                              :title="'Типове агенти само в този план: ' + uniqueTypes(label).join(', ')"
                              x-text="'✦ ' + uniqueTypes(label).length + ' уникални'"></span>
                    </div>
                </template>

                <div class="p-4 flex-1 flex flex-col">
                    <template x-if="result(label).error">
                        <div class="text-sm text-red-600 mb-3" x-text="result(label).error"></div>
                    </template>

                    <template x-if="result(label).status === 'completed'">
                        <div class="flex-1 flex flex-col gap-2">
                            {{-- Компактен поглед: началните агенти по име --}}
                            <div class="text-xs text-gray-500 leading-relaxed flex-1">
                                <template x-for="(agent, i) in result(label).agents.slice(0, 4)" :key="label + '-a' + i">
                                    <div class="truncate">
                                        <span class="text-gray-300" x-text="(i + 1) + '.'"></span>
                                        <span class="text-gray-700 font-medium" x-text="agent.name"></span>
                                        <span class="text-gray-400" x-text="'· ' + agent.type"></span>
                                    </div>
                                </template>
                                <div x-show="result(label).agents.length > 4" class="text-gray-300 mt-0.5"
                                     x-text="'… и още ' + (result(label).agents.length - 4)"></div>
                            </div>

                            <button @click="dag.show(result(label).agents)" type="button"
                                    class="w-full bg-white border border-indigo-300 hover:border-indigo-500 text-indigo-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                                👁 Виж агентите (като в билдъра)
                            </button>
                            <button @click="openSave(label)" :disabled="saving" type="button"
                                    class="w-full bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
                                💾 Запази като шаблон
                            </button>
                            <div x-show="savedAs[label]" class="text-xs text-green-700 bg-green-50 border border-green-100 rounded-lg px-3 py-1.5 text-center"
                                 x-text="'✓ запазен като шаблон „' + savedAs[label] + '“'"></div>
                        </div>
                    </template>

                    <template x-if="!result(label).status && !running[label]">
                        <div class="text-sm text-gray-400 text-center py-10">
                            <span x-show="isAvailable(label) && label !== 'hybrid'">Натисни „▶ Генерирай" за план от този provider.</span>
                            <span x-show="isAvailable(label) && label === 'hybrid'">Конфигурирай фазите (⚙) и натисни „▶ Генерирай".</span>
                            <span x-show="!isAvailable(label)">Недостъпен — виж бележката горе.</span>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <template x-if="completedLabels().length >= 2">
        <div class="mt-6 bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-sm">
            <div class="font-semibold text-gray-900 mb-2">Разлики (типове агенти, които има само един план)</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <template x-for="label in completedLabels()" :key="'diff-' + label">
                    <div>
                        <span class="text-gray-500" x-text="meta[label].title + ':'"></span>
                        <span class="text-violet-700 font-medium" x-text="uniqueTypes(label).join(', ') || '—'"></span>
                    </div>
                </template>
            </div>
            <div class="text-xs text-gray-400 mt-3">
                Пълните промптове, обосновки и цена по фази са в панела „Лог на генерирането" в builder-а.
            </div>
        </div>
    </template>

    {{-- Хибрид: per-phase конфигурация --}}
    <div x-show="hybridCfgOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
         @keydown.escape.window="hybridCfgOpen = false">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="hybridCfgOpen = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 shrink-0">
                <h3 class="text-lg font-bold text-gray-900">🧪 Хибрид — конфигурация по фази</h3>
                <p class="text-xs text-gray-400 mt-0.5">Различен провайдър/модел за всяка фаза на планирането.</p>
            </div>
            <div class="p-6 overflow-y-auto">
                @include('flows.partials.phase-picker')
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 shrink-0">
                <button type="button" @click="hybridCfgOpen = false" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm">Затвори</button>
                <button type="button" @click="hybridCfgOpen = false; start('hybrid')"
                        class="px-4 py-2 rounded-lg bg-violet-600 text-white hover:bg-violet-700 text-sm font-bold">▶ Генерирай хибрид</button>
            </div>
        </div>
    </div>

    {{-- Запази като шаблон --}}
    <div x-show="savePopup.open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
         @keydown.escape.window="savePopup.open = false">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="savePopup.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">💾 Запази като шаблон</h3>
                <p class="text-xs text-gray-400 mt-0.5" x-text="savePopup.label ? 'План от ' + meta[savePopup.label].title : ''"></p>
            </div>
            <div class="p-6 space-y-3 text-sm">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Име на шаблона</label>
                    <input type="text" x-model="savePopup.name" @keydown.enter="confirmSave()"
                           class="w-full border border-gray-300 rounded-lg px-2.5 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" x-model="savePopup.isActive" class="rounded">
                    Направи го активен (графът на flow-а ще бъде заменен с този план)
                </label>
                <p x-show="savePopup.error" class="text-xs text-red-600" x-text="savePopup.error"></p>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
                <button type="button" @click="savePopup.open = false" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm">Отказ</button>
                <button type="button" @click="confirmSave()" :disabled="saving"
                        class="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 text-sm font-bold">
                    <span x-show="!saving">💾 Запази</span>
                    <span x-show="saving" class="animate-pulse">Запазване…</span>
                </button>
            </div>
        </div>
    </div>

    @include('flows.partials.dag-preview')
</div>

<script>
function planAb() {
    return {
        labels: ['ollama', 'openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen', 'hybrid'],
        availability: @json($availability),
        meta: {
            ollama:    { title: '🦙 Ollama (локален)', model: @json($plannerModels['ollama'] ?? '') },
            openai:    { title: '🤖 OpenAI',           model: @json($plannerModels['openai'] ?? '') },
            anthropic: { title: '🧠 Anthropic',        model: @json($plannerModels['anthropic'] ?? '') },
            deepseek:  { title: '🐋 DeepSeek',         model: @json($plannerModels['deepseek'] ?? '') },
            gemini:    { title: '✨ Gemini (free tier)', model: @json($plannerModels['gemini'] ?? '') },
            xai:       { title: '⚡ Grok (xAI)',        model: @json($plannerModels['xai'] ?? '') },
            qwen:      { title: '🐉 Qwen (Alibaba)',    model: @json($plannerModels['qwen'] ?? '') },
            hybrid:    { title: '🧪 Хибрид (по фази)',  model: 'конфигурирай ⚙' },
        },
        tokens: {},          // label → cache token, чийто статус го покрива
        running: {},         // label → bool
        cardModel: @json($plannerModels), // provider → избран модел за картата
        saving: false,
        savedAs: {},         // label → име на записания шаблон
        savePopup: { open: false, label: null, name: '', isActive: false, error: '' },
        hybridCfgOpen: false,
        error: null,
        state: { providers: {} },
        _pollTimer: null,

        // Per-phase picker за хибрида (споделен компонент с builder попъпа).
        picker: window.plannerPhasePicker(@js($plannerDefaults), {
            providers: ['ollama', 'openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen'],
            availability: @json($availability),
            cloudModels: @js($cloudModels),
            ollamaModels: @js($ollamaModels),
            pricing: @js($pricing),
        }),

        // Read-only DAG преглед (карти като в билдъра) + настройки на агент.
        dag: window.planDagPreview({
            agentTypes: @js($agentTypes),
            templateIcons: @js($templateIcons),
            readOnly: false,
        }),

        result(label) {
            return this.state.providers?.[label] || {};
        },

        isAvailable(label) {
            if (label !== 'hybrid') return !!this.availability[label];
            // Хибридът е достъпен, когато всички избрани провайдъри са налични.
            return this.picker.phaseOrder.every(ph => this.availability[this.picker.phases[ph].provider]);
        },

        anyRunning() {
            return Object.values(this.running).some(Boolean);
        },

        completedLabels() {
            return this.labels.filter(l => this.result(l).status === 'completed');
        },

        uniqueTypes(label) {
            const done = this.completedLabels();
            if (done.length < 2 || !done.includes(label)) return [];
            const mine = (this.result(label).agents || []).map(a => a.type);
            const others = new Set(done.filter(l => l !== label)
                .flatMap(l => (this.result(l).agents || []).map(a => a.type)));
            return [...new Set(mine.filter(t => !others.has(t)))];
        },

        async start(label = null) {
            this.error = null;
            const body = label === 'hybrid'
                ? { provider: 'hybrid', phases: this.picker.payload() }
                : (label
                    ? { provider: label, model: this.cardModel[label] || null }
                    : { models: this.cardModel });
            try {
                const res = await fetch(@json(route('flows.plan-ab.start', $flow)), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                });
                const data = await res.json();
                if (!res.ok || !data.token) { this.error = data.error || 'Стартирането се провали.'; return; }

                (data.providers || []).forEach(l => {
                    this.tokens[l] = data.token;
                    this.running[l] = true;
                    this.state.providers[l] = { status: 'running' };
                    delete this.savedAs[l];
                });
                this.schedulePoll();
            } catch (e) {
                this.error = 'Мрежова грешка: ' + e.message;
            }
        },

        schedulePoll() {
            clearTimeout(this._pollTimer);
            this._pollTimer = setTimeout(() => this.poll(), 2500);
        },

        async poll() {
            const activeTokens = [...new Set(
                this.labels.filter(l => this.running[l] && this.tokens[l]).map(l => this.tokens[l])
            )];
            if (!activeTokens.length) return;

            for (const token of activeTokens) {
                try {
                    const res = await fetch(@json(url('plan-ab-status')) + '/' + token, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();

                    if (res.status === 404) {
                        this.labels.filter(l => this.tokens[l] === token).forEach(l => {
                            this.running[l] = false;
                            if (this.result(l).status === 'running') this.state.providers[l] = { status: 'failed', error: data.error || 'Токенът изтече.' };
                        });
                        continue;
                    }

                    // Merge само етикетите, покрити от този token.
                    this.labels.filter(l => this.tokens[l] === token).forEach(l => {
                        const r = data.providers?.[l];
                        if (r) this.state.providers[l] = r;
                        if (r && ['completed', 'failed', 'skipped'].includes(r.status)) this.running[l] = false;
                        // Командата приключи изцяло, но този етикет няма запис → не е стартиран.
                        if (!r && data.status === 'completed') this.running[l] = false;
                    });
                } catch (e) { /* keep polling */ }
            }

            if (this.anyRunning()) this.schedulePoll();
        },

        openSave(label) {
            this.savePopup = {
                open: true,
                label,
                name: this.result(label).model || label,
                isActive: false,
                error: '',
            };
        },

        async confirmSave() {
            const label = this.savePopup.label;
            if (!this.savePopup.name.trim()) { this.savePopup.error = 'Въведи име на шаблона.'; return; }
            this.saving = true;
            this.savePopup.error = '';
            try {
                const res = await fetch(@json($saveUrl), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' },
                    body: JSON.stringify({
                        token: this.tokens[label],
                        label,
                        name: this.savePopup.name.trim(),
                        is_active: this.savePopup.isActive,
                        // Редакциите от DAG прегледа пътуват с агентите от страницата.
                        agents: this.result(label).agents || null,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    this.savePopup.error = data.error || Object.values(data.errors || {}).flat().join(' ') || 'Запазването се провали.';
                    return;
                }
                this.savedAs[label] = data.version.name;
                this.savePopup.open = false;
            } catch (e) {
                this.savePopup.error = 'Мрежова грешка: ' + e.message;
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>
@endsection
