{{-- Споделен popup „Добави знания" (§2-етапни задачи). Включва се веднъж на страница;
     отваря се чрез window-event: $dispatch('knowledge-open', { taskId, requirements }).
     Per-секция intake: всяка секция вляво е отделен слот (свой текст/файл/URL), със свой
     „Провери" и цветно кръгче (сиво → жълто → зелено/червено). „Продължи" се отключва само
     когато всички кръгчета са зелени. --}}
@php
    $searchProvider = (string) config('services.web_search.provider', 'brave');
    $kgCfg = [
        'noteTpl' => route('client.org.tasks.knowledge.note', ['task' => '__TASK__']),
        'uploadTpl' => route('client.org.tasks.knowledge.upload', ['task' => '__TASK__']),
        'urlTpl' => route('client.org.tasks.knowledge.url', ['task' => '__TASK__']),
        'suggestTpl' => route('client.org.tasks.knowledge.suggest-existing', ['task' => '__TASK__']),
        'linkTpl' => route('client.org.tasks.knowledge.link-existing', ['task' => '__TASK__']),
        'connectorsTpl' => route('client.org.tasks.knowledge.connectors', ['task' => '__TASK__']),
        'connectorOptionsTpl' => route('client.org.tasks.knowledge.connector-options', ['task' => '__TASK__']),
        'connectorIngestTpl' => route('client.org.tasks.knowledge.connector-ingest', ['task' => '__TASK__']),
        'researchTpl' => route('client.org.tasks.knowledge.research', ['task' => '__TASK__']),
        'researchIngestTpl' => route('client.org.tasks.knowledge.research-ingest', ['task' => '__TASK__']),
        'statusTpl' => route('client.org.tasks.knowledge.status', ['task' => '__TASK__']),
        'checkTpl' => route('client.org.tasks.knowledge.check', ['task' => '__TASK__']),
        'proceedTpl' => route('client.org.tasks.knowledge.proceed', ['task' => '__TASK__']),
        'kbUrl' => route('companies.knowledge.index', ['company' => session('client_company_id')]),
        'integrationsUrl' => route('client.org.integrations'),
        'searchEnabled' => ! empty(config("services.{$searchProvider}.api_key")),
        'csrf' => csrf_token(),
    ];
    $tabBase = 'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition';
@endphp
<div x-data="knowledgeModal(@js($kgCfg))" x-show="open" x-cloak
     @knowledge-open.window="openFor($event.detail)"
     @keydown.escape.window="close()"
     class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
    <div class="absolute inset-0 bg-ink/50" @click="close()"></div>

    <div x-show="open" x-transition
         class="relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl border border-line bg-surface shadow-popover sm:max-h-[88vh] sm:max-w-4xl sm:rounded-2xl">
        {{-- Хедър --}}
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <div>
                <h2 class="text-base font-semibold text-ink">Нужна е информация за тази задача</h2>
                <p class="mt-0.5 text-xs text-muted">Попълни всяка секция вляво и натисни „Провери". Продължаваш, щом всички станат зелени.</p>
            </div>
            <button type="button" @click="close()" aria-label="Затвори" class="rounded-md p-1 text-subtle hover:text-ink"><x-icon name="x-mark" size="5" /></button>
        </div>

        <div class="flex min-h-0 flex-1 flex-col sm:flex-row">
            {{-- Ляв панел: секции + прогрес --}}
            <div class="max-h-44 shrink-0 overflow-y-auto border-b border-line bg-surface-subtle px-4 py-3 sm:max-h-none sm:w-64 sm:border-b-0 sm:border-r sm:py-4">
                <div class="mb-1.5 flex items-center justify-between text-xs text-muted">
                    <span>Готови секции</span>
                    <span class="font-semibold text-ink"><span x-text="greenCount()"></span> / <span x-text="reqs.length"></span></span>
                </div>
                <div class="mb-3 h-1.5 overflow-hidden rounded-full bg-line">
                    <div class="h-full bg-success transition-all" :style="`width: ${reqs.length ? Math.round(greenCount()/reqs.length*100) : 0}%`"></div>
                </div>

                <template x-if="privateReqs().length">
                    <div class="mb-2">
                        <p class="mb-1 px-1 text-[11px] font-semibold uppercase tracking-wide text-muted">Вътрешна информация</p>
                        <template x-for="r in privateReqs()" :key="r.key">
                            <button type="button" @click="focusReq(r.key)"
                                    class="mb-1 flex w-full items-start gap-2 rounded-lg border px-2.5 py-2 text-left transition"
                                    :class="r.key === focusKey ? 'border-primary/40 bg-primary/5' : 'border-transparent hover:bg-surface'">
                                <span class="mt-0.5 shrink-0">
                                    <x-icon name="minus-circle" size="4" class="text-subtle" x-show="circleColor(r) === 'grey'" />
                                    <x-icon name="clock" size="4" class="text-warning" x-show="circleColor(r) === 'yellow'" />
                                    <x-icon name="check-circle" size="4" class="text-success" x-show="circleColor(r) === 'green'" />
                                    <x-icon name="x-circle" size="4" class="text-danger" x-show="circleColor(r) === 'red'" />
                                </span>
                                <span class="text-xs leading-snug" :class="r.key === focusKey ? 'text-ink' : 'text-muted'" x-text="r.label"></span>
                            </button>
                        </template>
                    </div>
                </template>

                <template x-if="publicReqs().length">
                    <div>
                        <p class="mb-1 px-1 text-[11px] font-semibold uppercase tracking-wide text-muted">Може онлайн</p>
                        <template x-for="r in publicReqs()" :key="r.key">
                            <button type="button" @click="focusReq(r.key)"
                                    class="mb-1 flex w-full items-start gap-2 rounded-lg border px-2.5 py-2 text-left transition"
                                    :class="r.key === focusKey ? 'border-primary/40 bg-primary/5' : 'border-transparent hover:bg-surface'">
                                <span class="mt-0.5 shrink-0">
                                    <x-icon name="minus-circle" size="4" class="text-subtle" x-show="circleColor(r) === 'grey'" />
                                    <x-icon name="clock" size="4" class="text-warning" x-show="circleColor(r) === 'yellow'" />
                                    <x-icon name="check-circle" size="4" class="text-success" x-show="circleColor(r) === 'green'" />
                                    <x-icon name="x-circle" size="4" class="text-danger" x-show="circleColor(r) === 'red'" />
                                </span>
                                <span class="text-xs leading-snug" :class="r.key === focusKey ? 'text-ink' : 'text-muted'" x-text="r.label"></span>
                            </button>
                        </template>
                    </div>
                </template>
            </div>

            {{-- Десен панел: работна зона за избраната секция --}}
            <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                {{-- Табове --}}
                <div class="flex flex-wrap gap-1.5 border-b border-line px-4 py-3">
                    <button type="button" @click="setTab('text')" class="{{ $tabBase }}" :class="tabCls('text')"><x-icon name="pencil-square" size="4" /><span>Текст</span></button>
                    <button type="button" @click="setTab('file')" class="{{ $tabBase }}" :class="tabCls('file')"><x-icon name="arrow-up-tray" size="4" /><span>Файл</span></button>
                    <button type="button" @click="setTab('url')" class="{{ $tabBase }}" :class="tabCls('url')"><x-icon name="link" size="4" /><span>URL</span></button>
                    <button type="button" @click="setTab('knowledge')" class="{{ $tabBase }}" :class="tabCls('knowledge')"><x-icon name="book-open" size="4" /><span>Знание</span></button>
                    <button type="button" @click="setTab('integration')" class="{{ $tabBase }}" :class="tabCls('integration')"><x-icon name="puzzle-piece" size="4" /><span>Интеграция</span></button>
                    <button type="button" @click="setTab('web')" x-show="hasPublicReqs()" class="{{ $tabBase }} disabled:cursor-not-allowed disabled:opacity-40" :class="tabCls('web')"
                            :disabled="!cfg.searchEnabled" :title="!cfg.searchEnabled ? 'Търсенето в интернет не е конфигурирано' : ''"><x-icon name="globe-alt" size="4" /><span>Уеб</span></button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                    {{-- Контекст: за коя секция добавям + подсказка --}}
                    <div class="mb-3 rounded-lg bg-surface-subtle px-3 py-2" x-show="focusedReq()">
                        <p class="text-[11px] text-muted">Добавям за: <span class="font-medium text-ink" x-text="focusedReq()?.label"></span></p>
                        <div class="ai-prose mt-1 text-xs text-muted" x-show="focusedReq()?.how_to_provide" x-html="$md(focusedReq()?.how_to_provide || '')"></div>
                    </div>

                    {{-- Таб: Текст --}}
                    <div x-show="tab === 'text'" class="space-y-2">
                        <input x-model="title" type="text" placeholder="Заглавие (напр. Бранд гайд - 2026)"
                               class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                        <textarea x-model="content" rows="9" placeholder="Постави или напиши информацията за тази секция тук…"
                                  class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm leading-relaxed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"></textarea>
                        <div>
                            <x-button size="sm" x-on:click="saveNote()" x-bind:disabled="busy">Запази бележката</x-button>
                        </div>
                    </div>

                    {{-- Таб: Файл --}}
                    <div x-show="tab === 'file'" class="space-y-2">
                        <label @dragover.prevent="dropActive = true" @dragleave.prevent="dropActive = false"
                               @drop.prevent="dropActive = false; uploadFiles($event.dataTransfer.files)"
                               class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-10 text-center transition"
                               :class="dropActive ? 'border-primary bg-primary/5' : 'border-line hover:border-primary/40'">
                            <x-icon name="arrow-up-tray" size="6" class="text-subtle" />
                            <p class="text-sm text-ink">Пусни файлове тук или <span class="text-primary">избери</span></p>
                            <p class="text-xs text-muted">PDF, DOCX, XLSX, XLS, CSV, TXT, MD, изображения (до 20 MB)</p>
                            <input type="file" multiple class="hidden" @change="uploadFiles($event.target.files)">
                        </label>
                    </div>

                    {{-- Таб: URL --}}
                    <div x-show="tab === 'url'" class="space-y-2">
                        <p class="text-xs text-muted">Добави сайт или конкретна страница - обхожда се и влиза в базата знания.</p>
                        <input x-model="url" type="url" placeholder="https://…"
                               class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                        <div>
                            <x-button size="sm" x-on:click="addUrl()" x-bind:disabled="busy">Добави URL</x-button>
                        </div>
                    </div>

                    {{-- Таб: От знание --}}
                    <div x-show="tab === 'knowledge'" class="space-y-2">
                        <p class="text-xs text-muted">Готови ресурси от базата знания, които може вече да покриват тази секция:</p>
                        <div x-show="suggestBusy" class="py-6 text-center text-xs text-muted">Търся…</div>
                        <template x-if="!suggestBusy && !suggestions.length">
                            <p class="py-6 text-center text-xs text-muted">Няма съвпадения. Добави ново знание от другите табове.</p>
                        </template>
                        <template x-for="s in suggestions" :key="s.id">
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-ink" x-text="s.title"></p>
                                    <p class="text-[11px] text-muted" x-text="s.type"></p>
                                </div>
                                <button type="button" @click="!busy && linkResource(s.id)"
                                        class="shrink-0 rounded-md border border-line px-2.5 py-1 text-xs text-ink hover:bg-surface-subtle">Свържи</button>
                            </div>
                        </template>
                    </div>

                    {{-- Таб: Интеграция --}}
                    <div x-show="tab === 'integration'" class="space-y-3">
                        <div x-show="connBusy && !connectorsLoaded" class="py-6 text-center text-xs text-muted">Зареждам интеграции…</div>
                        <template x-if="connectorsLoaded && !connectors.length">
                            <div class="rounded-lg border border-line px-3 py-6 text-center">
                                <p class="text-sm text-ink" x-text="connectorsError ? 'Грешка при зареждане на интеграциите.' : 'Няма свързани интеграции.'"></p>
                                <a x-show="!connectorsError" :href="cfg.integrationsUrl" target="_blank" class="mt-1 inline-block text-xs text-primary hover:text-primary-hover">Свържи интеграция →</a>
                                <button x-show="connectorsError" type="button" @click="loadConnectors()" class="mt-1 text-xs text-primary hover:text-primary-hover">Опитай пак</button>
                            </div>
                        </template>

                        <div x-show="connectors.length" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <template x-for="c in connectors" :key="c.id">
                                <button type="button" @click="selectConnector(c)"
                                        class="flex items-center gap-3 rounded-lg border px-3 py-2.5 text-left transition"
                                        :class="activeConnector?.id === c.id ? 'border-primary/40 bg-primary/5' : 'border-line hover:bg-surface-subtle'">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-line bg-surface-subtle text-lg" x-text="c.icon"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-ink" x-text="c.label"></span>
                                        <span class="block truncate text-[11px] text-muted" x-text="c.account"></span>
                                    </span>
                                </button>
                            </template>
                        </div>

                        <div x-show="activeConnector" class="space-y-2">
                            <template x-for="step in (activeConnector?.picker || [])" :key="step.param">
                                <div>
                                    <label class="mb-1 block text-[11px] font-medium text-muted" x-text="step.label"></label>
                                    {{-- Само-текстова стъпка --}}
                                    <template x-if="step.input === 'text'">
                                        <input type="text" x-model="pickerValues[step.param]" @change="onPickerChange(step)"
                                               class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                    </template>
                                    {{-- Source стъпка: dropdown ако конекторът може да листва и не сме в ръчен режим --}}
                                    <template x-if="step.source">
                                        <div>
                                            <template x-if="activeConnector.browsable && !pickerManual[step.param]">
                                                <div class="flex items-center gap-2">
                                                    <select x-model="pickerValues[step.param]" @change="onPickerChange(step)"
                                                            class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                                        <option value="">— избери —</option>
                                                        <template x-for="opt in (pickerOptions[step.param] || [])" :key="opt.value">
                                                            <option :value="opt.value" x-text="opt.label"></option>
                                                        </template>
                                                    </select>
                                                    <button type="button" x-show="step.manual_fallback" @click="pickerManual[step.param] = true"
                                                            class="shrink-0 text-xs text-subtle hover:text-ink" title="Въведи ID ръчно">ID</button>
                                                </div>
                                            </template>
                                            {{-- Ръчен режим или конекторът не може да листва (липсва scope) → текстов ID + подсказка --}}
                                            <template x-if="!activeConnector.browsable || pickerManual[step.param]">
                                                <div class="space-y-1">
                                                    <input type="text" x-model="pickerValues[step.param]" @change="onPickerChange(step)" placeholder="Постави ID…"
                                                           class="w-full rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                                    <p x-show="!activeConnector.browsable" class="text-[11px] text-muted">
                                                        Пресвържи <span x-text="activeConnector.label"></span> в <a :href="cfg.integrationsUrl" target="_blank" class="text-primary hover:text-primary-hover">Интеграции</a>, за да избираш от списък.
                                                    </p>
                                                    <button type="button" x-show="activeConnector.browsable" @click="pickerManual[step.param] = false; loadPickerOptions(step)"
                                                            class="text-[11px] text-primary hover:text-primary-hover">← избери от списък</button>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div class="flex items-center gap-2 pt-1">
                                <x-button size="sm" x-on:click="ingestConnector()" x-bind:disabled="busy">Импортирай</x-button>
                                <span x-show="connBusy" class="text-xs text-muted">…</span>
                            </div>
                        </div>
                    </div>

                    {{-- Таб: Уеб (само за публични изисквания) --}}
                    <div x-show="tab === 'web'" class="space-y-2">
                        <template x-if="!webAllowed()">
                            <p class="rounded-lg bg-surface-subtle px-3 py-6 text-center text-xs text-muted">
                                Уеб търсене е за публични изисквания. Избери вляво: <span class="font-medium text-ink" x-text="publicReqLabels()"></span>.
                            </p>
                        </template>
                        <template x-if="webAllowed()">
                            <div class="space-y-2">
                                <p class="text-xs text-muted">Търси в интернет и избери кои източници да влязат в тази секция.</p>
                                <div class="flex gap-2">
                                    <input x-model="searchQuery" type="text" placeholder="Заявка за търсене…"
                                           @keydown.enter="runSearch()"
                                           class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                    <x-button size="sm" variant="secondary" x-on:click="runSearch()" x-bind:disabled="busy">Търси</x-button>
                                </div>
                                <p x-show="searchError" x-text="searchError" class="text-xs text-danger"></p>
                                <div x-show="searchBusy" class="py-4 text-center text-xs text-muted">Търся…</div>

                                <div class="space-y-1.5">
                                    <template x-for="c in candidates" :key="c.url">
                                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line px-3 py-2 hover:bg-surface-subtle">
                                            <input type="checkbox" :value="c.url" @change="toggleCandidate(c.url)"
                                                   :checked="selectedUrls.includes(c.url)" class="mt-1 shrink-0">
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm text-ink" x-text="c.title"></span>
                                                <span class="block truncate text-[11px] text-primary" x-text="c.url"></span>
                                                <span class="mt-0.5 block text-xs text-muted" x-text="c.snippet"></span>
                                            </span>
                                        </label>
                                    </template>
                                </div>
                                <div x-show="candidates.length">
                                    <x-button size="sm" x-on:click="ingestSelected()" x-bind:disabled="busy">
                                        Добави избраните (<span x-text="selectedUrls.length"></span>)
                                    </x-button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Per-секция проверка (статус на избраната вляво секция) --}}
                <div class="flex items-center justify-between gap-2 border-t border-line px-4 py-2.5">
                    <span class="inline-flex items-center gap-1.5 text-xs" x-show="focusedReq()">
                        <span class="text-muted">Статус:</span>
                        <span class="font-medium"
                              :class="{ 'text-subtle': focusColor() === 'grey', 'text-warning': focusColor() === 'yellow', 'text-success': focusColor() === 'green', 'text-danger': focusColor() === 'red' }"
                              x-text="statusLabel()"></span>
                    </span>
                    <x-button size="sm" variant="secondary" x-on:click="checkSection()" x-bind:disabled="busy">Провери</x-button>
                </div>

                {{-- Футър: прогрес + Продължи --}}
                <div class="flex items-center justify-between gap-2 border-t border-line px-4 py-3">
                    <a :href="cfg.kbUrl" target="_blank" class="text-xs text-primary hover:text-primary-hover">Пълна база знания →</a>
                    <div class="flex items-center gap-2">
                        <span x-show="stage" class="inline-flex items-center gap-1 text-xs text-accent"><x-org.bolt-spinner size="14" /><span x-text="stage"></span></span>
                        <span class="text-xs text-muted"><span x-text="greenCount()"></span> / <span x-text="reqs.length"></span> готови</span>
                        <x-button size="sm" x-on:click="proceed()" x-bind:disabled="busy || !allGreen()">Продължи</x-button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
window.knowledgeModal = function (cfg) {
    return {
        cfg, open: false, taskId: null, reqs: [], sources: [],
        focusKey: null, tab: 'text',
        drafts: {}, touchedKeys: [], checkedKeys: [],
        title: '', content: '', url: '',
        suggestions: [], suggestBusy: false,
        connectors: [], connectorsLoaded: false, connBusy: false, connectorsError: false,
        activeConnector: null, pickerValues: {}, pickerOptions: {}, pickerManual: {},
        searchQuery: '', candidates: [], selectedUrls: [], searchBusy: false, searchError: '',
        dropActive: false,
        stage: '', busy: false, msg: '', _poll: null,
        tabCls(id) { return this.tab === id ? 'bg-surface-subtle text-ink ring-1 ring-line' : 'text-muted hover:text-ink'; },

        openFor(d) {
            this.taskId = d.taskId; this.reqs = d.requirements || []; this.sources = [];
            this.drafts = {}; this.reqs.forEach(r => { this.drafts[r.key] = { title: '', content: '', url: '' }; });
            this.touchedKeys = []; this.checkedKeys = [];
            this.title = ''; this.content = ''; this.url = '';
            this.suggestions = []; this.connectors = []; this.connectorsLoaded = false; this.connectorsError = false;
            this.activeConnector = null; this.pickerValues = {}; this.pickerOptions = {};
            this.searchQuery = ''; this.candidates = []; this.selectedUrls = []; this.searchError = '';
            this.tab = 'text'; this.stage = ''; this.msg = ''; this.busy = false;
            this.focusKey = (this.firstMissing() || this.reqs[0] || {}).key || null;
            this.open = true;
            this.loadDraft(); this._refreshFocusQuery();
            this.refreshSources();
        },
        close() { this.open = false; if (this._poll) { clearInterval(this._poll); this._poll = null; } },

        privateReqs() { return this.reqs.filter(r => r.sourceability !== 'public'); },
        publicReqs() { return this.reqs.filter(r => r.sourceability === 'public'); },
        firstMissing() { return this.reqs.find(r => r.status !== 'covered'); },
        focusedReq() { return this.reqs.find(r => r.key === this.focusKey) || null; },

        // ── Per-секция цвят на кръгчето ───────────────────────────────────
        sourcesFor(key) { return this.sources.filter(s => s.requirement_key === key); },
        isPending(key) { return this.sourcesFor(key).some(s => s.status === 'pending' || s.status === 'processing'); },
        hasSources(key) { return this.touchedKeys.includes(key) || this.sourcesFor(key).length > 0; },
        circleColor(r) {
            if (this.isPending(r.key)) return 'yellow';
            // Публично изискване, одобрено за външно сорсване → сървърът го отпушва (зелено),
            // независимо от семантичното покритие.
            if (r.sourceability === 'public' && r.acknowledged) return 'green';
            if (this.checkedKeys.includes(r.key)) return r.status === 'covered' ? 'green' : 'red';
            if (r.status === 'covered' && !this.hasSources(r.key)) return 'green';
            if (this.hasSources(r.key)) return 'yellow';
            return 'grey';
        },
        webAllowed() { const r = this.focusedReq(); return !!(r && r.sourceability === 'public'); },
        hasPublicReqs() { return this.reqs.some(r => r.sourceability === 'public'); },
        publicReqLabels() { return this.reqs.filter(r => r.sourceability === 'public').map(r => r.label).join(', '); },
        statusLabel() {
            const r = this.focusedReq(); if (!r) return '';
            return ({ grey: 'не е добавено', yellow: 'чака проверка', green: 'покрито', red: 'недостатъчно' })[this.circleColor(r)] || '';
        },
        focusColor() { const r = this.focusedReq(); return r ? this.circleColor(r) : 'grey'; },
        greenCount() { return this.reqs.filter(r => this.circleColor(r) === 'green').length; },
        allGreen() { return this.reqs.length > 0 && this.reqs.every(r => this.circleColor(r) === 'green'); },

        markTouched(key) { if (key && !this.touchedKeys.includes(key)) this.touchedKeys.push(key); },
        markChecked(key) { if (key && !this.checkedKeys.includes(key)) this.checkedKeys.push(key); },
        unmarkChecked(key) { const i = this.checkedKeys.indexOf(key); if (i >= 0) this.checkedKeys.splice(i, 1); },

        // ── Per-секция чернови (текст/URL) ────────────────────────────────
        saveDraft() { if (this.focusKey) this.drafts[this.focusKey] = { title: this.title, content: this.content, url: this.url }; },
        loadDraft() { const d = this.drafts[this.focusKey] || {}; this.title = d.title || ''; this.content = d.content || ''; this.url = d.url || ''; },
        focusReq(key) {
            this.saveDraft(); this.focusKey = key; this.loadDraft(); this.msg = '';
            this._refreshFocusQuery();
            if (this.tab === 'knowledge') this.loadSuggestions();
        },
        _refreshFocusQuery() {
            const r = this.focusedReq();
            if (r && r.sourceability === 'public' && r.query) this.searchQuery = r.query;
        },
        setTab(t) {
            if (t === 'web' && !cfg.searchEnabled) return;
            this.tab = t; this.msg = '';
            if (t === 'integration' && !this.connectorsLoaded) this.loadConnectors();
            if (t === 'knowledge') this.loadSuggestions();
            if (t === 'web') this._refreshFocusQuery();
        },

        _url(tpl) { return tpl.replace('__TASK__', this.taskId); },
        _post(tpl, body) {
            return fetch(this._url(tpl), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body || {}) }).then(r => r.json());
        },
        _applyStatus(d) {
            if (d.ingest_failed) this.msg = d.message || 'Обработката на знанието се провали.';
            if (d.requirements) this.reqs = d.requirements;
            if (d.sources) this.sources = d.sources;
        },
        // Следи прогреса на ingest след добавяне (без оценка). Спира при завършване.
        _startPoll() {
            if (this._poll) clearInterval(this._poll);
            this.busy = true; this.stage = 'обработва се…';
            const tick = async () => {
                try {
                    const d = await (await fetch(this._url(cfg.statusTpl), { headers: { 'Accept': 'application/json' } })).json();
                    this._applyStatus(d);
                    if (!d.ingesting) { clearInterval(this._poll); this._poll = null; this.busy = false; this.stage = ''; }
                } catch (e) {}
            };
            tick(); this._poll = setInterval(tick, 2500);
        },
        refreshSources() {
            fetch(this._url(cfg.statusTpl), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => {
                    this._applyStatus(d);
                    // Ако секциите идват от сървъра (не подадени в event-а), инициализирай чернови + фокус.
                    this.reqs.forEach(r => { if (!this.drafts[r.key]) this.drafts[r.key] = { title: '', content: '', url: '' }; });
                    if (!this.focusKey && this.reqs.length) {
                        this.focusKey = (this.firstMissing() || this.reqs[0]).key;
                        this.loadDraft(); this._refreshFocusQuery();
                    }
                    if (d.ingesting) this._startPoll();
                })
                .catch(() => {});
        },
        _afterAdd() { this.markTouched(this.focusKey); this.unmarkChecked(this.focusKey); this._startPoll(); },
        // Сървърна грешка/валидация (Laravel 422 → {message, errors}) → покажи, не третирай като успех.
        _reject(d) {
            if (d && (d.errors || d.error)) {
                this.busy = false; this.stage = '';
                this.msg = d.message || d.error || 'Възникна грешка.';
                return true;
            }
            return false;
        },

        // ── Добавяне на знание (per активна секция) ───────────────────────
        saveNote() {
            if (this.busy) return;
            if (!this.title.trim() || !this.content.trim()) { this.msg = 'Попълни заглавие и съдържание.'; return; }
            this._post(cfg.noteTpl, { title: this.title, content: this.content, requirement_key: this.focusKey })
                .then(d => { if (this._reject(d)) return; this.title = ''; this.content = ''; this.saveDraft(); this._afterAdd(); })
                .catch(() => { this.msg = 'Грешка при запазване.'; });
        },
        uploadFiles(fileList) {
            const files = Array.from(fileList || []);
            if (!files.length || this.busy) return;
            const fd = new FormData();
            files.forEach(f => fd.append('files[]', f));
            if (this.focusKey) fd.append('requirement_key', this.focusKey);
            this.dropActive = false; this.busy = true; this.stage = 'качвам…';
            fetch(this._url(cfg.uploadTpl), { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (this._reject(d)) return;
                    if (Array.isArray(d.created) && d.created.length === 0 && Array.isArray(d.skipped) && d.skipped.length) {
                        this.busy = false; this.stage = ''; this.msg = 'Файловете вече са добавени.'; return;
                    }
                    this._afterAdd();
                })
                .catch(() => { this.busy = false; this.stage = ''; this.msg = 'Грешка при качване.'; });
        },
        addUrl() {
            if (!this.url.trim() || this.busy) return;
            this.msg = '';
            this._post(cfg.urlTpl, { url: this.url, requirement_key: this.focusKey })
                .then(d => { if (this._reject(d)) return; this.url = ''; this.saveDraft(); this._afterAdd(); })
                .catch(() => { this.msg = 'Грешка.'; });
        },
        loadSuggestions() {
            this.suggestBusy = true;
            const p = new URLSearchParams(); if (this.focusKey) p.append('requirement_key', this.focusKey);
            fetch(this._url(cfg.suggestTpl) + '?' + p.toString(), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => { this.suggestions = d.resources || []; })
                .catch(() => { this.suggestions = []; })
                .finally(() => { this.suggestBusy = false; });
        },
        linkResource(id) {
            if (this.busy) return;
            this.busy = true;
            this._post(cfg.linkTpl, { resource_id: id, requirement_key: this.focusKey })
                .then(d => { this.busy = false; this._applyStatus(d); this.markTouched(this.focusKey); this.checkSection(); })
                .catch(() => { this.busy = false; });
        },
        loadConnectors() {
            this.connBusy = true; this.connectorsError = false;
            fetch(this._url(cfg.connectorsTpl), { headers: { 'Accept': 'application/json' } })
                .then(r => { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
                .then(d => { this.connectors = d.connectors || []; })
                .catch(() => { this.connectors = []; this.connectorsError = true; })
                .finally(() => { this.connBusy = false; this.connectorsLoaded = true; });
        },
        selectConnector(c) {
            this.activeConnector = c; this.pickerValues = {}; this.pickerOptions = {}; this.pickerManual = {};
            (c.picker || []).forEach(s => { this.pickerValues[s.param] = ''; this.pickerManual[s.param] = false; });
            // Зареди опциите на ВСИЧКИ source стъпки (не само top-level): drive_files с празна
            // папка листва всички файлове; зависимите с празен контекст връщат [] (безвредно).
            (c.picker || []).forEach(s => { if (s.source) this.loadPickerOptions(s); });
        },
        loadPickerOptions(step) {
            if (!step.source) return;
            this.connBusy = true;
            const params = new URLSearchParams({ connector_id: this.activeConnector.id, source: step.source });
            Object.entries(this.pickerValues).forEach(([k, v]) => { if (v) params.append('context[' + k + ']', v); });
            fetch(this._url(cfg.connectorOptionsTpl) + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => { this.pickerOptions[step.param] = d.options || []; })
                .catch(() => { this.pickerOptions[step.param] = []; })
                .finally(() => { this.connBusy = false; });
        },
        onPickerChange(changedStep) {
            (this.activeConnector?.picker || []).forEach(s => {
                if (s.depends_on === changedStep.param) { this.pickerValues[s.param] = ''; this.pickerOptions[s.param] = []; this.loadPickerOptions(s); }
            });
        },
        canImport() {
            if (!this.activeConnector) return false;
            return (this.activeConnector.picker || []).every(s => s.optional || (this.pickerValues[s.param] || '').toString().trim() !== '');
        },
        ingestConnector() {
            if (this.busy) return;
            if (!this.canImport()) { this.msg = 'Избери файл/източник.'; return; }
            this.msg = ''; this.busy = true; this.stage = 'импортирам…';
            this._post(cfg.connectorIngestTpl, { connector_id: this.activeConnector.id, file_ref: this.pickerValues, requirement_key: this.focusKey })
                .then(d => { if (this._reject(d)) return; this._afterAdd(); })
                .catch(() => { this.busy = false; this.stage = ''; this.msg = 'Грешка при импорт.'; });
        },
        runSearch() {
            if (this.busy) return;
            if (!this.webAllowed()) { this.searchError = 'Уеб търсене е само за публични изисквания.'; return; }
            this.searchError = ''; this.candidates = []; this.selectedUrls = [];
            this.busy = true; this.searchBusy = true; this.stage = 'търся…';
            this._post(cfg.researchTpl, { query: this.searchQuery, requirement_key: this.focusKey })
                .then(d => {
                    this.busy = false; this.searchBusy = false; this.stage = '';
                    if (d.error) { this.searchError = d.message || 'Търсенето не е налично.'; return; }
                    this.candidates = d.candidates || []; if (d.query) this.searchQuery = d.query;
                    if (!this.candidates.length) this.searchError = 'Няма резултати.';
                })
                .catch(() => { this.busy = false; this.searchBusy = false; this.stage = ''; this.searchError = 'Търсенето се провали.'; });
        },
        toggleCandidate(url) { const i = this.selectedUrls.indexOf(url); i >= 0 ? this.selectedUrls.splice(i, 1) : this.selectedUrls.push(url); },
        ingestSelected() {
            if (!this.selectedUrls.length || this.busy) return;
            this.msg = ''; this.busy = true; this.stage = 'добавям избраните…';
            this._post(cfg.researchIngestTpl, { urls: this.selectedUrls, requirement_key: this.focusKey, query: this.searchQuery })
                .then(d => {
                    if (d && (d.error || d.errors)) { this.busy = false; this.stage = ''; this.searchError = d.message || d.error || 'Грешка при добавяне.'; return; }
                    this.candidates = []; this.selectedUrls = []; this._afterAdd();
                })
                .catch(() => { this.busy = false; this.stage = ''; this.msg = 'Грешка.'; });
        },

        // ── „Провери" за активната секция (форсирана оценка) ──────────────
        checkSection() {
            if (this.busy || !this.focusKey) return;
            this._runCheck(this.focusKey);
        },
        _runCheck(key) {
            if (!this.open) return;
            this.busy = true; this.stage = 'проверявам…';
            this._post(cfg.checkTpl, {})
                .then(d => {
                    if (d.ingesting) { this.stage = 'обработва се…'; setTimeout(() => this._runCheck(key), 2000); return; }
                    this.busy = false; this.stage = '';
                    this._applyStatus(d);
                    this.markChecked(key); // фиксираната секция, не текущия фокус
                })
                .catch(() => { this.busy = false; this.stage = ''; this.msg = 'Грешка при проверка.'; });
        },

        // ── „Продължи" (само когато всички са зелени) ─────────────────────
        proceed() {
            if (this.busy || !this.allGreen()) return;
            this.busy = true; this.stage = 'продължавам…';
            this._post(cfg.proceedTpl, {})
                .then(d => {
                    if (d.status === 'running' || d.status === 'generating') { window.location.reload(); return; }
                    this.busy = false; this.stage = '';
                    this._applyStatus(d);
                    this.msg = d.message || 'Все още липсва информация. Провери секциите.';
                })
                .catch(() => { this.busy = false; this.stage = ''; this.msg = 'Грешка.'; });
        },
    };
};
</script>
@endpush
@endonce
