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
     })">
    <header class="mb-6">
        <h1 class="text-2xl font-semibold text-ink">Управителят предлага екип</h1>
        <p class="text-muted mt-1">Ревюирай персоните и задачите. Можеш да коригираш име/тон/черти. Одобрението създава организацията.</p>
    </header>

    {{-- Зареждане --}}
    <template x-if="loading">
        <div class="flex items-center gap-3 text-sm text-muted py-12">
            <x-org.bolt-spinner size="26" />
            <span x-text="stage || 'Управителят композира екипа…'"></span>
        </div>
    </template>
    <p x-show="error" x-text="error" class="text-sm text-danger py-6"></p>

    {{-- Предложение --}}
    <div x-show="!loading && design" x-cloak class="space-y-8">
        {{-- §3-part understanding: какво разбра Управителят (над екипа) --}}
        <template x-if="design && ((design.problems && design.problems.length) || (design.needs && design.needs.length) || (design.opportunities && design.opportunities.length))">
            <div class="grid md:grid-cols-3 gap-3">
                <template x-if="design.problems && design.problems.length">
                    <div class="rounded-xl border border-danger-soft bg-danger-soft/20 p-4">
                        <p class="text-sm font-semibold text-danger-strong mb-2">Проблеми за решаване</p>
                        <ul class="space-y-1">
                            <template x-for="(p, i) in design.problems" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(p)"></span></li></template>
                        </ul>
                    </div>
                </template>
                <template x-if="design.needs && design.needs.length">
                    <div class="rounded-xl border border-info-soft bg-info-soft/20 p-4">
                        <p class="text-sm font-semibold text-info-strong mb-2">Нужди на бизнеса</p>
                        <ul class="space-y-1">
                            <template x-for="(n, i) in design.needs" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(n)"></span></li></template>
                        </ul>
                    </div>
                </template>
                <template x-if="design.opportunities && design.opportunities.length">
                    <div class="rounded-xl border border-success-soft bg-success-soft/20 p-4">
                        <p class="text-sm font-semibold text-success-strong mb-2">Предложени възможности за растеж</p>
                        <ul class="space-y-1">
                            <template x-for="(o, i) in design.opportunities" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(o)"></span></li></template>
                        </ul>
                    </div>
                </template>
            </div>
        </template>

        {{-- Приоритети от болките --}}
        <template x-if="design && design.priorities && design.priorities.length">
            <div class="rounded-xl border border-warning-soft bg-warning-soft/30 p-4">
                <p class="text-sm font-semibold text-warning-strong mb-2">Препоръчани приоритети</p>
                <ul class="space-y-1">
                    <template x-for="(q, i) in design.priorities" :key="i">
                        <li class="text-sm text-ink">• <span x-html="$mdInline(q.title)"></span>
                            <span class="text-xs text-muted" x-show="q.rationale" x-html="'— ' + $mdInline(q.rationale)"></span></li>
                    </template>
                </ul>
            </div>
        </template>

        {{-- Директори + асистенти (редактируеми персони — помощ/AI/черти) --}}
        <template x-for="(dir, di) in (design ? design.directors : [])" :key="dir.key">
            <div class="rounded-xl border border-line bg-surface-subtle/40 p-4">
                <div class="grid lg:grid-cols-[340px_1fr] gap-5">
                    <div>
                        <p class="text-[11px] font-mono uppercase tracking-wider mb-1" x-text="dir.domain"
                           :style="'color: var(--color-char-' + ((dir.persona &amp;&amp; dir.persona.color) || 'blue') + '-strong)'"></p>
                        <div x-data="personaEditor(dir.persona, 'Директор ' + (dir.title || dir.domain || ''))"
                             class="rounded-xl border border-line bg-surface p-4 space-y-4">
                            <p class="text-xs font-medium text-muted" x-text="dir.title"></p>
                            @include('client.org._persona-fields', ['modelPrefix' => 'persona'])
                        </div>
                    </div>
                    <div>
                        <p class="text-xs text-muted mb-2" x-text="assistantsFor(dir.key).length + ' асистенти'"></p>
                        <div class="grid xl:grid-cols-2 gap-3">
                            <template x-for="a in assistantsFor(dir.key)" :key="a.key">
                                <div x-data="personaEditor(a.persona, 'Асистент ' + (a.title || ''))"
                                     class="rounded-xl border border-line bg-surface p-3 space-y-3">
                                    <p class="text-xs font-medium text-muted" x-text="a.title"></p>
                                    @include('client.org._persona-fields', ['modelPrefix' => 'persona'])
                                </div>
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
// Цвят = домейн на отдела (огледало на OrgMember::functionColor / config function_colors).
const FUNCTION_COLORS = @js(config('organization.function_colors'));
const DEFAULT_FN_COLOR = @js(config('organization.default_function_color', 'blue'));
function colorForDomain(domain) {
    domain = (domain || '').toString().toLowerCase();
    for (const needle in FUNCTION_COLORS) {
        if (domain && domain.includes(needle.toLowerCase())) return FUNCTION_COLORS[needle];
    }
    return DEFAULT_FN_COLOR;
}
function design(cfg) {
    return {
        loading: true, stage: '', error: '', design: null, timer: null, approving: false, approveMsg: '', started: false,
        init() {
            if (this.started) return;   // Alpine може да извика init() повече от веднъж — без двоен propose.
            this.started = true;
            fetch(cfg.proposeUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => d.token ? this.poll(d.token) : this.fail())
                .catch(() => this.fail());
        },
        poll(token) {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }   // никога не оставяй сирак-интервал (трие edit-ите)
            const url = cfg.statusTpl.replace('TOKEN', token);
            let settled = false, fails = 0;
            const stop = () => { settled = true; if (this.timer) { clearInterval(this.timer); this.timer = null; } this.loading = false; };
            const tick = async () => {
                if (settled) return;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (settled) return;
                    if (d.status === 'pending') { this.stage = d.stage || 'Композирам…'; fails = 0; return; }
                    stop();
                    if (d.status === 'completed' && d.design) { this.design = this.normalize(d.design); }
                    else { this.fail(d.error); }
                } catch (e) { if (++fails >= 8) { stop(); this.fail(); } }
            };
            tick(); this.timer = setInterval(tick, 2500);
        },
        assistantsFor(dirKey) { return (this.design.assistants || []).filter(a => a.director === dirKey); },
        // Гарантира persona обект + всичките 5 черти (LLM понякога пропуска autonomy).
        normalize(design) {
            const def = { risk: 50, creativity: 50, precision: 50, autonomy: 60, tempo: 55 };
            const fix = (m) => { m.persona = m.persona || {}; m.persona.traits = Object.assign({}, def, m.persona.traits || {}); };
            (design.directors || []).forEach(fix);
            (design.assistants || []).forEach(fix);
            // Цвят на чертите по отдел: директор по своя домейн, асистент по домейна на директора му.
            (design.directors || []).forEach(d => { d.persona.color = colorForDomain(d.domain); });
            (design.assistants || []).forEach(a => {
                const dir = (design.directors || []).find(x => x.key === a.director);
                a.persona.color = colorForDomain(dir ? dir.domain : a.director);
            });
            return design;
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

// Под-компонент за редактируема персона-карта: bind-ва партиала към persona обекта
// (жива референция в this.design) + company-scoped ✨ AI-fill.
window.personaEditor = (persona, role) => ({
    ...window.personaFormBase({ suggestUrl: '{{ route('client.org.personas.suggest-field') }}', csrf: '{{ csrf_token() }}', role }),
    persona,
    aiRole() { return role; },
    aiContext() { return this.persona; },
    aiApply(field, value) { this.persona[field] = value; },
});
</script>
@endpush
@endsection
