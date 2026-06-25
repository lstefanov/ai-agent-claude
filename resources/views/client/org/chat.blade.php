@extends('layouts.client')

@section('title', 'Чат с ' . ($member->persona?->name ?? $member->display_name))

@push('head')<style>[x-cloak]{display:none !important}</style>@endpush

@section('content')
@php
    $persona = $member->persona;
    $charColors = ['purple', 'teal', 'coral', 'blue', 'amber', 'pink', 'green'];
    $c = $charColors[$member->id % count($charColors)];
    $initial = mb_substr($persona->name ?? $member->display_name, 0, 1);
@endphp
<div class="max-w-3xl mx-auto px-6 py-8"
     x-data="memberChat({
        chatId: {{ $chat->id }},
        sendUrl: '{{ route('client.org.chat.send') }}',
        statusTpl: '{{ route('client.org.chat.status', ['token' => 'TOKEN']) }}',
        csrf: '{{ csrf_token() }}',
        avatar: @js($persona?->hasReadyAvatar() ? $persona->avatar_url : null),
        initial: @js($initial),
        color: 'char-{{ $c }}',
     })" x-init="init()">
    <a href="{{ route('client.org.member', $member->id) }}" class="text-sm text-muted hover:text-ink">← Към героя</a>

    <div class="mt-3 flex items-center gap-3 mb-4">
        @if ($persona?->hasReadyAvatar())
            <img src="{{ $persona->avatar_url }}" alt="{{ $persona->name }}" class="h-11 w-11 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
        @else
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong font-semibold ring-2 ring-char-{{ $c }}-soft">{{ $initial }}</span>
        @endif
        <div>
            <h1 class="font-semibold text-ink">{{ $persona?->name ?? $member->display_name }}</h1>
            <p class="text-xs text-muted">{{ $member->display_name }}@if ($persona?->tone) · {{ $persona->tone }}@endif</p>
        </div>
    </div>

    <div class="rounded-xl border border-line bg-surface flex flex-col" style="height: 64vh">
        <div class="flex-1 overflow-y-auto p-4 space-y-3" x-ref="scroll">
            @foreach ($messages as $msg)
                <div class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="{{ $msg->role === 'user' ? 'bg-primary text-primary-fg rounded-2xl rounded-br-sm' : 'bg-surface-subtle text-ink rounded-2xl rounded-bl-sm' }} px-4 py-2 max-w-[85%]">
                        <p class="text-sm whitespace-pre-line">{{ $msg->content }}</p>
                        @if ($msg->role === 'assistant' && ($msg->payload['proposal'] ?? null))
                            <p class="text-xs text-char-{{ $c }}-strong mt-1">💡 Предложение → в <a href="{{ route('client.org.decisions') }}" class="underline">Кутията</a></p>
                        @endif
                    </div>
                </div>
            @endforeach

            <template x-for="msg in messages" :key="msg.uid">
                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    <div :class="msg.role === 'user' ? 'bg-primary text-primary-fg rounded-2xl rounded-br-sm' : (msg.failed ? 'bg-danger-soft text-danger-strong' : 'bg-surface-subtle text-ink') + ' rounded-2xl rounded-bl-sm'" class="px-4 py-2 max-w-[85%]">
                        <p class="text-sm whitespace-pre-line" x-text="msg.content"></p>
                        <template x-if="msg.proposal">
                            <p class="text-xs text-{{ 'char-'.$c }}-strong mt-1">💡 Предложение → в <a href="{{ route('client.org.decisions') }}" class="underline">Кутията</a></p>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="thinking">
                <div class="flex justify-start"><div class="bg-surface-subtle text-muted rounded-2xl rounded-bl-sm px-4 py-2 text-sm" x-text="stage || 'Мисля…'"></div></div>
            </template>
        </div>

        <div class="border-t border-line p-3">
            <form @submit.prevent="send()" class="flex items-end gap-2">
                <textarea x-model="input" rows="1" placeholder="Напиши на {{ $persona?->name ?? 'члена' }}…" @keydown.enter.prevent="send()"
                          class="flex-1 resize-none rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"></textarea>
                <x-button type="submit" size="sm" x-bind:disabled="!input.trim() || thinking">Изпрати</x-button>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function memberChat(cfg) {
    return {
        messages: [], input: '', thinking: false, stage: '', timer: null, uid: 0,
        init() { this.scroll(); },
        scroll() { this.$nextTick(() => { const el = this.$refs.scroll; if (el) el.scrollTop = el.scrollHeight; }); },
        send() {
            const text = this.input.trim(); if (!text || this.thinking) return;
            this.messages.push({ uid: ++this.uid, role: 'user', content: text, proposal: null, failed: false });
            this.input = ''; this.thinking = true; this.scroll();
            fetch(cfg.sendUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify({ chat_id: cfg.chatId, message: text }) })
                .then(r => r.json()).then(d => d.token ? this.poll(d.token) : this.fail()).catch(() => this.fail());
        },
        poll(token) {
            const url = cfg.statusTpl.replace('TOKEN', token);
            const tick = async () => {
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (d.status === 'pending') { this.stage = d.stage || 'Мисля…'; return; }
                    clearInterval(this.timer); this.thinking = false;
                    if (d.status === 'completed') { this.messages.push({ uid: ++this.uid, role: 'assistant', content: d.reply || '…', proposal: d.proposal, failed: false }); this.scroll(); }
                    else { this.messages.push({ uid: ++this.uid, role: 'assistant', content: d.error || 'Грешка.', proposal: null, failed: true }); }
                } catch (e) {}
            };
            tick(); this.timer = setInterval(tick, 1600);
        },
        fail() { this.thinking = false; this.messages.push({ uid: ++this.uid, role: 'assistant', content: 'Грешка. Опитай пак.', proposal: null, failed: true }); },
    };
}
</script>
@endpush
@endsection
