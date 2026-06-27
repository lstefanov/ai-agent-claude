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
    $nodeDot = [
        'completed' => 'bg-success', 'failed' => 'bg-danger', 'running' => 'bg-accent animate-pulse',
        'paused' => 'bg-warning', 'skipped' => 'bg-subtle', 'pending' => 'bg-subtle',
    ];
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

    {{-- Техническа прозрачност (§12): node timeline + цена + planner. Видимо по подразбиране;
         само суровите изходи/промптове са в свиваем drawer. --}}
    @isset($timeline)
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-ink mb-3">Детайли за изпълнението</h2>

            @if ($task)
                <p class="text-xs text-muted mb-3">Задача: <span class="text-ink">{{ $task->title }}</span> · {{ $task->orgMember?->fullName() }} ({{ $task->orgMember?->roleTitle() }})</p>
            @endif

            {{-- Агрегати --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
                <div class="rounded-lg border border-line bg-surface p-3"><p class="text-xs text-muted">Цена</p><p class="text-lg font-semibold text-ink tabular-nums">${{ number_format($totals['cost_usd'], 4) }}</p></div>
                <div class="rounded-lg border border-line bg-surface p-3"><p class="text-xs text-muted">Токени</p><p class="text-lg font-semibold text-ink tabular-nums">{{ number_format($totals['tokens']) }}</p></div>
                <div class="rounded-lg border border-line bg-surface p-3"><p class="text-xs text-muted">Среден QA</p><p class="text-lg font-semibold text-ink tabular-nums">{{ $totals['avg_qa'] ?? '—' }}</p></div>
                <div class="rounded-lg border border-line bg-surface p-3"><p class="text-xs text-muted">Възли / опити</p><p class="text-lg font-semibold text-ink tabular-nums">{{ $timeline->count() }} / {{ $totals['attempts'] }}</p></div>
            </div>

            @php($est = $task?->proposal['estimated_cost']['credits'] ?? null)
            @if ($est)<p class="text-xs text-subtle mb-3">Ориентировъчно при предложението: ~{{ $est }} кредита · реално: ${{ number_format($totals['cost_usd'], 4) }}.</p>@endif

            {{-- Node timeline --}}
            @if ($timeline->isNotEmpty())
                <div class="rounded-xl border border-line bg-surface divide-y divide-line" x-data="{ open: null }">
                    @foreach ($timeline as $i => $t)
                        @php($nr = $t['run'])
                        <div>
                            <button type="button" x-on:click="open = open === {{ $i }} ? null : {{ $i }}" class="w-full flex items-center justify-between gap-3 p-3 text-left hover:bg-surface-subtle transition">
                                <span class="min-w-0 flex items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-full {{ $nodeDot[$nr->status] ?? 'bg-subtle' }}"></span>
                                    <span class="text-sm text-ink truncate">{{ $t['name'] }}</span>
                                    @if ($t['attempts'] > 1)<span class="text-xs text-warning-strong" title="опити">×{{ $t['attempts'] }}</span>@endif
                                </span>
                                <span class="flex items-center gap-3 text-xs text-subtle tabular-nums shrink-0">
                                    @if ($nr->model_used)<span class="font-mono hidden sm:inline">{{ $nr->model_used }}</span>@endif
                                    @if ($nr->qa_score !== null)<span>QA {{ (int) $nr->qa_score }}</span>@endif
                                    <span>${{ number_format((float) $nr->cost_usd, 4) }}</span>
                                    @if ($nr->duration_ms)<span>{{ round($nr->duration_ms / 1000, 1) }}s</span>@endif
                                </span>
                            </button>
                            <div x-show="open === {{ $i }}" x-cloak class="px-3 pb-3 space-y-2 text-xs">
                                <div class="flex flex-wrap gap-x-3 gap-y-1 text-muted tabular-nums">
                                    <span>статус: {{ $nr->status }}</span>
                                    <span>модел: <span class="font-mono">{{ $nr->model_used ?? '—' }}</span></span>
                                    <span>токени: {{ number_format((int) $nr->tokens_used) }} ({{ (int) $nr->prompt_tokens }}+{{ (int) $nr->completion_tokens }})</span>
                                </div>
                                @if ($nr->output)
                                    <div class="rounded-lg bg-surface-subtle p-2 text-ink whitespace-pre-wrap max-h-48 overflow-y-auto">{{ \Illuminate\Support\Str::limit($nr->output, 1200) }}</div>
                                @endif
                                @if ($nr->error)<p class="text-danger">{{ $nr->error }}</p>@endif
                                @if ($nr->raw_output && $nr->raw_output !== $nr->output)
                                    <details><summary class="cursor-pointer text-subtle">Суров изход / промпт</summary><pre class="mt-1 rounded-lg bg-surface-subtle p-2 overflow-x-auto max-h-64 overflow-y-auto">{{ \Illuminate\Support\Str::limit($nr->raw_output, 4000) }}</pre></details>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Planner прозрачност (групирано по token) --}}
            @if ($plannerLogs->isNotEmpty())
                <details class="mt-4 rounded-xl border border-line bg-surface">
                    <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-muted hover:text-ink">Технически детайли (планер)</summary>
                    <div class="border-t border-line p-3 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead><tr class="text-subtle text-left"><th class="pr-3 pb-1">Провайдър</th><th class="pr-3 pb-1">Модел</th><th class="pr-3 pb-1 text-right">Цена</th><th class="pr-3 pb-1 text-right">Време</th><th class="pb-1">Статус</th></tr></thead>
                            <tbody>
                                @foreach ($plannerLogs as $log)
                                    <tr class="border-t border-line">
                                        <td class="pr-3 py-1 font-mono">{{ $log->provider }}</td>
                                        <td class="pr-3 py-1 font-mono">{{ $log->model }}</td>
                                        <td class="pr-3 py-1 text-right tabular-nums">${{ number_format((float) $log->cost_usd, 4) }}</td>
                                        <td class="pr-3 py-1 text-right tabular-nums">{{ (int) $log->duration_ms }}ms</td>
                                        <td class="py-1">{{ $log->status }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </div>
    @endisset

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
