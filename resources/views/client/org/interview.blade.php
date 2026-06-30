@extends('layouts.client')

@section('title', 'Интервю с Управителя')

@push('head')
<style>
    [x-cloak] { display: none !important; }
</style>
@endpush

@section('content')
@php($persona = $manager->persona)
<div class="max-w-3xl mx-auto px-6 py-8">
    <header class="mb-6 flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-xs font-mono uppercase tracking-wider text-muted mb-1">Стъпка 3 от 3 · Интервю</p>
            <h1 class="text-2xl font-semibold text-ink">{{ $persona?->name ?? 'Управителят' }} иска да разбере бизнеса</h1>
            <p class="text-muted mt-1">Няколко въпроса, за да си състави ясна представа преди да проектира екипа.</p>
        </div>
        @include('client.org._wizard-reset')
    </header>

    <div x-data="interview({
        sendUrl: '{{ route('client.org.interview.send') }}',
        statusTpl: '{{ route('client.org.interview.status', ['token' => 'TOKEN']) }}',
        ready: {{ $profile->status === 'ready' ? 'true' : 'false' }},
        transcript: @js($transcript ?? []),
     })">
    <div class="rounded-xl border border-line bg-surface flex flex-col" style="height: 68vh">
        {{-- Транскрипт --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-4 space-y-3" x-ref="scroll" style="min-height:0">
            <template x-for="msg in messages" :key="msg.uid">
                <div :data-uid="msg.uid">
                    {{-- Балонът се показва само при текст (празен reply + въпрос → само картата). --}}
                    <template x-if="msg.content && msg.content.trim()">
                        <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start msg-bot-bubble'"
                             x-init="msg.role === 'assistant' && messages.at(-1)?.uid === msg.uid && $nextTick(() => scrollToMsg(msg.uid))">
                            <div :class="msg.role === 'user'
                                ? 'bg-primary text-primary-fg rounded-2xl rounded-br-sm px-4 py-2 max-w-[85%]'
                                : (msg.failed ? 'bg-danger-soft text-danger-strong rounded-2xl rounded-bl-sm px-4 py-2 max-w-[90%]' : 'bg-surface-subtle text-ink rounded-2xl rounded-bl-sm px-4 py-2 max-w-[90%]')">
                                <template x-if="msg.role === 'assistant'">
                                    <div class="text-sm ai-prose" x-html="$md(msg.content)"></div>
                                </template>
                                <template x-if="msg.role !== 'assistant'">
                                    <p class="text-sm whitespace-pre-line" x-text="msg.content"></p>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Готови опции + „Друго" (текстът на въпроса е в балона по-горе). --}}
                    <template x-if="msg.question && !msg.answered">
                        <div class="mt-2 ml-1 border border-line rounded-xl p-3 space-y-2 bg-surface">
                            <template x-if="msg.question.input_type !== 'radio'">
                                <p class="text-xs text-muted flex items-center gap-1">
                                    <x-icon name="check-circle" size="4" class="text-accent" />Избери всички, които важат
                                </p>
                            </template>
                            <div class="space-y-1.5">
                                <template x-for="opt in msg.question.options" :key="opt.value">
                                    <label class="flex items-start gap-2 text-sm cursor-pointer rounded-md px-2 py-1.5 hover:bg-surface-subtle">
                                        <input :type="msg.question.input_type === 'radio' ? 'radio' : 'checkbox'" :name="'q_' + msg.uid"
                                               class="mt-0.5" style="accent-color: var(--color-primary)"
                                               :checked="msg.selected.includes(opt.value)" @change="toggleOpt(msg, opt.value)">
                                        <span class="text-ink" x-text="opt.label"></span>
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
                            <x-org.busy-button size="sm" busy="thinking" loading-text="Изпращам…" :spinner="false"
                                      x-on:click="answer(msg)"
                                      x-bind:disabled="msg.selected.length === 0 && !(msg.otherChecked && msg.other.trim())">Изпрати отговор</x-org.busy-button>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Индикатор „мисля" — анимирано лого + контекстен етикет --}}
            <template x-if="thinking">
                <x-org.thinking />
            </template>
        </div>

        {{-- Свободен вход — винаги достъпен, дори след завършено интервю --}}
        <div class="border-t border-line p-3">
            <form @submit.prevent="sendText()" class="flex items-end gap-2">
                <textarea x-model="input" rows="1"
                          :placeholder="ready ? 'Имаш още въпроси? Напиши…' : 'Напиши свободно…'"
                          @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); sendText(); }"
                          class="flex-1 resize-none rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"></textarea>
                <x-org.busy-button type="submit" size="sm" busy="thinking" loading-text="Изпращам…" :spinner="false"
                          x-bind:disabled="!input.trim() || thinking">Изпрати</x-org.busy-button>
            </form>
        </div>
    </div>

    {{-- Готово — под чата, широко колкото панела --}}
    <template x-if="ready">
        <div class="mt-4 space-y-3" x-cloak>
            <p class="text-sm text-success-strong text-center">✓ Управителят има ясна представа.</p>
            <x-button :href="route('client.org.analysis')" class="w-full">Виж анализа →</x-button>
        </div>
    </template>
    </div>
</div>

@push('scripts')
<script>
function interview(cfg) {
    return {
        messages: [], input: '', thinking: false, stage: '', ready: cfg.ready, timer: null, uid: 0, started: false,
        init() {
            if (this.started) return;   // Alpine може да извика init() повече от веднъж — пазим се.
            this.started = true;

            this.hydrate();             // възстанови предишния разговор (оцелява при refresh)

            if (this.ready) {
                // Транскриптът вече завършва с „готови сме"; статичното съобщение е само за празен профил.
                if (this.messages.length === 0) {
                    this.pushBot('Имам ясна представа за бизнеса. Готови сме за дизайна на екипа.');
                }
                return;
            }

            const t = cfg.transcript || [];
            const last = t.length ? t[t.length - 1] : null;
            // Има отворен въпрос → чакаме отговор. Иначе (празно или прекъснат ход) → продължи.
            if (last && last.role === 'assistant' && last.question) {
                return;
            }
            this.thinking = true;
            this.dispatch({});
        },
        // Възстановяване на разговора от сървърния транскрипт. Ползва mkMsg → uid расте 1..N,
        // така че следващите „живи" съобщения не се сблъскват по :key.
        hydrate() {
            const t = cfg.transcript || [];
            for (const e of t) {
                const role = e.role === 'user' ? 'user' : 'assistant';
                const q = role === 'assistant' ? (e.question || null) : null;
                // Празен reply + въпрос → балонът показва текста на въпроса (носи разговора при refresh).
                const content = (e.content && e.content.trim()) ? e.content : (q && q.text ? q.text : (e.content || ''));
                const m = this.mkMsg(role, content, q);
                if (role === 'assistant') m.answered = true;   // минал въпрос → вече отговорен
                this.messages.push(m);
            }
            // Последният въпрос на Управителя остава отворен, ако интервюто още тече.
            if (!this.ready && t.length) {
                const last = t[t.length - 1];
                if (last.role === 'assistant' && last.question) {
                    const open = this.messages[this.messages.length - 1];
                    open.answered = false;
                    this.scrollToMsg(open.uid);
                    return;
                }
            }
            this.scroll();
        },
        mkMsg(role, content, question = null) {
            return { uid: ++this.uid, role, content, question, answered: false, failed: false,
                     selected: [], otherChecked: false, other: '' };
        },
        pushBot(content, question = null, failed = false) {
            const m = this.mkMsg('assistant', content, question);
            m.failed = failed;
            this.messages.push(m);
            this.scrollToMsg(m.uid);
        },
        pushUser(content) { this.messages.push(this.mkMsg('user', content)); this.scroll(); },
        scheduleScroll(fn, delays = [0, 120, 300, 500, 800]) {
            this.$nextTick(() => {
                delays.forEach(ms => (ms ? setTimeout(fn, ms) : fn()));
                requestAnimationFrame(fn);
            });
        },
        scroll() {
            this.scheduleScroll(() => {
                const el = this.$refs.scroll;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        // Скрол до началото на балона. Alpine x-if рендира балона асинхронно — ResizeObserver + retry.
        scrollToMsg(uid) {
            const pad = 12;
            const el = () => this.$refs.scroll;

            const scrollBubble = (fallbackBottom = false) => {
                const box = el();
                if (!box) return false;
                if (fallbackBottom) {
                    box.scrollTop = box.scrollHeight;
                    return true;
                }
                const wrap = box.querySelector('[data-uid="' + uid + '"]');
                if (!wrap) return false;
                const bubble = wrap.querySelector('.msg-bot-bubble');
                const target = (bubble && bubble.offsetHeight > 0) ? bubble : wrap;
                if (target.offsetHeight < 1) return false;

                const top = box.scrollTop
                    + target.getBoundingClientRect().top
                    - box.getBoundingClientRect().top
                    - pad;
                box.scrollTop = Math.max(0, top);
                return true;
            };

            const run = (last = false) => {
                if (!scrollBubble(last)) scrollBubble(true);
            };

            this.$nextTick(() => {
                run();
                requestAnimationFrame(() => run());
                [120, 300, 500, 800, 1200].forEach(ms => setTimeout(() => run(ms === 1200), ms));

                const box = el();
                const wrap = box?.querySelector('[data-uid="' + uid + '"]');
                if (wrap && typeof ResizeObserver !== 'undefined') {
                    const ro = new ResizeObserver(() => scrollBubble(false));
                    ro.observe(wrap);
                    setTimeout(() => ro.disconnect(), 2500);
                }
            });
        },
        toggleOpt(msg, value) {
            if (msg.question.input_type === 'radio') { msg.selected = [value]; return; }
            const i = msg.selected.indexOf(value);
            i === -1 ? msg.selected.push(value) : msg.selected.splice(i, 1);
        },
        sendText() {
            const text = this.input.trim(); if (!text || this.thinking) return;
            this.pushUser(text); this.input = ''; this.thinking = true;
            this.scroll();
            this.dispatch({ message: text });
        },
        answer(msg) {
            const values = [...msg.selected]; const other = msg.otherChecked ? msg.other.trim() : '';
            if (values.length === 0 && !other) return;
            msg.answered = true;
            const labels = msg.question.options.filter(o => values.includes(o.value)).map(o => o.label);
            if (other) labels.push(other);
            const display = labels.join(', ');
            this.pushUser(display); this.thinking = true;
            this.scroll();
            this.dispatch({ answer: { key: msg.question.key, values, other }, display });
        },
        dispatch(body) {
            fetch(cfg.sendUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            }).then(r => r.json()).then(d => { if (d.token) this.poll(d.token); else { this.thinking = false; this.pushBot('Грешка. Опитай пак.', null, true); } })
              .catch(() => { this.thinking = false; this.pushBot('Грешка. Опитай пак.', null, true); });
        },
        poll(token) {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }   // никога не оставяй сирак-интервал
            const url = cfg.statusTpl.replace('TOKEN', token);
            let settled = false, fails = 0;
            const stop = () => { settled = true; if (this.timer) { clearInterval(this.timer); this.timer = null; } this.thinking = false; };
            const tick = async () => {
                if (settled) return;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (settled) return;                                          // друг tick вече приключи
                    if (d.status === 'pending') { this.stage = d.stage || 'Мисля…'; fails = 0; return; }
                    stop();
                    if (d.status === 'failed' || d.status === 'expired') { this.pushBot(d.error || 'Грешка.', null, true); return; }
                    this.pushBot(d.reply || (d.question && d.question.text) || '', d.question || null);   // въпросът носи текста
                    this.ready = d.phase === 'ready';
                } catch (e) {
                    if (++fails >= 8) { stop(); this.pushBot('Връзката се губи. Опитай пак.', null, true); }
                }
            };
            tick(); this.timer = setInterval(tick, 1600);
        },
    };
}
</script>
@endpush
@endsection
