@extends('layouts.client')

@section('title', 'Резултат')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<style>
    .result-content { color: var(--color-ink); line-height: 1.65; }
    .result-content h1, .result-content h2, .result-content h3 { font-weight: 600; line-height: 1.25; margin: 1.25em 0 0.5em; }
    .result-content h1 { font-size: 1.5rem; }
    .result-content h2 { font-size: 1.25rem; }
    .result-content h3 { font-size: 1.1rem; }
    .result-content p { margin: 0.75em 0; }
    .result-content ul, .result-content ol { margin: 0.75em 0; padding-left: 1.5em; }
    .result-content ul { list-style: disc; }
    .result-content ol { list-style: decimal; }
    .result-content li { margin: 0.25em 0; }
    .result-content a { color: var(--color-primary); text-decoration: underline; }
    .result-content img { max-width: 100%; height: auto; border-radius: 0.5rem; margin: 1em 0; border: 1px solid var(--color-line); }
    .result-content code { font-family: var(--font-mono); font-size: 0.85em; background: var(--color-surface-subtle); padding: 0.1em 0.35em; border-radius: 0.25rem; }
    .result-content pre { background: var(--color-surface-subtle); border: 1px solid var(--color-line); border-radius: 0.5rem; padding: 1em; overflow-x: auto; margin: 1em 0; }
    .result-content pre code { background: none; padding: 0; }
    .result-content blockquote { border-left: 3px solid var(--color-line-strong); padding-left: 1em; color: var(--color-muted); margin: 1em 0; }
    .result-content table { width: 100%; border-collapse: collapse; margin: 1em 0; }
    .result-content th, .result-content td { border: 1px solid var(--color-line); padding: 0.5em 0.75em; text-align: left; }
    .result-content th { background: var(--color-surface-subtle); font-weight: 600; }
</style>
@endpush

@section('content')
@php
    use App\Models\FlowRun;
    $statusMap = [
        'completed' => ['Завършен', 'success'],
        'failed' => ['Неуспешен', 'danger'],
        'running' => ['Изпълнява се', 'info'],
        'pending' => ['Изчаква', 'neutral'],
        'waiting_approval' => ['Изчаква преглед', 'warning'],
    ];
    [$statusLabel, $statusColor] = $statusMap[$run->status] ?? [$run->status, 'neutral'];
@endphp

<div class="max-w-3xl mx-auto"
     x-data="resultView(@js($run->final_output ?? ''), '{{ route('client.flows.run', $run->flow) }}')"
     x-init="render()">

    {{-- Хедър --}}
    <div class="mb-6">
        <a href="{{ route('client.flows.show', $run->flow) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
            <x-icon name="arrow-left" size="4" /> {{ $run->flow->name }}
        </a>
        <div class="flex items-center justify-between gap-4 mt-2">
            <h1 class="text-2xl font-display font-bold text-ink">Резултат</h1>
            <x-badge :color="$statusColor">{{ $statusLabel }}</x-badge>
        </div>
        <p class="text-sm text-subtle mt-1">{{ $run->created_at?->format('d.m.Y H:i') }}</p>
    </div>

    {{-- Резултат --}}
    <x-card>
        @if($run->status === 'completed' && filled($run->final_output))
            <div class="result-content" x-html="html"></div>
        @elseif($run->status === 'failed')
            <x-empty-state icon="exclamation-triangle" title="Изпълнението не завърши успешно"
                           message="Нещо се обърка по време на изпълнението. Можеш да опиташ отново." />
        @elseif(in_array($run->status, ['pending', 'running', 'waiting_approval']))
            <x-empty-state icon="clock" title="Резултатът все още се подготвя"
                           message="Изпълнението още тече. Върни се след малко." />
        @else
            <x-empty-state icon="document-text" title="Няма резултат" message="Това изпълнение няма краен резултат." />
        @endif
    </x-card>

    {{-- Inline re-run --}}
    <div x-show="state==='running'" x-cloak class="mt-4">
        <x-card>
            <div class="flex items-center justify-between text-sm text-muted mb-2">
                <span x-text="stepTotal ? ('Стъпка ' + stepIndex + '/' + stepTotal + ' · ' + stepLabel) : stepLabel"></span>
                <span class="tabular-nums" x-text="percent + '%'"></span>
            </div>
            <div class="h-2 rounded-full bg-surface-subtle overflow-hidden">
                <div class="h-full bg-primary transition-all duration-500" :style="`width:${percent}%`"></div>
            </div>
        </x-card>
    </div>

    <div x-show="state==='done'" x-cloak class="mt-4">
        <x-alert type="success" :dismissible="false">
            Готово! <a x-bind:href="newResultUrl" class="font-medium underline">Виж новия резултат</a>.
        </x-alert>
    </div>

    <div x-show="state==='under_review'" x-cloak class="mt-4">
        <x-alert type="warning" :dismissible="false">Новото изпълнение изисква преглед от човек. Ще продължи след одобрение.</x-alert>
    </div>

    <div x-show="state==='failed'" x-cloak class="mt-4">
        <x-alert type="error" :dismissible="false"><span x-text="errorMsg"></span></x-alert>
    </div>

    {{-- Действия --}}
    <div class="flex flex-wrap gap-3 mt-6">
        <x-button x-on:click="run()" icon="play" x-bind:disabled="state==='running'">Изпълни пак</x-button>
        <x-button variant="secondary" :href="route('client.flows.show', $run->flow)">Назад към Flow-а</x-button>
    </div>
</div>

@push('scripts')
<script>
    function resultView(rawMarkdown, runUrl) {
        return {
            html: '',
            state: 'idle',
            percent: 0,
            stepLabel: '',
            stepIndex: null,
            stepTotal: null,
            newResultUrl: null,
            errorMsg: '',
            pollTimer: null,
            csrf: document.querySelector('meta[name=csrf-token]').content,
            render() {
                if (!rawMarkdown) { this.html = ''; return; }
                const clean = (typeof marked !== 'undefined') ? marked.parse(rawMarkdown, { breaks: true, gfm: true }) : rawMarkdown;
                this.html = (typeof DOMPurify !== 'undefined') ? DOMPurify.sanitize(clean) : clean;
            },
            async run() {
                clearInterval(this.pollTimer);
                this.state = 'running'; this.percent = 0; this.stepLabel = 'Стартиране…'; this.errorMsg = '';
                try {
                    const res = await fetch(runUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { this.fail(data.message || 'Неуспешен старт.'); return; }
                    this.poll(data.poll_url);
                } catch (e) { this.fail('Възникна грешка при стартиране.'); }
            },
            poll(url) {
                clearInterval(this.pollTimer);
                const tick = async () => {
                    try {
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const d = await res.json();
                        if (typeof d.percent === 'number') this.percent = d.percent;
                        this.stepIndex = d.step_index; this.stepTotal = d.step_total;
                        if (d.step_label) this.stepLabel = d.step_label;
                        if (d.under_review) { this.state = 'under_review'; }
                        else if (d.failed) { clearInterval(this.pollTimer); this.fail(d.error || 'Изпълнението е неуспешно.'); }
                        else if (d.done) { clearInterval(this.pollTimer); this.state = 'done'; this.percent = 100; this.newResultUrl = d.result_url; }
                        else { this.state = 'running'; }
                    } catch (e) { /* skip */ }
                };
                tick();
                this.pollTimer = setInterval(tick, 2000);
            },
            fail(msg) { clearInterval(this.pollTimer); this.state = 'failed'; this.errorMsg = msg; },
        };
    }
</script>
@endpush
@endsection
