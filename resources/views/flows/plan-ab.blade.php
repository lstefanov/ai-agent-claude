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
                Едно и също описание → план от локалния Ollama, OpenAI и Anthropic. Сравни и избери най-добрия.
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

    @if (count($available) < 3)
        <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            @if (! in_array('ollama', $available)) Ollama сървърът не отговаря — локалната колона е недостъпна. @endif
            @if (! in_array('openai', $available)) Липсва <code>OPENAI_API_KEY</code> — OpenAI колоната е недостъпна. @endif
            @if (! in_array('anthropic', $available)) Липсва <code>ANTHROPIC_API_KEY</code> — Anthropic колоната е недостъпна. @endif
        </div>
    @endif

    <template x-if="error">
        <div class="mb-6 rounded-lg bg-red-50 border border-red-100 px-4 py-3 text-sm text-red-700" x-text="error"></div>
    </template>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <template x-for="provider in providers" :key="provider">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden flex flex-col"
                 :class="!availability[provider] ? 'opacity-60' : ''">
                <div class="px-4 py-3.5 border-b border-gray-100 flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 truncate" x-text="meta[provider].title"></div>
                        <div class="text-xs text-gray-400 truncate" x-text="result(provider).model || meta[provider].model"></div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <template x-if="result(provider).status === 'running'">
                            <span class="text-xs text-indigo-500 animate-pulse">планира…</span>
                        </template>
                        <template x-if="result(provider).status === 'failed'">
                            <span class="text-xs text-red-500">✗ провал</span>
                        </template>
                        <template x-if="result(provider).status === 'skipped'">
                            <span class="text-xs text-gray-400">пропуснат</span>
                        </template>
                        <button @click="start(provider)"
                                :disabled="running[provider] || !availability[provider]"
                                class="text-xs bg-white border border-indigo-300 hover:border-indigo-500 text-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed font-medium px-2.5 py-1 rounded-lg transition"
                                :title="'Генерирай план само с ' + meta[provider].title">
                            <span x-show="!running[provider]" x-text="result(provider).status === 'completed' ? '↻ Отново' : '▶ Генерирай'"></span>
                            <span x-show="running[provider]" class="animate-pulse">…</span>
                        </button>
                    </div>
                </div>

                {{-- Stats row --}}
                <template x-if="result(provider).status === 'completed'">
                    <div class="px-4 py-2 bg-gray-50/70 border-b border-gray-100 text-xs text-gray-500 tabular-nums flex items-center gap-3">
                        <span x-text="result(provider).agents.length + ' агента'"></span>
                        <span x-text="(result(provider).duration_ms/1000).toFixed(1) + 's'"></span>
                        <span class="font-medium"
                              :class="provider === 'ollama' || !(result(provider).cost_usd > 0) ? 'text-green-600' : 'text-amber-600'"
                              x-text="(result(provider).cost_usd > 0) ? ('$' + result(provider).cost_usd) : 'безплатно'"></span>
                    </div>
                </template>

                <div class="p-4 flex-1 flex flex-col">
                    <template x-if="result(provider).error">
                        <div class="text-sm text-red-600 mb-3" x-text="result(provider).error"></div>
                    </template>

                    <template x-if="result(provider).status === 'completed'">
                        <div class="flex-1 flex flex-col">
                            <ol class="space-y-2 mb-4 flex-1">
                                <template x-for="agent in result(provider).agents" :key="agent.uid">
                                    <li class="border rounded-lg px-3 py-2 text-sm"
                                        :class="uniqueTypes(provider).includes(agent.type) ? 'border-violet-300 bg-violet-50' : 'border-gray-100 bg-gray-50'">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium text-gray-900 truncate" x-text="agent.name"></span>
                                            <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded-full shrink-0"
                                                  :class="uniqueTypes(provider).includes(agent.type) ? 'bg-violet-200 text-violet-800' : 'bg-gray-200 text-gray-600'"
                                                  x-text="agent.type"></span>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            <span x-show="agent.depends_on && agent.depends_on.length" x-text="'← ' + (agent.depends_on || []).join(', ')"></span>
                                            <span x-show="!agent.depends_on || !agent.depends_on.length" class="text-gray-400">старт</span>
                                            <span x-show="String(agent.model || '').includes('/')" class="ml-1 text-amber-600"
                                                  x-text="'⤴ ' + String(agent.model || '').split('/')[0]"></span>
                                        </div>
                                        <details class="mt-1">
                                            <summary class="text-xs text-indigo-500 cursor-pointer select-none">промптове</summary>
                                            <div class="mt-1 text-xs text-gray-600 whitespace-pre-wrap" x-text="agent.prompt_template"></div>
                                        </details>
                                    </li>
                                </template>
                            </ol>
                            <button @click="apply(provider)" :disabled="applying"
                                    class="w-full bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
                                ✓ Използвай този план
                            </button>
                        </div>
                    </template>

                    <template x-if="!result(provider).status && !running[provider]">
                        <div class="text-sm text-gray-400 text-center py-10">
                            <span x-show="availability[provider]">Натисни „▶ Генерирай" за план от този provider.</span>
                            <span x-show="!availability[provider]">Недостъпен — виж бележката горе.</span>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <template x-if="completedProviders().length >= 2">
        <div class="mt-6 bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-sm">
            <div class="font-semibold text-gray-900 mb-2">Разлики (типове агенти, които има само един provider)</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <template x-for="provider in completedProviders()" :key="'diff-' + provider">
                    <div>
                        <span class="text-gray-500" x-text="meta[provider].title + ':'"></span>
                        <span class="text-violet-700 font-medium" x-text="uniqueTypes(provider).join(', ') || '—'"></span>
                    </div>
                </template>
            </div>
            <div class="text-xs text-gray-400 mt-3">
                Уникалните агенти са маркирани в лилаво в колоните. Пълните промптове, обосновки и цена по фази са в
                панела „Лог на генерирането" в builder-а.
            </div>
        </div>
    </template>
</div>

<script>
function planAb() {
    return {
        providers: ['ollama', 'openai', 'anthropic'],
        availability: @json($availability),
        meta: {
            ollama:    { title: '🦙 Ollama (локален)', model: @json($plannerModels['ollama'] ?? '') },
            openai:    { title: '🤖 OpenAI',           model: @json($plannerModels['openai'] ?? '') },
            anthropic: { title: '🧠 Anthropic',        model: @json($plannerModels['anthropic'] ?? '') },
        },
        tokens: {},          // provider → cache token, чийто статус го покрива
        running: {},         // provider → bool
        applying: false,
        error: null,
        state: { providers: {} },
        _pollTimer: null,

        result(provider) {
            return this.state.providers?.[provider] || {};
        },

        anyRunning() {
            return Object.values(this.running).some(Boolean);
        },

        completedProviders() {
            return this.providers.filter(p => this.result(p).status === 'completed');
        },

        uniqueTypes(provider) {
            const done = this.completedProviders();
            if (done.length < 2 || !done.includes(provider)) return [];
            const mine = (this.result(provider).agents || []).map(a => a.type);
            const others = new Set(done.filter(p => p !== provider)
                .flatMap(p => (this.result(p).agents || []).map(a => a.type)));
            return [...new Set(mine.filter(t => !others.has(t)))];
        },

        async start(provider = null) {
            this.error = null;
            try {
                const res = await fetch(@json(route('flows.plan-ab.start', $flow)), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' },
                    body: JSON.stringify(provider ? { provider } : {}),
                });
                const data = await res.json();
                if (!res.ok || !data.token) { this.error = data.error || 'Стартирането се провали.'; return; }

                (data.providers || []).forEach(p => {
                    this.tokens[p] = data.token;
                    this.running[p] = true;
                    this.state.providers[p] = { status: 'running' };
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
                this.providers.filter(p => this.running[p] && this.tokens[p]).map(p => this.tokens[p])
            )];
            if (!activeTokens.length) return;

            for (const token of activeTokens) {
                try {
                    const res = await fetch(@json(url('plan-ab-status')) + '/' + token, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();

                    if (res.status === 404) {
                        this.providers.filter(p => this.tokens[p] === token).forEach(p => {
                            this.running[p] = false;
                            if (this.result(p).status === 'running') this.state.providers[p] = { status: 'failed', error: data.error || 'Токенът изтече.' };
                        });
                        continue;
                    }

                    // Merge само провайдърите, покрити от този token.
                    this.providers.filter(p => this.tokens[p] === token).forEach(p => {
                        const r = data.providers?.[p];
                        if (r) this.state.providers[p] = r;
                        if (r && ['completed', 'failed', 'skipped'].includes(r.status)) this.running[p] = false;
                        // Командата приключи изцяло, но този provider няма запис → не е стартиран.
                        if (!r && data.status === 'completed') this.running[p] = false;
                    });
                } catch (e) { /* keep polling */ }
            }

            if (this.anyRunning()) this.schedulePoll();
        },

        async apply(provider) {
            this.applying = true;
            try {
                const res = await fetch(@json(route('flows.plan-ab.apply', $flow)), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' },
                    body: JSON.stringify({ token: this.tokens[provider], provider }),
                });
                const data = await res.json();
                if (data.ok && data.redirect) { window.location = data.redirect; return; }
                this.error = data.error || 'Прилагането се провали.';
            } catch (e) {
                this.error = 'Мрежова грешка: ' + e.message;
            }
            this.applying = false;
        },
    };
}
</script>
@endsection
