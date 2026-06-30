{{-- Споделен popup „Добави знания" (§2-етапни задачи). Включва се веднъж на страница;
     отваря се чрез window-event: $dispatch('knowledge-open', { taskId, requirements }). --}}
@php
    $kgCfg = [
        'noteTpl' => route('client.org.tasks.knowledge.note', ['task' => '__TASK__']),
        'ackTpl' => route('client.org.tasks.knowledge.ack', ['task' => '__TASK__']),
        'statusTpl' => route('client.org.tasks.knowledge.status', ['task' => '__TASK__']),
        'kbUrl' => route('companies.knowledge.index', ['company' => session('client_company_id')]),
        'csrf' => csrf_token(),
    ];
@endphp
<div x-data="knowledgeModal(@js($kgCfg))" x-show="open" x-cloak
     @knowledge-open.window="openFor($event.detail)"
     @keydown.escape.window="close()"
     class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
    <div class="absolute inset-0 bg-ink/50" @click="close()"></div>

    <div x-show="open" x-transition
         class="relative max-h-[90vh] w-full overflow-y-auto rounded-t-2xl border border-line bg-surface shadow-popover sm:max-w-lg sm:rounded-2xl">
        {{-- Хедър --}}
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <h2 class="text-base font-semibold text-ink">Нужна е информация за тази задача</h2>
            <button type="button" @click="close()" aria-label="Затвори" class="rounded-md p-1 text-subtle hover:text-ink"><x-icon name="x-mark" size="5" /></button>
        </div>

        <div class="space-y-4 px-5 py-4">
            <p class="text-sm text-muted">За да изпълни задачата без догадки, системата има нужда от информацията по-долу. Въведи я тук (или в пълната база знания), после натисни „Провери".</p>

            {{-- Частни / собствени липси: трябва ръчно въвеждане --}}
            <template x-if="privateMissing().length">
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-muted">Въведи тази вътрешна информация:</p>
                    <template x-for="r in privateMissing()" :key="r.key">
                        <div class="rounded-lg border border-line bg-surface-subtle p-3">
                            <p class="text-sm font-medium text-ink" x-text="r.label"></p>
                            <div class="ai-prose mt-1 text-sm text-muted" x-show="r.how_to_provide" x-html="$md(r.how_to_provide)"></div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Публични липси: разреши уеб-търсене (без ръчно въвеждане) --}}
            <template x-if="publicMissing().length">
                <div class="space-y-2 rounded-lg border border-line p-3">
                    <p class="text-xs font-semibold text-muted">Това може да се намери онлайн — разреши на агента да го потърси:</p>
                    <ul class="space-y-1">
                        <template x-for="r in publicMissing()" :key="r.key">
                            <li class="text-sm text-ink">• <span x-text="r.label"></span></li>
                        </template>
                    </ul>
                    <x-button size="sm" variant="secondary" x-on:click="allowSearch()" x-bind:disabled="busy">Позволи търсене в интернет</x-button>
                </div>
            </template>

            {{-- Вграден редактор за бележка --}}
            <div class="space-y-2">
                <p class="text-xs font-semibold text-muted">Бърза бележка</p>
                <input x-model="title" type="text" placeholder="Заглавие (напр. Списък треньори — юни 2026)"
                       class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                <textarea x-model="content" rows="6" placeholder="Постави информацията тук…"
                          class="w-full rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"></textarea>
                <div class="flex items-center justify-between gap-2">
                    <a :href="cfg.kbUrl" target="_blank" class="text-xs text-primary hover:text-primary-hover">Пълна база знания →</a>
                    <div class="flex items-center gap-2">
                        <span x-show="stage" class="inline-flex items-center gap-1 text-xs text-accent"><x-org.bolt-spinner size="14" /><span x-text="stage"></span></span>
                        <x-button size="sm" variant="secondary" x-on:click="check()" x-bind:disabled="busy">Провери</x-button>
                        <x-button size="sm" x-on:click="saveNote()" x-bind:disabled="busy">Запази</x-button>
                    </div>
                </div>
                <p x-show="msg" x-text="msg" class="text-xs text-muted"></p>
            </div>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
window.knowledgeModal = function (cfg) {
    return {
        cfg, open: false, taskId: null, reqs: [], title: '', content: '', stage: '', busy: false, msg: '', _poll: null,
        openFor(d) {
            this.taskId = d.taskId; this.reqs = d.requirements || [];
            this.title = ''; this.content = ''; this.stage = ''; this.msg = ''; this.busy = false;
            this.open = true;
            if (!this.reqs.length) this.check(); // няма подадени → дръпни актуалните
        },
        close() { this.open = false; if (this._poll) { clearInterval(this._poll); this._poll = null; } },
        privateMissing() { return this.reqs.filter(r => r.sourceability !== 'public' && r.status !== 'covered'); },
        publicMissing() { return this.reqs.filter(r => r.sourceability === 'public' && r.status !== 'covered' && !r.acknowledged); },
        _url(tpl) { return tpl.replace('__TASK__', this.taskId); },
        _post(tpl, body) {
            return fetch(this._url(tpl), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body || {}) }).then(r => r.json());
        },
        saveNote() {
            if (!this.title.trim() || !this.content.trim()) { this.msg = 'Попълни заглавие и съдържание.'; return; }
            this.busy = true; this.msg = ''; this.stage = 'обработва знанието…';
            this._post(cfg.noteTpl, { title: this.title, content: this.content })
                .then(() => { this.title = ''; this.content = ''; this._startPoll(); })
                .catch(() => { this.busy = false; this.stage = ''; this.msg = 'Грешка при запазване.'; });
        },
        allowSearch() {
            const keys = this.publicMissing().map(r => r.key);
            if (!keys.length) return;
            this.busy = true;
            this._post(cfg.ackTpl, { keys }).then(d => { this.busy = false; this._handle(d); }).catch(() => { this.busy = false; });
        },
        check() {
            this.busy = true; this.stage = 'проверявам…';
            fetch(this._url(cfg.statusTpl), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(d => this._handle(d)).catch(() => { this.busy = false; this.stage = ''; });
        },
        _startPoll() {
            if (this._poll) clearInterval(this._poll);
            const t = async () => { try { const d = await (await fetch(this._url(cfg.statusTpl), { headers: { 'Accept': 'application/json' } })).json(); this._handle(d); } catch (e) {} };
            t(); this._poll = setInterval(t, 2500);
        },
        _handle(d) {
            if (d.ingesting) { this.stage = 'обработва знанието…'; this.busy = true; return; }
            if (this._poll) { clearInterval(this._poll); this._poll = null; }
            this.busy = false; this.stage = '';
            if (d.ingest_failed) { this.msg = d.message || 'Обработката на знанието се провали.'; return; }
            // Авто-изход от knowledgeStatus (пускане/генерация) → презареди, за да се види новото състояние.
            if (d.status === 'running' || d.status === 'generating') { window.location.reload(); return; }
            if (d.requirements) this.reqs = d.requirements;
            if (d.knowledge_status === 'ready') { this.msg = 'Готово! Зареждам…'; setTimeout(() => window.location.reload(), 600); return; }
            this.msg = 'Все още липсва информация. Добави още и натисни „Провери".';
        },
    };
};
</script>
@endpush
@endonce
