@extends('layouts.client')

@section('title', 'Чат с ' . ($member->persona?->name ?? $member->display_name))

@push('head')<style>[x-cloak]{display:none !important}</style>@endpush

@section('content')
@php
    $persona = $member->persona;
    $c = $member->functionColor();   // цвят = функция/домейн (§10.1), не id % 7
    $initial = mb_strtoupper(mb_substr($member->fullName(), 0, 1));
@endphp
<div class="max-w-3xl mx-auto px-6 py-8"
     x-data="memberChat({
        chatId: {{ $chat->id }},
        sendUrl: '{{ route('client.org.chat.send') }}',
        statusTpl: '{{ route('client.org.chat.status', ['token' => 'TOKEN']) }}',
        approveUrl: '{{ route('client.org.decisions.approve') }}',
        rejectUrl: '{{ route('client.org.decisions.reject') }}',
        decisionsUrl: '{{ route('client.org.decisions') }}',
        csrf: '{{ csrf_token() }}',
        avatar: @js($persona?->hasReadyAvatar() ? $persona->avatar_url : null),
        initial: @js($initial),
        color: 'char-{{ $c }}',
     })" x-init="init()">
    <a href="{{ route('client.org.member', $member->id) }}" class="text-sm text-muted hover:text-ink">← Към служителя</a>

    <div class="mt-3 flex items-center gap-3 mb-4">
        @if ($persona?->hasReadyAvatar())
            <img src="{{ $persona->avatar_url }}" alt="{{ $persona->name }}" class="h-11 w-11 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
        @else
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong font-semibold ring-2 ring-char-{{ $c }}-soft">{{ $initial }}</span>
        @endif
        <div>
            <h1 class="font-semibold text-ink">{{ $persona?->name ?? $member->display_name }}</h1>
            <p class="text-xs text-muted">{{ $member->display_name }}@if ($persona?->tone) · <x-prose :text="$persona->tone" inline />@endif</p>
        </div>
    </div>

    <div class="rounded-xl border border-line bg-surface flex flex-col" style="height: 64vh">
        <div class="flex-1 overflow-y-auto p-4 space-y-3" x-ref="scroll">
            @foreach ($messages as $msg)
                @php
                    $pr = $msg->payload['proposal'] ?? null;
                    $prStatus = is_array($pr) && ! empty($pr['id'])
                        ? ($proposalStatuses[$pr['id']] ?? null)
                        : null;
                @endphp
                <div class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="{{ $msg->role === 'user' ? 'bg-primary text-primary-fg rounded-2xl rounded-br-sm' : 'bg-surface-subtle text-ink rounded-2xl rounded-bl-sm' }} px-4 py-2 max-w-[85%]">
                        @if ($msg->role === 'assistant')
                            <x-prose :text="$msg->content" class="text-sm" />
                        @else
                            <p class="text-sm whitespace-pre-line">{{ $msg->content }}</p>
                        @endif
                        @if ($msg->role === 'assistant' && is_array($pr) && ($pr['kind'] ?? null) === 'proposal' && ! empty($pr['id']))
                            @include('client.org._chat-proposal-card', ['pr' => $pr, 'prStatus' => $prStatus, 'live' => false])
                        @elseif ($msg->role === 'assistant' && is_array($pr))
                            <p class="text-xs text-char-{{ $c }}-strong mt-1">💡 Предложение → в <a href="{{ route('client.org.decisions') }}" class="underline">Предложения</a></p>
                        @endif
                    </div>
                </div>
            @endforeach

            <template x-for="msg in messages" :key="msg.uid">
                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    <div :class="msg.role === 'user' ? 'bg-primary text-primary-fg rounded-2xl rounded-br-sm' : (msg.failed ? 'bg-danger-soft text-danger-strong' : 'bg-surface-subtle text-ink') + ' rounded-2xl rounded-bl-sm'" class="px-4 py-2 max-w-[85%]">
                        <template x-if="msg.role === 'assistant'">
                            <div class="text-sm ai-prose" x-html="$md(msg.content)"></div>
                        </template>
                        <template x-if="msg.role !== 'assistant'">
                            <p class="text-sm whitespace-pre-line" x-text="msg.content"></p>
                        </template>
                        <template x-if="msg.proposal && msg.proposal.kind === 'proposal' && msg.proposal.id">
                            <div class="mt-2 rounded-lg border border-line bg-surface p-3">
                                <p class="text-xs font-mono uppercase tracking-wider text-subtle">Предложение</p>
                                <p class="mt-1 text-sm font-medium text-ink" x-text="msg.proposal.title"></p>
                                <p class="mt-1 text-xs text-muted line-clamp-3" x-show="msg.proposal.description" x-text="msg.proposal.description"></p>
                                <div class="mt-2 flex flex-wrap items-center gap-2" x-show="msg.proposalStatus === 'pending'">
                                    <button type="button" class="text-xs font-medium text-danger hover:text-danger-strong"
                                            x-on:click="rejectProposal(msg.proposal.id, msg)">Откази</button>
                                    <button type="button" class="text-xs font-medium text-primary hover:text-primary-hover"
                                            x-on:click="approveProposal(msg.proposal.id, false, msg)">Одобри</button>
                                    <button type="button" class="inline-flex h-8 items-center justify-center rounded-md bg-primary px-3 text-xs font-medium text-primary-fg hover:bg-primary-hover"
                                            x-on:click="approveProposal(msg.proposal.id, true, msg)">Одобри и пусни</button>
                                </div>
                                <p class="mt-1 text-xs text-success-strong" x-show="msg.proposalStatus === 'approved'" x-cloak>Одобрено.</p>
                                <p class="mt-1 text-xs text-muted" x-show="msg.proposalStatus === 'rejected'" x-cloak>Отхвърлено.</p>
                            </div>
                        </template>
                        <template x-if="msg.proposal && (!msg.proposal.kind || !msg.proposal.id)">
                            <p class="text-xs text-char-{{ $c }}-strong mt-1">💡 Предложение → в <a href="{{ route('client.org.decisions') }}" class="underline">Предложения</a></p>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="thinking">
                <x-org.thinking />
            </template>
        </div>

        <div class="border-t border-line p-3">
            <form @submit.prevent="send()" class="flex items-end gap-2">
                <textarea x-model="input" rows="1" placeholder="Напиши на {{ $persona?->name ?? 'члена' }}…" @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                          class="flex-1 resize-none rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"></textarea>
                <x-button type="submit" size="sm" x-bind:disabled="!input.trim() || thinking">Изпрати</x-button>
            </form>
        </div>
    </div>

    <div x-show="success" x-cloak class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-line bg-surface px-4 py-3 text-sm shadow-popover">
        <p class="font-medium text-ink" x-text="success"></p>
        <button type="button" class="mt-1 text-xs text-subtle hover:text-ink" x-on:click="success = null">Затвори</button>
    </div>
    <div x-show="error" x-cloak class="fixed bottom-4 right-4 z-50 rounded-lg bg-danger-soft text-danger-strong px-4 py-2 text-sm shadow-card" x-text="error"></div>
</div>

@push('scripts')
<script>
function memberChat(cfg) {
    return {
        messages: [], input: '', thinking: false, stage: '', timer: null, uid: 0, success: null, error: '',
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
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
            const url = cfg.statusTpl.replace('TOKEN', token);
            let settled = false, fails = 0;
            const stop = () => { settled = true; if (this.timer) { clearInterval(this.timer); this.timer = null; } this.thinking = false; };
            const tick = async () => {
                if (settled) return;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (settled) return;
                    if (d.status === 'pending') { this.stage = d.stage || 'Мисля…'; fails = 0; return; }
                    stop();
                    if (d.status === 'completed') {
                        this.messages.push({
                            uid: ++this.uid, role: 'assistant', content: d.reply || '…',
                            proposal: d.proposal, proposalStatus: d.proposal ? 'pending' : null, failed: false,
                        });
                        this.scroll();
                    } else {
                        this.messages.push({ uid: ++this.uid, role: 'assistant', content: d.error || 'Грешка.', proposal: null, failed: true });
                    }
                } catch (e) { if (++fails >= 8) { stop(); this.messages.push({ uid: ++this.uid, role: 'assistant', content: 'Връзката се губи. Опитай пак.', proposal: null, failed: true }); } }
            };
            tick(); this.timer = setInterval(tick, 1600);
        },
        fail() { this.thinking = false; this.messages.push({ uid: ++this.uid, role: 'assistant', content: 'Грешка. Опитай пак.', proposal: null, failed: true }); },
        async _post(url, body) {
            this.error = '';
            try {
                const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) });
                const d = await r.json().catch(() => ({}));
                if (!r.ok || !d.ok) {
                    if (d.superseded) { alert(d.error || 'Организацията се промени — нужно е ре-ревю.'); location.reload(); return null; }
                    this.error = d.message || d.error || 'Грешка.'; return null;
                }
                return d;
            } catch (e) { this.error = 'Мрежова грешка.'; return null; }
        },
        async approveProposal(id, run, msg) {
            const d = await this._post(cfg.approveUrl, { kind: 'proposal', id, run: !!run });
            if (d) {
                if (msg) msg.proposalStatus = 'approved';
                this.success = d.message || 'Одобрено.';
                return true;
            }
            return false;
        },
        async rejectProposal(id, msg) {
            if (!confirm('Отхвърляне на предложението?')) return false;
            const d = await this._post(cfg.rejectUrl, { kind: 'proposal', id });
            if (d) {
                if (msg) msg.proposalStatus = 'rejected';
                this.success = 'Отхвърлено.';
                return true;
            }
            return false;
        },
    };
}
</script>
@endpush
@endsection
