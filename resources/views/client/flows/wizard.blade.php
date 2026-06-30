@extends('layouts.client')

@section('title', 'Създай нов Flow')

@push('head')
<style>
    /* Typing индикатор — три анимирани точки */
    @keyframes wizTypingBlink { 0%, 80%, 100% { opacity: .25; transform: translateY(0); } 40% { opacity: 1; transform: translateY(-3px); } }
    .wiz-typing { display: inline-flex; align-items: center; gap: 3px; }
    .wiz-typing span { width: 6px; height: 6px; border-radius: 9999px; background: currentColor; animation: wizTypingBlink 1.2s infinite both; }
    .wiz-typing span:nth-child(2) { animation-delay: .2s; }
    .wiz-typing span:nth-child(3) { animation-delay: .4s; }
</style>
@endpush

@section('content')
<div class="max-w-6xl mx-auto"
     x-data="wizard({
        draftId: {{ $draft->id }},
        available: {{ $draft && $available ? 'true' : 'false' }},
        sendUrl: '{{ route('client.wizard.send') }}',
        statusUrl: '{{ route('client.wizard.status', 'TOKEN') }}',
        historyUrl: '{{ route('client.wizard.history', $draft) }}',
        buildUrl: '{{ route('client.wizard.build', $draft) }}',
        improveUrl: '{{ route('client.wizard.improve-description') }}',
     })"
     x-init="init()">

    <div class="mb-6">
        <h1 class="text-2xl font-display font-bold text-ink">Създай нов Flow</h1>
        <p class="text-sm text-muted mt-1">Отговори на няколко въпроса — асистентът ще напише описанието и ще създаде Flow-а вместо теб.</p>
    </div>

    <template x-if="!available">
        <x-alert type="warning" :dismissible="false" class="mb-6">Създателят не е наличен в момента (липсва AI ключ). Свържи се с администратор.</x-alert>
    </template>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- ── Чат панел ─────────────────────────────────────────── --}}
        <div class="flex flex-col bg-surface border border-line rounded-xl shadow-card h-[70vh]">
            <div class="px-5 py-3 border-b border-line flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-info-soft text-primary"><x-icon name="sparkles" size="4" /></span>
                <span class="text-sm font-semibold text-ink">Асистент</span>
                <div class="ml-auto flex items-center gap-3">
                    <form method="POST" action="{{ route('client.wizard.new') }}"
                          onsubmit="return confirm('Да започнем нов чат? Текущият разговор ще се изчисти.');">
                        @csrf
                        <button type="submit" title="Започни нов разговор"
                                class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-ink transition">
                            <x-icon name="plus" size="4" /> Нов чат
                        </button>
                    </form>
                </div>
            </div>

            {{-- Съобщения (min-height:0 е задължително, за да скролира flex-детето) --}}
            <div class="flex-1 min-h-0 overflow-y-auto px-5 py-4 space-y-4" x-ref="scroll" style="min-height:0">
                {{-- Стартови чипове --}}
                <template x-if="messages.length === 0 && !thinking">
                    <div class="space-y-3">
                        <p class="text-sm text-muted">Здравей! Какво искаш да създадеш? Избери или опиши с думи:</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="chip in starters" :key="chip">
                                <button type="button" @click="useChip(chip)"
                                        class="px-3 py-1.5 text-sm rounded-full border border-line-strong text-ink hover:bg-surface-subtle transition" x-text="chip"></button>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-for="msg in messages" :key="msg.uid">
                    <div>
                        {{-- Балон --}}
                        <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                            <div :class="msg.role === 'user'
                                ? 'bg-primary text-primary-fg rounded-2xl rounded-br-sm px-4 py-2 max-w-[85%]'
                                : (msg.failed ? 'bg-danger-soft text-danger-strong rounded-2xl rounded-bl-sm px-4 py-2 max-w-[90%]' : 'bg-surface-subtle text-ink rounded-2xl rounded-bl-sm px-4 py-2 max-w-[90%]')">
                                <p class="text-sm whitespace-pre-line" x-text="msg.content"></p>
                            </div>
                        </div>

                        {{-- Форма-въпрос (radio/checkbox + „Друго") --}}
                        <template x-if="msg.question && !msg.answered">
                            <div class="mt-2 ml-1 border border-line rounded-xl p-3 space-y-2 bg-surface">
                                <p class="text-sm font-medium text-ink" x-text="msg.question.text"></p>
                                <div class="space-y-1.5">
                                    <template x-for="opt in msg.question.options" :key="opt.value">
                                        <label class="flex items-start gap-2 text-sm cursor-pointer rounded-md px-2 py-1.5 hover:bg-surface-subtle">
                                            <input :type="msg.question.input_type === 'radio' ? 'radio' : 'checkbox'"
                                                   :name="'q_' + msg.uid" class="mt-0.5" style="accent-color: var(--color-primary)"
                                                   :checked="msg.selected.includes(opt.value)"
                                                   @change="toggleOpt(msg, opt.value)">
                                            <span>
                                                <span class="text-ink" x-text="opt.label"></span>
                                                <span class="text-xs text-subtle block" x-show="opt.hint" x-text="opt.hint"></span>
                                            </span>
                                        </label>
                                    </template>
                                    <template x-if="msg.question.allow_other">
                                        <div>
                                            <label class="flex items-center gap-2 text-sm cursor-pointer rounded-md px-2 py-1.5 hover:bg-surface-subtle">
                                                <input type="checkbox" style="accent-color: var(--color-primary)" x-model="msg.otherChecked">
                                                <span class="text-ink" x-text="msg.question.other_label"></span>
                                            </label>
                                            <input x-show="msg.otherChecked" x-model="msg.other" type="text" placeholder="Опиши…"
                                                   class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary">
                                        </div>
                                    </template>
                                </div>
                                <x-button size="sm" x-on:click="answer(msg)"
                                          x-bind:disabled="msg.selected.length === 0 && !(msg.otherChecked && msg.other.trim())">Изпрати отговор</x-button>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- „Пише…" — анимирани точки --}}
                <template x-if="thinking">
                    <div class="flex justify-start">
                        <div class="bg-surface-subtle text-muted rounded-2xl rounded-bl-sm px-4 py-2 text-sm inline-flex items-center gap-2">
                            <span x-text="(stage || 'Мисля').replace(/[.…\s]+$/, '')"></span>
                            <span class="wiz-typing"><span></span><span></span><span></span></span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Вход --}}
            <form class="px-4 py-3 border-t border-line flex items-end gap-2" @submit.prevent="sendText()">
                <textarea x-model="input" rows="1" placeholder="Напиши съобщение…" x-ref="input"
                          @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); sendText(); }"
                          class="flex-1 resize-none rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"></textarea>
                <x-button type="submit" icon="paper-airplane" x-bind:disabled="thinking || !input.trim()">Изпрати</x-button>
            </form>
        </div>

        {{-- ── Платно (заглавие + описание) ──────────────────────── --}}
        <div class="flex flex-col gap-4">
            <x-card>
                <div class="space-y-4">
                    <x-field label="Заглавие на Flow-а" name="title">
                        <x-input x-model="title" @input="titleDirty = true" placeholder="напр. Facebook пост за промоция" />
                    </x-field>

                    <x-field label="Описание (асистентът го попълва, можеш да го редактираш)" name="description">
                        <div class="relative">
                            <textarea x-model="description" @input="descDirty = true" rows="12"
                                      x-bind:disabled="improving"
                                      placeholder="Описанието се сглобява тук, докато отговаряш…"
                                      class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary disabled:opacity-60"></textarea>
                            <div x-show="improving" x-cloak class="absolute inset-0 flex items-center justify-center rounded-lg bg-surface/70 backdrop-blur-[1px]">
                                <span class="inline-flex items-center gap-2 text-sm font-medium text-primary">
                                    <x-icon name="sparkles" size="4" class="animate-pulse" /> Подобряваме описанието с AI…
                                </span>
                            </div>
                        </div>
                    </x-field>

                    {{-- Recap --}}
                    <template x-if="recap.length">
                        <div class="rounded-lg bg-surface-subtle border border-line p-3">
                            <p class="text-xs font-semibold text-subtle uppercase tracking-wide mb-1.5">Обобщение</p>
                            <ul class="text-sm text-muted space-y-0.5 list-disc list-inside">
                                <template x-for="r in recap" :key="r"><li x-text="r"></li></template>
                            </ul>
                        </div>
                    </template>
                </div>
            </x-card>

            {{-- Скорост (Бързо ↔ Икономично) — без терминология за модели --}}
            <div class="bg-surface border border-line rounded-xl shadow-card p-4">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <span class="text-sm font-medium text-ink">Скорост на изпълнение</span>
                    <span class="text-xs text-subtle" x-text="({fast:'по-бързо, малко по-скъпо', balanced:'баланс между двете', economic:'по-бавно, най-евтино'})[speed]"></span>
                </div>
                <div class="inline-flex w-full rounded-lg border border-line bg-surface-subtle p-0.5" role="radiogroup">
                    <template x-for="opt in [{v:'fast',l:'Бързо'},{v:'balanced',l:'Балансирано'},{v:'economic',l:'Икономично'}]" :key="opt.v">
                        <button type="button" @click="speed = opt.v" role="radio" :aria-checked="speed === opt.v"
                                :class="speed === opt.v ? 'bg-surface text-ink shadow-card' : 'text-muted hover:text-ink'"
                                class="flex-1 px-3 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                x-text="opt.l"></button>
                    </template>
                </div>
                <p class="text-xs text-muted mt-3 leading-relaxed" x-text="speedHint[speed]"></p>
            </div>

            <button type="button" @click="build()"
                    x-bind:disabled="building || improving || description.trim().length < 10"
                    :class="phase === 'ready'
                        ? 'bg-primary text-primary-fg hover:bg-primary-hover shadow-popover ring-2 ring-primary/30'
                        : 'bg-primary text-primary-fg hover:bg-primary-hover'"
                    class="inline-flex items-center justify-center gap-2 h-12 px-5 text-base font-semibold rounded-lg transition disabled:opacity-50 disabled:pointer-events-none">
                <template x-if="improving">
                    <span class="inline-flex items-center gap-2"><x-icon name="cog-6-tooth" size="5" class="animate-spin" /> Подобряваме описанието…</span>
                </template>
                <template x-if="!improving">
                    <span class="inline-flex items-center gap-2"><x-icon name="bolt" size="5" /> <span x-text="improvedReady ? 'Генерирай сега' : 'Готово, Генерирай'"></span></span>
                </template>
            </button>
            <p class="text-xs text-center" x-show="improveNote" x-cloak :class="improvedReady ? 'text-primary' : 'text-subtle'" x-text="improveNote"></p>
            <p class="text-xs text-subtle text-center" x-show="phase === 'ready' && !improvedReady && !improveNote">Имаме достатъчно — можеш да генерираш или да продължиш разговора.</p>
        </div>
    </div>

    {{-- ── Overlay при генериране ────────────────────────────── --}}
    <div x-show="building" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-6">
        <div class="bg-surface rounded-xl shadow-popover max-w-md w-full p-6 text-center">
            <template x-if="!buildError">
                <div>
                    <div class="flex justify-center mb-4"><x-icon name="cog-6-tooth" size="8" class="text-primary animate-spin" /></div>
                    <h3 class="text-lg font-display font-semibold text-ink">Създаваме твоя Flow…</h3>
                    <p class="text-sm text-muted mt-1" x-text="buildStage || 'Това отнема малко време.'"></p>
                </div>
            </template>
            <template x-if="buildError">
                <div>
                    <div class="flex justify-center mb-4"><x-icon name="exclamation-triangle" size="8" class="text-danger" /></div>
                    <h3 class="text-lg font-display font-semibold text-ink">Нещо се обърка</h3>
                    <p class="text-sm text-muted mt-1" x-text="buildError"></p>
                    <div class="mt-4"><x-button variant="secondary" x-on:click="building = false; buildError = ''">Затвори</x-button></div>
                </div>
            </template>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function wizard(cfg) {
        return {
            draftId: cfg.draftId,
            available: cfg.available,
            messages: [],
            input: '',
            title: '',
            description: '',
            speed: 'fast',
            speedHint: {
                fast: 'Резултатът е готов възможно най-бързо. Подходящо, когато бързаш или ще пускаш този Flow често и държиш на скоростта.',
                balanced: 'Разумен баланс между скорост и спестяване — добър избор за повечето случаи, ако не си сигурен какво да избереш.',
                economic: 'Спестяваш максимално, но изпълнението отнема малко повече време. Подходящо, когато резултатът не ти трябва веднага.',
            },
            titleDirty: false,
            descDirty: false,
            thinking: false,
            stage: '',
            phase: 'interviewing',
            recap: [],
            building: false,
            buildStage: '',
            buildError: '',
            improving: false,
            improvedReady: false,
            improveNote: '',
            uidSeq: 0,
            starters: ['Пост за социална мрежа', 'Одит на бизнес', 'Анализ на конкуренция', 'SEO оптимизация', 'Друго…'],
            csrf: document.querySelector('meta[name=csrf-token]').content,

            async init() {
                try {
                    const res = await fetch(cfg.historyUrl, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (data.title) { this.title = data.title; this.titleDirty = true; }
                    if (data.description) { this.description = data.description; }
                    if (data.status === 'ready') this.phase = 'ready';
                    const msgs = (data.messages || []);
                    msgs.forEach((m, i) => {
                        const isLast = i === msgs.length - 1;
                        this.messages.push(this.mkMsg(m.role, m.content, m.question, m.failed, !(isLast && m.question)));
                    });
                    this.scrollDown();
                } catch (e) { /* нов разговор */ }
            },

            mkMsg(role, content, question = null, failed = false, answered = false) {
                return { uid: ++this.uidSeq, role, content, question: question || null, failed: !!failed,
                         answered, selected: [], otherChecked: false, other: '' };
            },

            useChip(text) { this.input = text === 'Друго…' ? '' : text; if (text !== 'Друго…') this.sendText(); else this.$refs.input.focus(); },

            sendText() {
                const text = this.input.trim();
                if (!text || this.thinking) return;
                this.messages.push(this.mkMsg('user', text));
                this.input = '';
                this.dispatch({ message: text });
            },

            toggleOpt(msg, value) {
                if (msg.question.input_type === 'radio') { msg.selected = [value]; return; }
                const i = msg.selected.indexOf(value);
                if (i === -1) msg.selected.push(value); else msg.selected.splice(i, 1);
            },

            answer(msg) {
                const values = [...msg.selected];
                const other = msg.otherChecked ? msg.other.trim() : '';
                if (values.length === 0 && !other) return;
                msg.answered = true;
                const labels = msg.question.options.filter(o => values.includes(o.value)).map(o => o.label);
                if (other) labels.push(other);
                this.messages.push(this.mkMsg('user', labels.join(', ')));
                this.dispatch({ answer: { key: msg.question.key, values, other } });
            },

            dispatch(body) {
                if (!this.available) return;
                this.thinking = true;
                this.stage = 'Мисля…';
                this.scrollDown();
                const payload = { draft_id: this.draftId, ...body };
                if (this.descDirty) payload.description = this.description;
                fetch(cfg.sendUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                }).then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { this.thinking = false; this.pushBot(data.message || 'Грешка.', null, true); return; }
                    this.pollStatus(data.token);
                }).catch(() => { this.thinking = false; this.pushBot('Възникна грешка при изпращане.', null, true); });
            },

            pollStatus(token) {
                const url = cfg.statusUrl.replace('TOKEN', token);
                const tick = async () => {
                    try {
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const d = await res.json();
                        if (d.status === 'pending') { this.stage = d.stage || 'Мисля…'; return; }
                        clearInterval(this.timer);
                        this.thinking = false;
                        if (d.status === 'failed' || d.status === 'expired') { this.pushBot(d.error || 'Грешка.', null, true); return; }
                        // completed
                        this.pushBot(d.reply || '…', d.question || null);
                        if (d.description_draft && !this.descDirty) this.description = d.description_draft;
                        if (d.suggested_title && !this.titleDirty) this.title = d.suggested_title;
                        this.recap = d.recap || [];
                        this.phase = d.phase === 'ready' ? 'ready' : 'interviewing';
                        // Асистентът е готов → подобри описанието с AI веднъж, преди
                        // клиентът да генерира (текстът в полето става вече подобрен).
                        if (this.phase === 'ready' && !this.improvedReady && this.description.trim().length >= 10) {
                            this.improveDescriptionNow();
                        }
                    } catch (e) { /* пробвай пак */ }
                };
                tick();
                this.timer = setInterval(tick, 1600);
            },

            pushBot(content, question, failed = false) {
                this.messages.push(this.mkMsg('assistant', content, question, failed, false));
                this.scrollDown();
            },

            // Подобрява текущото описание през „Подобри с AI" и го връща в полето.
            // Вика се автоматично щом асистентът е готов (phase === 'ready'), за да
            // влезе в полето вече подобреният текст; пуска се най-много веднъж.
            async improveDescriptionNow() {
                if (this.improving) return;
                this.improving = true; this.improveNote = '';
                this.scrollDown();
                try {
                    const res = await fetch(cfg.improveUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ title: this.title, description: this.description }),
                    });
                    const d = await res.json().catch(() => ({}));
                    if (res.ok && d.improved) {
                        this.description = d.improved;
                        this.descDirty = true; // подобреният текст вече е „на клиента" — асистентът не го пренаписва
                        this.improveNote = 'Описанието е подобрено с AI — прегледай го и редактирай при нужда.';
                    } else {
                        this.improveNote = (d.error || 'Не успяхме да подобрим описанието.') + ' Можеш да генерираш с текущото.';
                    }
                } catch (e) {
                    this.improveNote = 'Не успяхме да подобрим описанието. Можеш да генерираш с текущото.';
                } finally {
                    this.improving = false;
                    this.improvedReady = true;
                    this.scrollDown();
                }
            },

            build() {
                if (this.description.trim().length < 10 || this.improving) return;
                // Резервен вариант: ако описанието е въведено ръчно без да се стигне
                // до „готов" асистент, подобри го на първото натискане преди генерация.
                if (!this.improvedReady) { this.improveDescriptionNow(); return; }
                this.building = true; this.buildError = ''; this.buildStage = 'Подготвяме…';
                fetch(cfg.buildUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ title: this.title, description: this.description, speed: this.speed }),
                }).then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { this.buildError = data.message || 'Неуспешно създаване.'; return; }
                    this.pollBuild(data.status_url, data.redirect_url);
                }).catch(() => { this.buildError = 'Възникна грешка.'; });
            },

            pollBuild(statusUrl, redirectUrl) {
                const tick = async () => {
                    try {
                        const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
                        const d = await res.json();
                        this.buildStage = d.stage || this.buildStage;
                        if (d.status === 'completed') { clearInterval(this.buildTimer); window.location = redirectUrl; }
                        else if (d.status === 'failed' || d.status === 'expired') { clearInterval(this.buildTimer); this.buildError = d.error || 'Генерирането се провали.'; }
                    } catch (e) { /* пробвай пак */ }
                };
                tick();
                this.buildTimer = setInterval(tick, 2000);
            },

            scrollDown() {
                const go = () => { const el = this.$refs.scroll; if (el) el.scrollTop = el.scrollHeight; };
                // веднъж след DOM ъпдейта и втори път след рендване на формата-въпрос
                // (нести x-for/x-if опции), за да стигне реално до дъното всеки път.
                this.$nextTick(go);
                setTimeout(go, 120);
            },
        };
    }
</script>
@endpush
@endsection
