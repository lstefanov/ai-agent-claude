@extends('layouts.client')

@section('title', 'Анализ на бизнеса')

@push('head')<style>[x-cloak]{display:none !important}</style>@endpush

@section('content')
<div class="max-w-3xl mx-auto px-6 py-8"
     x-data="analysis({
        runUrl: '{{ route('client.org.analysis.run') }}',
        statusTpl: '{{ route('client.org.analysis.status', ['token' => 'TOKEN']) }}',
        csrf: '{{ csrf_token() }}',
        ready: {{ $profile->synthesis_completed_at ? 'true' : 'false' }},
        problems: @js((array) $profile->problems),
        needs: @js((array) $profile->needs),
        opportunities: @js((array) $profile->opportunities),
     })" x-init="init()">
    <header class="mb-6">
        <p class="text-xs font-mono uppercase tracking-wider text-muted mb-1">Стъпка · Анализ преди екипа</p>
        <h1 class="text-2xl font-semibold text-ink">Ето какво разбрах за бизнеса</h1>
        <p class="text-muted mt-1">Проблемите и нуждите ви, и няколко възможности за растеж — на тяхна база ще сглобя екипа.</p>
    </header>

    {{-- Зареждане --}}
    <template x-if="!ready">
        <div class="flex items-center gap-3 text-sm text-muted py-12">
            <x-org.bolt-spinner size="26" />
            <span x-text="stage || 'Анализирам…'"></span>
        </div>
    </template>
    <p x-show="error" x-text="error" class="text-sm text-danger py-6"></p>

    {{-- Изводи --}}
    <div x-show="ready" x-cloak class="space-y-4">
        <template x-if="problems.length">
            <div class="rounded-xl border border-danger-soft bg-danger-soft/20 p-4">
                <p class="text-sm font-semibold text-danger-strong mb-2">Проблеми за решаване</p>
                <ul class="space-y-1"><template x-for="(p, i) in problems" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(p)"></span></li></template></ul>
            </div>
        </template>
        <template x-if="needs.length">
            <div class="rounded-xl border border-info-soft bg-info-soft/20 p-4">
                <p class="text-sm font-semibold text-info-strong mb-2">Нужди на бизнеса</p>
                <ul class="space-y-1"><template x-for="(n, i) in needs" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(n)"></span></li></template></ul>
            </div>
        </template>
        <template x-if="opportunities.length">
            <div class="rounded-xl border border-success-soft bg-success-soft/20 p-4">
                <p class="text-sm font-semibold text-success-strong mb-2">Предложени възможности за растеж</p>
                <ul class="space-y-1"><template x-for="(o, i) in opportunities" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(o)"></span></li></template></ul>
            </div>
        </template>

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('client.org.interview') }}" class="text-sm text-muted hover:text-ink">← Назад към интервюто</a>
            <x-button :href="route('client.org.design.review')">Проектирай екипа →</x-button>
        </div>
    </div>
</div>

@push('scripts')
<script>
function analysis(cfg) {
    return {
        ready: cfg.ready, stage: '', error: '', timer: null, started: false,
        problems: cfg.problems || [], needs: cfg.needs || [], opportunities: cfg.opportunities || [],
        init() {
            if (this.started) return; this.started = true;
            if (this.ready) return;          // вече синтезирано (server-rendered)
            this.run();
        },
        run() {
            fetch(cfg.runUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => {
                    if (d.done) { this.apply(d); return; }
                    if (d.token) this.poll(d.token); else this.fail();
                }).catch(() => this.fail());
        },
        poll(token) {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
            const url = cfg.statusTpl.replace('TOKEN', token);
            let settled = false, fails = 0;
            const stop = () => { settled = true; if (this.timer) { clearInterval(this.timer); this.timer = null; } };
            const tick = async () => {
                if (settled) return;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (settled) return;
                    if (d.status === 'pending') { this.stage = d.stage || 'Анализирам…'; fails = 0; return; }
                    stop();
                    if (d.status === 'completed') this.apply(d); else this.fail(d.error);
                } catch (e) { if (++fails >= 8) { stop(); this.fail(); } }
            };
            tick(); this.timer = setInterval(tick, 2000);
        },
        apply(d) { this.problems = d.problems || []; this.needs = d.needs || []; this.opportunities = d.opportunities || []; this.ready = true; },
        fail(msg) { this.error = msg || 'Анализът се провали. Презареди, за да опитам пак.'; },
    };
}
</script>
@endpush
@endsection
