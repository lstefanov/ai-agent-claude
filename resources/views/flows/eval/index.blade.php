@extends('layouts.app')

@section('title', 'Eval — ' . $flow->name)

@section('content')
<div class="max-w-5xl mx-auto" x-data="evalRunner(@js(route('flows.eval.run', $flow)), @js(url('eval-run-status')), @js(route('flows.eval.results', $flow)))">
    <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
        <div>
            <div class="text-sm text-gray-400 mb-1">
                <a href="{{ route('companies.show', $flow->company) }}" class="hover:text-indigo-600">{{ $flow->company->name }}</a>
                <span class="mx-1">/</span>
                <a href="{{ route('flows.show', $flow) }}" class="hover:text-indigo-600">{{ $flow->name }}</a>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">🧪 Eval — тестове за качество</h1>
            <p class="text-sm text-gray-500 mt-1">
                Задай golden тестове, пусни ги на различни версии × нива и виж обективна крива цена↔качество.
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if($hasResults)
                <a href="{{ route('flows.eval.results', $flow) }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">📊 Резултати →</a>
            @endif
            <a href="{{ route('flows.eval.create', $flow) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition">+ Нов тест</a>
        </div>
    </div>

    {{-- Cases list --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-gray-100 font-semibold text-gray-800">Тестове ({{ $cases->count() }})</div>
        @forelse($cases as $case)
            <div class="px-5 py-3 border-b border-gray-50 last:border-0 flex items-center justify-between gap-4 hover:bg-gray-50">
                <label class="flex items-center gap-3 min-w-0">
                    <input type="checkbox" value="{{ $case->id }}" x-model.number="caseIds" class="rounded border-gray-300 text-indigo-600">
                    <span class="min-w-0">
                        <span class="font-medium text-gray-900">{{ $case->name }}</span>
                        @unless($case->is_active)<span class="ml-2 text-xs text-gray-400">(неактивен)</span>@endunless
                        <span class="block text-xs text-gray-500 truncate">{{ $case->description }}</span>
                    </span>
                </label>
                <div class="flex items-center gap-4 shrink-0 text-sm">
                    <span class="text-gray-400">тежест {{ rtrim(rtrim(number_format($case->weight, 1), '0'), '.') }}</span>
                    <span class="text-gray-400">{{ count($case->criteria ?? []) }} крит.</span>
                    <a href="{{ route('flows.eval.edit', [$flow, $case]) }}" class="text-indigo-600 hover:text-indigo-800">✏️</a>
                    <form method="POST" action="{{ route('flows.eval.destroy', [$flow, $case]) }}" onsubmit="return confirm('Изтриване на „{{ $case->name }}“?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-gray-400 hover:text-red-600">🗑</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-gray-400 text-sm">Още няма тестове. Започни с „+ Нов тест".</div>
        @endforelse
    </div>

    {{-- Run section --}}
    @if($cases->where('is_active', true)->count() && $versions->count())
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-4">▶ Стартирай Eval</h2>

        <div class="grid md:grid-cols-2 gap-6">
            <div>
                <div class="text-sm font-medium text-gray-700 mb-2">Версии</div>
                <div class="space-y-1.5 max-h-44 overflow-auto pr-1">
                    @foreach($versions as $version)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" value="{{ $version->id }}" x-model.number="versionIds" class="rounded border-gray-300 text-indigo-600">
                            <span>{{ $version->name }}</span>
                            @if($version->is_active)<span class="text-xs text-green-600">(активна)</span>@endif
                            @if($version->model_level)<span class="text-xs text-gray-400">· {{ $version->model_level }}</span>@endif
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <div class="text-sm font-medium text-gray-700 mb-2">Нива</div>
                <div class="flex flex-wrap gap-3">
                    @foreach($levels as $level)
                        <label class="flex items-center gap-1.5 text-sm">
                            <input type="checkbox" value="{{ $level }}" x-model="levels" class="rounded border-gray-300 text-indigo-600">
                            <span class="uppercase text-xs font-medium">{{ $level }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-gray-400 mt-2">Препоръка: започни с low/medium/high. Cases: маркирай горе или остави празно за „всички активни".</p>
            </div>
        </div>

        <div class="mt-5 flex items-center gap-4">
            <button @click="start()" :disabled="running || versionIds.length === 0 || levels.length === 0"
                    class="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                <span x-show="!running">▶ Стартирай</span>
                <span x-show="running" class="animate-pulse">Изпълнение…</span>
            </button>
            <span class="text-xs text-gray-500" x-text="comboSummary()"></span>
        </div>

        <p x-show="error" x-text="error" class="text-sm text-red-600 mt-3"></p>

        {{-- Progress --}}
        <div x-show="running || done > 0" class="mt-4" x-cloak>
            <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span x-text="`${done} / ${total} оценени` + (failed ? ` · ${failed} грешка` : '')"></span>
                <span x-show="finished"><a :href="resultsUrl" class="text-indigo-600 font-medium">Виж резултатите →</a></span>
            </div>
            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full bg-indigo-500 transition-all" :style="`width: ${Math.round(nodeProgress*100)}%`"></div>
            </div>

            {{-- Per-eval node progress so the bar never looks stuck at 0 --}}
            <div class="mt-2 space-y-1 max-h-48 overflow-auto" x-show="items.length">
                <template x-for="it in items" :key="it.id">
                    <div class="flex items-center justify-between text-xs">
                        <span class="uppercase font-medium text-gray-500" x-text="it.level"></span>
                        <span x-show="it.status === 'running'" class="text-gray-400" x-text="`${it.nodes_done}/${it.nodes_total} възела…`"></span>
                        <span x-show="it.status === 'pending'" class="text-gray-300">чака на опашка…</span>
                        <span x-show="it.status === 'completed'" class="text-green-600 font-medium" x-text="`✓ ${it.score}/100`"></span>
                        <span x-show="it.status === 'failed'" class="text-red-500">грешка</span>
                    </div>
                </template>
            </div>

            <p class="text-xs text-gray-400 mt-2">⏳ Всеки eval е истински FlowRun (възли + judge накрая). При няколко нива е нормално да отнеме минути — локалните агенти делят един GPU.</p>
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
