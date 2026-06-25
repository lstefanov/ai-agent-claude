@extends('layouts.client')

@section('title', 'Нова задача')

@section('content')
<div class="max-w-2xl mx-auto px-6 py-8"
     x-data="taskNew({
        createUrl: '{{ route('client.org.tasks.create') }}',
        resultTpl: '{{ route('client.runs.result', ['run' => '__RUN__']) }}',
        preselect: {{ $preselect ?: 'null' }},
     })">

    <a href="{{ route('client.org.roster') }}" class="text-sm text-muted hover:text-ink">← Към екипа</a>
    <h1 class="mt-3 text-2xl font-semibold text-ink mb-1">Нова задача</h1>
    <p class="text-muted mb-6">Опиши какво трябва да се свърши. Управителят ще я възложи на най-подходящия
        асистент (или избери сам), който ще проектира flow-а по своята персона.</p>

    {{-- Форма --}}
    <div x-show="phase === 'form'" class="space-y-5">
        <div class="rounded-xl border border-line bg-surface p-5 space-y-4">
            <div>
                <label class="block text-sm font-medium text-ink mb-1">Заглавие <span class="text-subtle">(по избор)</span></label>
                <input type="text" x-model="title" maxlength="255" placeholder="напр. Седмичен бюлетин за клиенти"
                       class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
            </div>
            <div>
                <label class="block text-sm font-medium text-ink mb-1">Какво да прави асистентът?</label>
                <textarea x-model="description" rows="5" maxlength="4000" required
                          placeholder="Опиши задачата подробно — какъв е резултатът, от какви източници, на какъв език, формат…"
                          class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"></textarea>
            </div>

            {{-- Режим: авто / ръчен --}}
            <div>
                <label class="block text-sm font-medium text-ink mb-2">Кой да я поеме?</label>
                <div class="inline-flex rounded-lg border border-line bg-surface-subtle p-0.5">
                    <button type="button" @click="mode = 'auto'"
                            :class="mode === 'auto' ? 'bg-primary text-primary-fg' : 'text-muted hover:text-ink'"
                            class="px-4 py-1.5 text-sm font-medium rounded-md transition">Управителят избира</button>
                    <button type="button" @click="mode = 'manual'"
                            :class="mode === 'manual' ? 'bg-primary text-primary-fg' : 'text-muted hover:text-ink'"
                            class="px-4 py-1.5 text-sm font-medium rounded-md transition">Избери асистент</button>
                </div>

                <div x-show="mode === 'manual'" x-cloak class="mt-3">
                    <select x-model.number="assistantId"
                            class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                        <option value="">— избери асистент —</option>
                        @foreach ($assistants as $a)
                            <option value="{{ $a['member_id'] }}">{{ $a['name'] }} · {{ $a['title'] }}@if ($a['director']) ({{ $a['director'] }})@endif</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <p x-show="error" x-text="error" x-cloak class="text-sm text-danger"></p>

        <button type="button" @click="submit()" :disabled="busy || !description.trim() || (mode === 'manual' && !assistantId)"
                class="inline-flex items-center justify-center gap-2 h-10 px-5 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover disabled:opacity-50 transition">
            <span x-show="!busy">Възложи задачата</span>
            <span x-show="busy" x-cloak>Възлагане…</span>
        </button>
    </div>

    {{-- Възлагане + генерация --}}
    <div x-show="phase !== 'form'" x-cloak class="rounded-xl border border-line bg-surface p-6">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 h-2.5 w-2.5 rounded-full"
                  :class="phase === 'working' ? 'bg-accent animate-pulse' : (phase === 'ready' ? 'bg-success' : 'bg-danger')"></span>
            <div class="min-w-0">
                <p class="text-sm text-ink">
                    Възложено на <a :href="assignment.member_url" class="font-semibold text-primary hover:text-primary-hover" x-text="assignment.member_name"></a>
                </p>
                <p class="text-xs text-muted mt-0.5" x-text="assignment.reason"></p>
                <p class="text-sm mt-3" x-text="statusText"></p>
            </div>
        </div>

        <div x-show="phase === 'ready'" x-cloak class="mt-5 flex flex-wrap gap-2">
            <button type="button" @click="runNow()" :disabled="busy"
                    class="inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover disabled:opacity-50 transition">Изпълни сега</button>
            <a :href="assignment.member_url"
               class="inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-medium rounded-md border border-line text-ink hover:bg-surface-subtle transition">Виж асистента</a>
        </div>

        <p x-show="error" x-text="error" x-cloak class="mt-4 text-sm text-danger"></p>
    </div>
</div>

@push('scripts')
<script>
function taskNew(cfg) {
    return {
        mode: 'auto', assistantId: cfg.preselect || '', title: '', description: '',
        phase: 'form', busy: false, error: '', statusText: '',
        assignment: { member_name: '', reason: '', member_url: '#', task_id: null, run_url: '#' },
        _poll: null,

        init() {
            if (cfg.preselect) { this.mode = 'manual'; this.assistantId = cfg.preselect; }
        },

        post(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                body: JSON.stringify(body || {}),
            }).then(async r => ({ ok: r.ok, status: r.status, data: await r.json().catch(() => ({})) }));
        },

        submit() {
            if (!this.description.trim()) return;
            this.busy = true; this.error = '';
            this.post(cfg.createUrl, { title: this.title, description: this.description, mode: this.mode, assistant_id: this.assistantId || null })
                .then(({ ok, data }) => {
                    if (!ok || !data.ok) {
                        this.busy = false;
                        this.error = data.message || data.error || 'Възлагането се провали.';
                        return;
                    }
                    this.assignment = { member_name: data.member_name, reason: data.reason, member_url: data.member_url, task_id: data.task_id, run_url: data.run_url };
                    this.phase = 'working';
                    this.statusText = 'Асистентът проектира flow-а по своята персона…';
                    if (data.status === 'ready') { this.busy = false; this.phase = 'ready'; this.statusText = 'Готов е за изпълнение.'; }
                    else if (data.gen_status_url) { this.pollGen(data.gen_status_url); }
                    else { this.busy = false; }
                });
        },

        pollGen(url) {
            const tick = async () => {
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (d.task_status === 'ready') { clearInterval(this._poll); this.busy = false; this.phase = 'ready'; this.statusText = 'Готов е за изпълнение.'; }
                    else if (d.status === 'failed' || d.task_status === 'failed') { clearInterval(this._poll); this.busy = false; this.phase = 'error'; this.error = 'Генерацията се провали.'; }
                    else if (d.stage) { this.statusText = d.stage; }
                } catch (e) {}
            };
            tick(); this._poll = setInterval(tick, 2500);
        },

        runNow() {
            this.busy = true; this.error = ''; this.statusText = 'Пускам…';
            this.post(this.assignment.run_url, {})
                .then(({ ok, status, data }) => {
                    if (status === 402) { this.busy = false; this.error = (data.message || 'Недостатъчно кредити.') + ' Иди в Кредити, за да добавиш.'; return; }
                    if (data.status === 'running' && data.poll_url) { this.pollRun(data.run_id, data.poll_url); }
                    else { this.busy = false; this.error = data.message || 'Грешка при пускане.'; }
                });
        },

        pollRun(runId, pollUrl) {
            const tick = async () => {
                try {
                    const d = await (await fetch(pollUrl, { headers: { 'Accept': 'application/json' } })).json();
                    this.statusText = d.label || d.step || d.status || 'тече…';
                    if (['completed', 'failed', 'waiting_approval'].includes(d.status)) {
                        clearInterval(this._poll);
                        window.location = cfg.resultTpl.replace('__RUN__', runId);
                    }
                } catch (e) {}
            };
            tick(); this._poll = setInterval(tick, 2000);
        },
    };
}
</script>
@endpush
@endsection
