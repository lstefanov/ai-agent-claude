@extends('layouts.client')

@section('title', 'Интервю с Управителя')

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .iv-typing span { display:inline-block; width:5px; height:5px; border-radius:9999px; background: var(--color-muted); animation: ivBlink 1.2s infinite both; }
    .iv-typing span:nth-child(2){ animation-delay:.2s } .iv-typing span:nth-child(3){ animation-delay:.4s }
    @keyframes ivBlink { 0%,80%,100%{ opacity:.3 } 40%{ opacity:1 } }
</style>
@endpush

@section('content')
@php($persona = $manager->persona)
<div class="max-w-3xl mx-auto px-6 py-8"
     x-data="interview({
        sendUrl: '{{ route('client.org.interview.send') }}',
        statusTpl: '{{ route('client.org.interview.status', ['token' => 'TOKEN']) }}',
        ready: {{ $profile->status === 'ready' ? 'true' : 'false' }},
     })" x-init="init()">
    <header class="mb-6">
        <p class="text-xs font-mono uppercase tracking-wider text-muted mb-1">Стъпка 3 от 3 · Интервю</p>
        <h1 class="text-2xl font-semibold text-ink">{{ $persona?->name ?? 'Управителят' }} иска да разбере бизнеса</h1>
        <p class="text-muted mt-1">Няколко въпроса, за да си състави ясна представа преди да проектира екипа.</p>
    </header>

    <div class="rounded-xl border border-line bg-surface flex flex-col" style="height: 68vh">
        {{-- Транскрипт --}}
        <div class="flex-1 overflow-y-auto p-4 space-y-3" x-ref="scroll">
            <template x-for="msg in messages" :key="msg.uid">
                <div>
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <div :class="msg.role === 'user'
                            ? 'bg-primary text-primary-fg rounded-2xl rounded-br-sm px-4 py-2 max-w-[85%]'
                            : (msg.failed ? 'bg-danger-soft text-danger-strong rounded-2xl rounded-bl-sm px-4 py-2 max-w-[90%]' : 'bg-surface-subtle text-ink rounded-2xl rounded-bl-sm px-4 py-2 max-w-[90%]')">
                            <p class="text-sm whitespace-pre-line" x-text="msg.content"></p>
                        </div>
                    </div>

                    {{-- Въпрос с готови опции + „Друго" --}}
                    <template x-if="msg.question && !msg.answered">
                        <div class="mt-2 ml-1 border border-line rounded-xl p-3 space-y-2 bg-surface">
                            <p class="text-sm font-medium text-ink" x-text="msg.question.text"></p>
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
                            <x-button size="sm" x-on:click="answer(msg)"
                                      x-bind:disabled="msg.selected.length === 0 && !(msg.otherChecked && msg.other.trim())">Изпрати отговор</x-button>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Индикатор „мисля" --}}
            <template x-if="thinking">
                <div class="flex justify-start">
                    <div class="bg-surface-subtle text-muted rounded-2xl rounded-bl-sm px-4 py-2 text-sm inline-flex items-center gap-2">
                        <span x-text="(stage || 'Мисля').replace(/[.…\s]+$/, '')"></span>
                        <span class="iv-typing"><span></span><span></span><span></span></span>
                    </div>
                </div>
            </template>
        </div>

        {{-- Готово --}}
        <template x-if="ready">
            <div class="border-t border-line p-4 flex items-center justify-between gap-3" x-cloak>
                <p class="text-sm text-success-strong">✓ Управителят има ясна представа.</p>
                <x-button :href="route('client.dashboard')">Готово — продължи</x-button>
            </div>
        </template>

        {{-- Свободен вход --}}
        <template x-if="!ready">
            <div class="border-t border-line p-3">
                <form @submit.prevent="sendText()" class="flex items-end gap-2">
                    <textarea x-model="input" rows="1" placeholder="Напиши свободно…" @keydown.enter.prevent="sendText()"
                              class="flex-1 resize-none rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"></textarea>
                    <x-button type="submit" size="sm" x-bind:disabled="!input.trim() || thinking">Изпрати</x-button>
                </form>
            </div>
        </template>
    </div>
</div>

@push('scripts')
<script>
function interview(cfg) {
    return {
        messages: [], input: '', thinking: false, stage: '', ready: cfg.ready, timer: null, uid: 0,
        init() {
            if (this.ready) {
                this.pushBot('Имам ясна представа за бизнеса. Готови сме за дизайна на екипа.');
                return;
            }
            // Авто-старт: празен ход → първият въпрос.
            this.thinking = true;
            this.dispatch({});
        },
        mkMsg(role, content, question = null) {
            return { uid: ++this.uid, role, content, question, answered: false, failed: false,
                     selected: [], otherChecked: false, other: '' };
        },
        pushBot(content, question = null, failed = false) {
            const m = this.mkMsg('assistant', content, question); m.failed = failed; this.messages.push(m); this.scroll();
        },
        pushUser(content) { this.messages.push(this.mkMsg('user', content)); this.scroll(); },
        scroll() { this.$nextTick(() => { const el = this.$refs.scroll; if (el) el.scrollTop = el.scrollHeight; }); },
        toggleOpt(msg, value) {
            if (msg.question.input_type === 'radio') { msg.selected = [value]; return; }
            const i = msg.selected.indexOf(value);
            i === -1 ? msg.selected.push(value) : msg.selected.splice(i, 1);
        },
        sendText() {
            const text = this.input.trim(); if (!text || this.thinking) return;
            this.pushUser(text); this.input = ''; this.thinking = true;
            this.dispatch({ message: text });
        },
        answer(msg) {
            const values = [...msg.selected]; const other = msg.otherChecked ? msg.other.trim() : '';
            if (values.length === 0 && !other) return;
            msg.answered = true;
            const labels = msg.question.options.filter(o => values.includes(o.value)).map(o => o.label);
            if (other) labels.push(other);
            this.pushUser(labels.join(', ')); this.thinking = true;
            this.dispatch({ answer: { key: msg.question.key, values, other } });
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
            const url = cfg.statusTpl.replace('TOKEN', token);
            const tick = async () => {
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (d.status === 'pending') { this.stage = d.stage || 'Мисля…'; return; }
                    clearInterval(this.timer); this.thinking = false;
                    if (d.status === 'failed' || d.status === 'expired') { this.pushBot(d.error || 'Грешка.', null, true); return; }
                    this.pushBot(d.reply || '…', d.question || null);
                    if (d.phase === 'ready') this.ready = true;
                } catch (e) { /* retry */ }
            };
            tick(); this.timer = setInterval(tick, 1600);
        },
    };
}
</script>
@endpush
@endsection
