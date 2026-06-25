@extends('layouts.app')

@section('title', 'Eval — ' . $flow->name)

@section('content')
<div class="max-w-5xl mx-auto" x-data="evalRunner(@js(route('flows.eval.run', $flow)), @js(url('eval-run-status')), @js(route('flows.eval.results', $flow)))">
    <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
        <div>
            <div class="text-sm text-subtle mb-1">
                <a href="{{ route('companies.show', $flow->company) }}" class="hover:text-primary">{{ $flow->company->name }}</a>
                <span class="mx-1">/</span>
                <a href="{{ route('flows.show', $flow) }}" class="hover:text-primary">{{ $flow->name }}</a>
            </div>
            <h1 class="text-2xl font-bold text-ink">Eval — тестове за качество</h1>
            <p class="text-sm text-muted mt-1">
                Задай golden тестове, пусни ги на различни версии × нива и виж обективна крива цена↔качество.
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if($hasResults)
                <a href="{{ route('flows.eval.results', $flow) }}" class="text-sm text-primary hover:text-primary-hover font-medium">Резултати →</a>
            @endif
            <a href="{{ route('flows.eval.create', $flow) }}" class="bg-primary hover:bg-primary-hover text-white text-sm font-medium px-4 py-2 rounded-lg transition">+ Нов тест</a>
        </div>
    </div>

    {{-- Cases list --}}
    <div class="bg-surface rounded-xl border border-line overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-line font-semibold text-ink">Тестове ({{ $cases->count() }})</div>
        @forelse($cases as $case)
            <div class="px-5 py-3 border-b border-line last:border-0 flex items-center justify-between gap-4 hover:bg-surface-subtle">
                <label class="flex items-center gap-3 min-w-0">
                    <input type="checkbox" value="{{ $case->id }}" x-model.number="caseIds" class="rounded border-line text-primary">
                    <span class="min-w-0">
                        <span class="font-medium text-ink">{{ $case->name }}</span>
                        @unless($case->is_active)<span class="ml-2 text-xs text-subtle">(неактивен)</span>@endunless
                        <span class="block text-xs text-muted truncate">{{ $case->description }}</span>
                    </span>
                </label>
                <div class="flex items-center gap-4 shrink-0 text-sm">
                    <span class="text-subtle">тежест {{ rtrim(rtrim(number_format($case->weight, 1), '0'), '.') }}</span>
                    <span class="text-subtle">{{ count($case->criteria ?? []) }} крит.</span>
                    <a href="{{ route('flows.eval.edit', [$flow, $case]) }}" class="text-primary hover:text-primary-hover">✏️</a>
                    <form method="POST" action="{{ route('flows.eval.destroy', [$flow, $case]) }}" onsubmit="return confirm('Изтриване на „{{ $case->name }}“?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-subtle hover:text-red-600">🗑</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-subtle text-sm">Още няма тестове. Започни с „+ Нов тест".</div>
        @endforelse
    </div>

    {{-- Run section --}}
    @if($cases->where('is_active', true)->count() && $versions->count())
    <div class="bg-surface rounded-xl border border-line p-5">
        <h2 class="font-semibold text-ink mb-1">▶ Стартирай Eval</h2>
        <p class="text-xs text-muted mb-4">
            Пуска избран(и) <b>шаблон(и)</b> на избрани <b>ценови нива</b> и оценява изхода. Целта: кое е най-евтиното ниво без загуба на качество.
            @if($versions->count() === 1)<br>Имаш 1 шаблон — просто избери нивата, на които да го тестваш.@endif
        </p>

        <div class="grid md:grid-cols-2 gap-6">
            <div>
                <div class="text-sm font-medium text-ink mb-2">Версии <span class="font-normal text-subtle">(шаблони на flow-а)</span></div>
                <div class="space-y-1.5 max-h-44 overflow-auto pr-1">
                    @foreach($versions as $version)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" value="{{ $version->id }}" x-model.number="versionIds" class="rounded border-line text-primary">
                            <span>{{ $version->name }}</span>
                            @if($version->is_active)<span class="text-xs text-green-600">(активна)</span>@endif
                            @if($version->model_level)<span class="text-xs text-subtle" title="Родното ниво на този шаблон">· родно: {{ $version->model_level }}</span>@endif
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <div class="text-sm font-medium text-ink mb-2">Нива</div>
                <div class="flex flex-wrap gap-3">
                    @foreach($levels as $level)
                        <label class="flex items-center gap-1.5 text-sm">
                            <input type="checkbox" value="{{ $level }}" x-model="levels" class="rounded border-line text-primary">
                            <span class="uppercase text-xs font-medium">{{ $level }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-subtle mt-2">Препоръка: започни с low/medium/high. Cases: маркирай горе или остави празно за „всички активни".</p>
            </div>
        </div>

        <div class="mt-5 flex items-center gap-4">
            <button @click="start()" :disabled="running || versionIds.length === 0 || levels.length === 0"
                    class="bg-primary hover:bg-primary-hover disabled:opacity-50 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                <span x-show="!running">▶ Стартирай</span>
                <span x-show="running" class="animate-pulse">Изпълнение…</span>
            </button>
            <span class="text-xs text-muted" x-text="comboSummary()"></span>
        </div>

        <p x-show="error" x-text="error" class="text-sm text-red-600 mt-3"></p>

        {{-- Progress --}}
        <div x-show="running || done > 0" class="mt-4" x-cloak>
            <div class="flex justify-between text-xs text-muted mb-1">
                <span x-text="`${done} / ${total} оценени` + (failed ? ` · ${failed} грешка` : '')"></span>
                <span x-show="finished"><a :href="resultsUrl" class="text-primary font-medium">Виж резултатите →</a></span>
            </div>
            <div class="h-2 bg-neutral-soft rounded-full overflow-hidden">
                <div class="h-full bg-info-soft0 transition-all" :style="`width: ${Math.round(nodeProgress*100)}%`"></div>
            </div>

            {{-- Per-eval node progress so the bar never looks stuck at 0 --}}
            <div class="mt-2 space-y-1 max-h-48 overflow-auto" x-show="items.length">
                <template x-for="it in items" :key="it.id">
                    <div class="flex items-center justify-between text-xs">
                        <span class="uppercase font-medium text-muted" x-text="it.level"></span>
                        <span x-show="it.status === 'running'" class="text-subtle" x-text="`${it.nodes_done}/${it.nodes_total} възела…`"></span>
                        <span x-show="it.status === 'pending'" class="text-subtle">чака на опашка…</span>
                        <span x-show="it.status === 'completed'" class="text-green-600 font-medium" x-text="`✓ ${it.score}/100`"></span>
                        <span x-show="it.status === 'failed'" class="text-red-500">грешка</span>
                    </div>
                </template>
            </div>

            <p class="text-xs text-subtle mt-2">⏳ Всеки eval е истински FlowRun (възли + judge накрая). При няколко нива е нормално да отнеме минути — локалните агенти делят един GPU.</p>
        </div>
    </div>
    @else
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 text-sm">
            За да пуснеш eval ти трябва поне един активен тест и поне една версия на flow-а.
        </div>
    @endif
</div>

@push('scripts')
<script>
function evalRunner(runUrl, statusBase, resultsUrl) {
    return {
        runUrl, statusBase, resultsUrl,
        versionIds: @js($versions->where('is_active', true)->pluck('id')->values()),
        levels: ['low', 'medium', 'high'],
        caseIds: [],
        running: false, error: '', token: null,
        total: 0, done: 0, failed: 0, finished: false,
        items: [], nodeProgress: 0,
        _poll: null,

        comboSummary() {
            const cases = this.caseIds.length || {{ $cases->where('is_active', true)->count() }};
            const n = this.versionIds.length * this.levels.length * cases;
            return n ? `${n} изпълнения (${this.versionIds.length} версии × ${this.levels.length} нива × ${cases} cases)` : '';
        },

        async start() {
            this.error = ''; this.finished = false; this.done = 0; this.total = 0; this.failed = 0;
            this.items = []; this.nodeProgress = 0;
            this.running = true;
            try {
                const res = await fetch(this.runUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' },
                    body: JSON.stringify({ version_ids: this.versionIds, levels: this.levels, case_ids: this.caseIds }),
                });
                const data = await res.json();
                if (!res.ok) { this.error = data.error || 'Грешка при стартиране.'; this.running = false; return; }
                this.token = data.token; this.total = data.total;
                this.poll();
            } catch (e) {
                this.error = 'Мрежова грешка: ' + e.message; this.running = false;
            }
        },

        poll() {
            clearInterval(this._poll);
            this._poll = setInterval(async () => {
                try {
                    const res = await fetch(`${this.statusBase}/${this.token}`, { headers: { 'Accept': 'application/json' } });
                    const s = await res.json();
                    this.done = s.done; this.failed = s.failed; this.total = s.total || this.total;
                    this.items = s.items || []; this.nodeProgress = s.node_progress || 0;
                    if (s.finished) { this.finished = true; this.running = false; this.nodeProgress = 1; clearInterval(this._poll); }
                } catch (e) { /* keep polling */ }
            }, 3000);
        },
    };
}
</script>
@endpush
@endsection
