@extends('layouts.client')

@section('title', 'Проучване на бизнеса')

@section('content')
@php($persona = $manager->persona)
<div class="max-w-3xl mx-auto px-6 py-8"
     x-data="research({
        startUrl: '{{ route('client.org.research.start') }}',
        statusTpl: '{{ route('client.org.research.status', ['token' => 'TOKEN']) }}',
        interviewUrl: '{{ route('client.org.interview') }}',
        done: {{ $profile && in_array($profile->status, ['interviewing', 'ready'], true) ? 'true' : 'false' }},
        researching: {{ $profile && $profile->status === 'researching' ? 'true' : 'false' }},
        analysis: @js(optional($profile)->situational_analysis ?? ''),
        research: @js(optional($profile)->research ?? []),
     })">
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <p class="text-xs font-mono uppercase tracking-wider text-muted mb-1">Стъпка 2 от 3 · Проучване</p>
            <h1 class="text-2xl font-semibold text-ink">{{ $manager->persona?->name ?? 'Управителят' }} проучва бизнеса</h1>
            <p class="text-muted mt-1">Сайт, отзиви и добри практики в бранша — за да си състави ясна представа преди дизайна.</p>
        </div>
        @include('client.org._wizard-reset')
    </header>

    <div class="rounded-xl border border-line bg-surface p-6">
        <div class="flex items-center gap-4 mb-5">
            @if ($persona?->hasReadyAvatar())
                <img src="{{ $persona->avatar_url }}" alt="{{ $persona->name }}" class="h-14 w-14 rounded-full object-cover ring-1 ring-line">
            @else
                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-char-blue-soft text-char-blue-strong text-lg font-semibold">
                    {{ mb_substr($manager->persona?->name ?? 'У', 0, 1) }}</span>
            @endif
            <div>
                <p class="font-medium text-ink">{{ $manager->persona?->name ?? 'Управител' }}</p>
                <p class="text-sm text-muted"><x-prose :text="$manager->persona?->tone" inline /></p>
            </div>
        </div>

        {{-- Старт / прогрес --}}
        <div x-show="!done">
            <x-org.busy-button x-show="!running" busy="running" loading-text="Изпълнява се…" :spinner="false" x-on:click="start()">Стартирай проучването</x-org.busy-button>
            <p x-show="running" x-cloak class="mt-3 flex items-center gap-2 text-sm text-muted">
                <x-org.bolt-spinner :size="16" />
                <span x-text="stage || 'Проучвам…'"></span>
            </p>
            <p x-show="error" x-text="error" class="text-sm text-danger mt-3"></p>
        </div>

        {{-- Резултат --}}
        <div x-show="done" x-cloak>
            <div class="rounded-lg bg-surface-subtle p-4 text-sm text-ink leading-relaxed ai-prose"
                 x-html="$md(analysis || @js(optional($profile)->situational_analysis) || 'Анализът е готов.')"></div>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <template x-if="arr(research.suggested_areas).length">
                    <div class="rounded-lg border border-line p-3">
                        <p class="text-xs font-medium text-muted mb-2">Вероятни фокуси</p>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="area in arr(research.suggested_areas).slice(0, 6)" :key="area.domain || area.label">
                                <span class="rounded-md bg-char-blue-soft px-2 py-1 text-xs text-char-blue-strong" x-text="area.label || area.domain"></span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <template x-if="arr(research.evidence).length">
                <p class="mt-3 text-xs text-muted">
                    <span x-text="arr(research.evidence).length"></span>
                    <span> публични сигнала са използвани за първоначалните хипотези.</span>
                </p>
            </template>

            <div class="flex justify-end mt-5">
                <x-button :href="route('client.org.interview')">Към интервюто →</x-button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function research(cfg) {
    return {
        running: false, done: cfg.done, stage: cfg.researching ? 'Проучването вече е стартирано. Ако не се обновява, стартирай отново.' : '', error: '', analysis: cfg.analysis || '', research: cfg.research || {}, timer: null, started: false,
        init() {
            if (!this.done && !cfg.researching) this.start();
        },
        arr(value) {
            return Array.isArray(value) ? value : [];
        },
        start() {
            if (this.running) return;
            this.started = true;
            this.running = true; this.error = '';
            fetch(cfg.startUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => { if (d.token) this.poll(d.token); else this.fail(); })
                .catch(() => this.fail());
        },
        poll(token) {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }   // никога не оставяй сирак-интервал
            const url = cfg.statusTpl.replace('TOKEN', token);
            let settled = false, fails = 0;
            const stop = () => { settled = true; if (this.timer) { clearInterval(this.timer); this.timer = null; } this.running = false; };
            const tick = async () => {
                if (settled) return;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (settled) return;
                    if (d.status === 'pending') { this.stage = d.stage || 'Проучвам…'; fails = 0; return; }
                    stop();
                    if (d.status === 'completed') { this.analysis = d.analysis || ''; this.research = d.research || this.research || {}; this.done = true; }
                    else { this.fail(d.error); }
                } catch (e) { if (++fails >= 8) { stop(); this.fail(); } }
            };
            tick(); this.timer = setInterval(tick, 2000);
        },
        fail(msg) { this.running = false; this.error = msg || 'Проучването се провали. Опитай пак.'; },
    };
}
</script>
@endpush
@endsection
