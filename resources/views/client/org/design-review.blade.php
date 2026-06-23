@extends('layouts.client')

@section('title', 'Дизайн на екипа')

@push('head')<style>[x-cloak]{display:none !important}</style>@endpush

@section('content')
<div class="max-w-6xl mx-auto px-6 py-8"
     x-data="design({
        proposeUrl: '{{ route('client.org.design.propose') }}',
        statusTpl: '{{ route('client.org.design.status', ['token' => 'TOKEN']) }}',
        approveUrl: '{{ route('client.org.design.approve') }}',
        csrf: '{{ csrf_token() }}',
     })" x-init="init()">
    <header class="mb-6">
        <h1 class="text-2xl font-semibold text-ink">Управителят предлага екип</h1>
        <p class="text-muted mt-1">Ревюирай персоните и задачите. Можеш да коригираш име/тон/черти. Одобрението създава организацията.</p>
    </header>

    {{-- Зареждане --}}
    <template x-if="loading">
        <div class="flex items-center gap-3 text-sm text-muted py-12">
            <span class="h-2 w-2 rounded-full bg-accent animate-pulse"></span>
            <span x-text="stage || 'Управителят композира екипа…'"></span>
        </div>
    </template>
    <p x-show="error" x-text="error" class="text-sm text-danger py-6"></p>

    {{-- Предложение --}}
    <div x-show="!loading && design" x-cloak class="space-y-8">
        {{-- Куестове от болките --}}
        <template x-if="design && design.quests && design.quests.length">
            <div class="rounded-xl border border-warning-soft bg-warning-soft/30 p-4">
                <p class="text-sm font-semibold text-warning-strong mb-2">Препоръчани куестове</p>
                <ul class="space-y-1">
                    <template x-for="(q, i) in design.quests" :key="i">
                        <li class="text-sm text-ink">• <span x-text="q.title"></span>
                            <span class="text-xs text-muted" x-show="q.rationale" x-text="'— ' + q.rationale"></span></li>
                    </template>
                </ul>
            </div>
        </template>

        {{-- Директори + асистенти (редактируеми персони) --}}
        <template x-for="(dir, di) in (design ? design.directors : [])" :key="dir.key">
            <div class="rounded-xl border border-line bg-surface-subtle/40 p-4">
                <div class="grid lg:grid-cols-[320px_1fr] gap-5">
                    <div x-html="''">
                        <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1" x-text="dir.domain"></p>
                        <div x-html="personaCard(dir, 'char-purple')"></div>
                    </div>
                    <div>
                        <p class="text-xs text-muted mb-2" x-text="assistantsFor(dir.key).length + ' асистенти'"></p>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <template x-for="a in assistantsFor(dir.key)" :key="a.key">
                                <div x-html="personaCard(a, 'char-teal')"></div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div class="flex items-center justify-end gap-3 pt-2">
            <p x-show="approveMsg" x-text="approveMsg" class="text-sm text-muted mr-auto"></p>
            <x-button x-on:click="approve()" x-bind:disabled="approving">
                <span x-text="approving ? 'Създавам организацията…' : 'Одобри и създай екипа'"></span>
            </x-button>
        </div>
    </div>
</div>

@push('scripts')
<script>
function design(cfg) {
    return {
        loading: true, stage: '', error: '', design: null, timer: null, approving: false, approveMsg: '',
        init() {
            fetch(cfg.proposeUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => d.token ? this.poll(d.token) : this.fail())
                .catch(() => this.fail());
        },
        poll(token) {
            const url = cfg.statusTpl.replace('TOKEN', token);
            const tick = async () => {
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (d.status === 'pending') { this.stage = d.stage || 'Композирам…'; return; }
                    clearInterval(this.timer); this.loading = false;
                    if (d.status === 'completed' && d.design) { this.design = d.design; }
                    else { this.fail(d.error); }
                } catch (e) {}
            };
            tick(); this.timer = setInterval(tick, 2500);
        },
        assistantsFor(dirKey) { return (this.design.assistants || []).filter(a => a.director === dirKey); },
        // Лека редактируема карта (име/тон/черти) — данните са в this.design, мутират се директно.
        personaCard(m, color) {
            const p = m.persona || {};
            const traits = p.traits || {};
            const bars = ['risk', 'creativity', 'precision', 'tempo'].filter(k => k in traits).map(k =>
                `<div><div class="flex justify-between text-[10px] text-muted"><span>${k}</span><span class="tabular-nums">${traits[k]}</span></div>
                 <div class="h-1 rounded-full bg-surface-subtle overflow-hidden"><div class="h-full rounded-full bg-${color}" style="width:${traits[k]}%"></div></div></div>`).join('');
            return `<div class="rounded-xl border border-line bg-surface p-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-${color}-soft text-${color}-strong font-semibold">${(p.name||'?')[0]}</span>
                    <div class="min-w-0"><p class="font-medium text-ink text-sm truncate">${p.name||''}</p>
                    <p class="text-xs text-muted truncate">${m.title||''}${p.age? ' · '+p.age+'г.':''}</p></div>
                </div>
                <p class="text-xs text-subtle mt-1 truncate">${p.tone||''}</p>
                <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">${bars}</div>
            </div>`;
        },
        approve() {
            if (!this.design) return;
            this.approving = true; this.approveMsg = '';
            fetch(cfg.approveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify({ design: this.design }) })
                .then(r => r.json()).then(d => { if (d.ok && d.redirect) window.location = d.redirect; else { this.approving = false; this.approveMsg = d.error || 'Грешка.'; } })
                .catch(() => { this.approving = false; this.approveMsg = 'Грешка при одобрение.'; });
        },
        fail(msg) { this.loading = false; this.error = msg || 'Дизайнът се провали. Опитай пак.'; },
    };
}
</script>
@endpush
@endsection
