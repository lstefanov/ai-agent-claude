@extends('layouts.app')

@section('title', 'База знания — ' . $company->name)

@section('content')
<div x-data="knowledgePage(@js($config))" x-init="init()">

    {{-- ─────────── Header ─────────── --}}
    <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
            <a :href="config.backUrl" class="text-indigo-600 hover:underline text-sm">← {{ $company->name }}</a>
            <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3">
                📚 База знания
                <span class="text-sm font-normal text-gray-400" x-show="!loading"
                      x-text="stats.documents + ' документа · ' + stats.chunks + ' откъса'"></span>
            </h1>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            {{-- Enabled toggle (шаблонът на „Памет" панела) --}}
            <button @click="toggleEnabled()"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border transition"
                    :class="enabled ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-50 border-gray-200 text-gray-400'">
                <span class="w-2 h-2 rounded-full" :class="enabled ? 'bg-green-500' : 'bg-gray-300'"></span>
                <span x-text="enabled ? 'Включена' : 'Изключена'"></span>
            </button>
            <button x-show="site.website_url" x-cloak @click="refreshSite()"
                    :disabled="busy"
                    :title="'Извлича страниците на ' + site.website_url + ' в базата знания' + (site.synced_at ? ' (последно: ' + formatDate(site.synced_at) + ')' : '')"
                    class="bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50">
                🌐 Обнови от сайта
            </button>
            <select x-show="site.website_url" x-cloak x-model="site.recrawl" @change="setRecrawl()"
                    title="Автоматично пре-обхождане на сайта"
                    class="border border-gray-300 rounded-lg px-2 py-2 text-sm text-gray-600 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="off">Без автообновяване</option>
                <option value="daily">Всеки ден</option>
                <option value="weekly">Всяка седмица</option>
            </select>
            <label class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition cursor-pointer flex items-center gap-2"
                   :class="uploading && 'opacity-60 pointer-events-none'">
                <span x-show="!uploading">⬆ Качи файлове</span>
                <span x-show="uploading">Качване…</span>
                <input type="file" multiple class="hidden" @change="uploadFiles($event)"
                       accept=".pdf,.txt,.md,.docx,.xlsx,.csv,.jpg,.jpeg,.png">
            </label>
        </div>
    </div>

    {{-- Provider mismatch banner --}}
    <div x-show="stats.foreign_provider_chunks > 0" x-cloak
         class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl text-sm">
        ⚠ <span x-text="stats.foreign_provider_chunks"></span> откъса са индексирани с друг embedding провайдър
        (текущ: <span class="font-mono" x-text="stats.provider_tag"></span>) и не участват в търсенето —
        преиндексирай засегнатите документи с ↻.
    </div>

    <div x-show="error" x-cloak class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm" x-text="error"></div>

    <div class="flex gap-6 items-start">

        {{-- ─────────── Sidebar: папки ─────────── --}}
        <div class="w-64 shrink-0 bg-white rounded-xl border border-gray-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-700">Папки</h2>
            </div>

            <button @click="selectedFolder = null"
                    class="w-full text-left px-2 py-1.5 rounded-lg text-sm mb-1 transition"
                    :class="selectedFolder === null ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50'">
                Всички
                <span class="text-xs text-gray-400 float-right mt-0.5" x-text="documents.length"></span>
            </button>

            <template x-for="folder in folderTree()" :key="folder.id">
                <div class="group flex items-center gap-1 rounded-lg transition"
                     :class="selectedFolder === folder.id ? 'bg-indigo-50' : 'hover:bg-gray-50'">
                    <button @click="selectedFolder = folder.id"
                            class="flex-1 text-left px-2 py-1.5 text-sm truncate"
                            :class="selectedFolder === folder.id ? 'text-indigo-700 font-medium' : 'text-gray-600'"
                            :style="'padding-left:' + (8 + folder.depth * 14) + 'px'">
                        <span x-show="renaming !== folder.id">📁 <span x-text="folder.name"></span>
                            <span class="text-xs text-gray-400" x-text="'(' + folder.doc_count + ')'"></span>
                        </span>
                    </button>
                    <input x-show="renaming === folder.id" x-cloak type="text"
                           class="flex-1 border border-indigo-300 rounded px-1.5 py-0.5 text-sm mx-1"
                           :id="'rename-' + folder.id" :value="folder.name"
                           @keydown.enter="renameFolder(folder.id, $event.target.value)"
                           @keydown.escape="renaming = null">
                    <div class="opacity-0 group-hover:opacity-100 flex shrink-0 pr-1">
                        <button @click="startRename(folder.id)" title="Преименувай"
                                class="p-1 text-gray-400 hover:text-indigo-600 text-xs">✏</button>
                        <button @click="addSubfolder(folder.id)" title="Подпапка"
                                class="p-1 text-gray-400 hover:text-indigo-600 text-xs">＋</button>
                        <button @click="deleteFolder(folder)" title="Изтрий"
                                class="p-1 text-gray-400 hover:text-red-600 text-xs">🗑</button>
                    </div>
                </div>
            </template>

            <div class="mt-3 flex gap-1">
                <input type="text" x-model="newFolderName" placeholder="Нова папка…"
                       @keydown.enter="createFolder()"
                       class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <button @click="createFolder()"
                        class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm text-gray-600 transition">＋</button>
            </div>

            {{-- Source филтър --}}
            <div class="mt-4 pt-3 border-t border-gray-100">
                <h3 class="text-xs font-semibold text-gray-400 uppercase mb-2">Източник</h3>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="f in sourceFilters" :key="f.key">
                        <button @click="sourceFilter = f.key"
                                class="px-2 py-1 rounded-full text-xs font-medium transition"
                                :class="sourceFilter === f.key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                                x-text="f.label"></button>
                    </template>
                </div>
            </div>
        </div>

        {{-- ─────────── Main ─────────── --}}
        <div class="flex-1 min-w-0 space-y-6">

            {{-- Documents table --}}
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
                    <input type="text" x-model="search" placeholder="Търси по заглавие…"
                           class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <span class="text-xs text-gray-400" x-show="busy">
                        <span class="inline-block animate-spin mr-1">◌</span> обработка…
                    </span>
                    <span class="text-xs text-gray-400" x-text="'общо $' + (stats.cost_usd || 0).toFixed(4)"></span>
                </div>

                <div x-show="loading" class="px-4 py-10 text-center text-gray-400 text-sm">Зареждане…</div>

                <div x-show="!loading && filteredDocs().length === 0" x-cloak
                     class="px-4 py-12 text-center">
                    <p class="text-2xl mb-2">📄</p>
                    <p class="text-gray-500 font-medium mb-1">Няма документи</p>
                    <p class="text-gray-400 text-sm">Качи ценоразписи, каталози, условия — агентите ще ги ползват като достоверен източник.</p>
                </div>

                <table x-show="!loading && filteredDocs().length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-400 uppercase border-b border-gray-100">
                            <th class="px-4 py-2 cursor-pointer hover:text-gray-600" @click="sortBy('title')">Заглавие</th>
                            <th class="px-2 py-2">Папка</th>
                            <th class="px-2 py-2">Тип</th>
                            <th class="px-2 py-2 cursor-pointer hover:text-gray-600" @click="sortBy('status')">Статус</th>
                            <th class="px-2 py-2 text-right">Чанкове</th>
                            <th class="px-2 py-2 text-right">Размер</th>
                            <th class="px-2 py-2 text-right">Цена</th>
                            <th class="px-2 py-2 cursor-pointer hover:text-gray-600" @click="sortBy('created_at')">Дата</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="doc in pagedDocs()" :key="doc.id">
                            <tr class="border-b border-gray-50 hover:bg-gray-50 group">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-800 truncate max-w-xs" x-text="doc.title" :title="doc.original_name || doc.source_url"></div>
                                </td>
                                <td class="px-2 py-2.5 text-gray-500 text-xs" x-text="folderName(doc.folder_id)"></td>
                                <td class="px-2 py-2.5">
                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium"
                                          :class="{
                                              'bg-blue-50 text-blue-600': doc.source_type === 'upload',
                                              'bg-emerald-50 text-emerald-600': doc.source_type === 'site',
                                              'bg-purple-50 text-purple-600': doc.source_type === 'run',
                                              'bg-gray-100 text-gray-500': doc.source_type === 'url',
                                          }"
                                          x-text="sourceLabel(doc.source_type)"></span>
                                </td>
                                <td class="px-2 py-2.5">
                                    <span x-show="doc.status === 'pending'" title="Чака обработка">⏳</span>
                                    <span x-show="doc.status === 'processing'" class="inline-block animate-spin text-indigo-500" title="Обработва се">◌</span>
                                    <span x-show="doc.status === 'ready'" class="text-green-600" title="Готов">✓</span>
                                    <span x-show="doc.status === 'failed'" class="text-red-500 cursor-help" :title="doc.error">✗</span>
                                </td>
                                <td class="px-2 py-2.5 text-right text-gray-500" x-text="doc.chunk_count"></td>
                                <td class="px-2 py-2.5 text-right text-gray-400 text-xs" x-text="formatSize(doc.size_bytes)"></td>
                                <td class="px-2 py-2.5 text-right text-gray-400 text-xs" x-text="doc.cost_usd ? '$' + doc.cost_usd.toFixed(4) : '—'"></td>
                                <td class="px-2 py-2.5 text-gray-400 text-xs" x-text="doc.created_at"></td>
                                <td class="px-2 py-2.5 text-right whitespace-nowrap">
                                    <div class="opacity-0 group-hover:opacity-100 transition-opacity inline-flex gap-1">
                                        <button @click="reingest(doc)" title="Преиндексирай"
                                                class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">↻</button>
                                        <button @click="deleteDocument(doc)" title="Изтрий"
                                                class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50">🗑</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                {{-- Paging --}}
                <div x-show="!loading && filteredDocs().length > pageSize" x-cloak
                     class="flex items-center justify-between px-4 py-2.5 border-t border-gray-100 text-sm text-gray-500">
                    <span x-text="'Стр. ' + page + ' от ' + totalPages()"></span>
                    <div class="flex gap-1">
                        <button @click="page = Math.max(1, page - 1)" :disabled="page === 1"
                                class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40">←</button>
                        <button @click="page = Math.min(totalPages(), page + 1)" :disabled="page === totalPages()"
                                class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-40">→</button>
                    </div>
                </div>
            </div>

            {{-- ─────────── Тест на търсенето ─────────── --}}
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <h2 class="text-sm font-semibold text-gray-700 mb-3">🔍 Тествай търсене в базата знания</h2>
                <div class="flex gap-2 mb-3">
                    <input type="text" x-model="searchTest.query" placeholder="напр. цена на лазерна епилация цели крака"
                           @keydown.enter="runSearchTest()"
                           class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <button @click="runSearchTest()" :disabled="searchTest.running"
                            class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50">
                        <span x-show="!searchTest.running">Търси</span>
                        <span x-show="searchTest.running">Търсене…</span>
                    </button>
                </div>
                <div x-show="searchTest.done && searchTest.results.length === 0" x-cloak
                     class="text-sm text-gray-400 py-2">Нищо релевантно не беше намерено.</div>
                <div class="space-y-2">
                    <template x-for="(hit, i) in searchTest.results" :key="i">
                        <div class="border border-gray-100 rounded-lg p-3 bg-gray-50">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 text-xs font-mono"
                                      x-text="hit.score.toFixed(2)"></span>
                                <span class="font-medium text-sm text-gray-800" x-text="'«' + hit.title + '»'"></span>
                                <span class="text-xs text-gray-400" x-text="sourceLabel(hit.source_type)"></span>
                            </div>
                            <p class="text-xs text-gray-600 whitespace-pre-line line-clamp-4" x-text="hit.content"></p>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function knowledgePage(config) {
    return {
        config,
        enabled: true, loading: true, error: '', busy: false,
        folders: [], documents: [], stats: {}, site: {},
        selectedFolder: null, sourceFilter: 'all',
        search: '', sortCol: 'created_at', sortDir: 'desc', page: 1, pageSize: 15,
        uploading: false, newFolderName: '', renaming: null,
        searchTest: { query: '', running: false, done: false, results: [] },
        pollTimer: null,
        sourceFilters: [
            { key: 'all', label: 'Всички' },
            { key: 'upload', label: 'Документи' },
            { key: 'site', label: 'Сайт' },
        ],

        init() { this.refresh(); },

        async api(path, options = {}) {
            const res = await fetch(this.config.base + path, {
                headers: {
                    'X-CSRF-TOKEN': this.config.csrf,
                    'Accept': 'application/json',
                    ...(options.json ? { 'Content-Type': 'application/json' } : {}),
                },
                method: options.method || 'GET',
                body: options.json ? JSON.stringify(options.json) : (options.body || undefined),
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.error || data.message || ('Грешка ' + res.status));
            }
            return res.json();
        },

        async refresh() {
            try {
                const data = await this.api('/data');
                this.enabled = data.enabled;
                this.folders = data.folders;
                this.documents = data.documents;
                this.stats = data.stats;
                this.site = data.site || {};
                this.busy = data.busy;
                this.error = '';
                clearTimeout(this.pollTimer);
                if (this.busy) this.pollTimer = setTimeout(() => this.refresh(), 4000);
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async toggleEnabled() {
            try { this.enabled = (await this.api('/toggle', { method: 'POST' })).enabled; }
            catch (e) { this.error = e.message; }
        },

        // ── Сайт ──
        async refreshSite() {
            try {
                await this.api('/refresh-site', { method: 'POST', json: {} });
                this.busy = true;
                clearTimeout(this.pollTimer);
                this.pollTimer = setTimeout(() => this.refresh(), 4000);
            } catch (e) { this.error = e.message; }
        },
        async setRecrawl() {
            try { await this.api('/recrawl-setting', { method: 'POST', json: { recrawl: this.site.recrawl } }); }
            catch (e) { this.error = e.message; }
        },
        formatDate(iso) {
            try { return new Date(iso).toLocaleString('bg-BG', { dateStyle: 'short', timeStyle: 'short' }); }
            catch { return iso; }
        },

        // ── Папки ──
        folderTree() {
            const byParent = {};
            this.folders.forEach(f => (byParent[f.parent_id || 0] ||= []).push(f));
            const out = [];
            const walk = (parentId, depth) => (byParent[parentId] || []).forEach(f => {
                out.push({ ...f, depth });
                walk(f.id, depth + 1);
            });
            walk(0, 0);
            return out;
        },
        folderName(id) {
            return id ? (this.folders.find(f => f.id === id)?.name || '—') : '—';
        },
        async createFolder(parentId = null) {
            const name = this.newFolderName.trim();
            if (!name) return;
            try {
                await this.api('/folders', { method: 'POST', json: { name, parent_id: parentId } });
                this.newFolderName = '';
                this.refresh();
            } catch (e) { this.error = e.message; }
        },
        addSubfolder(parentId) {
            const name = prompt('Име на подпапката:');
            if (!name || !name.trim()) return;
            this.api('/folders', { method: 'POST', json: { name: name.trim(), parent_id: parentId } })
                .then(() => this.refresh()).catch(e => this.error = e.message);
        },
        startRename(id) {
            this.renaming = id;
            this.$nextTick(() => document.getElementById('rename-' + id)?.focus());
        },
        async renameFolder(id, name) {
            if (!name.trim()) { this.renaming = null; return; }
            try {
                await this.api('/folders/' + id, { method: 'PATCH', json: { name: name.trim() } });
                this.renaming = null;
                this.refresh();
            } catch (e) { this.error = e.message; }
        },
        async deleteFolder(folder) {
            if (!confirm('Изтрий папка „' + folder.name + '“? Документите в нея остават (падат в корена).')) return;
            try {
                await this.api('/folders/' + folder.id, { method: 'DELETE' });
                if (this.selectedFolder === folder.id) this.selectedFolder = null;
                this.refresh();
            } catch (e) { this.error = e.message; }
        },

        // ── Документи ──
        async uploadFiles(event) {
            const files = event.target.files;
            if (!files.length) return;
            this.uploading = true;
            const form = new FormData();
            [...files].forEach(f => form.append('files[]', f));
            if (this.selectedFolder) form.append('folder_id', this.selectedFolder);
            try {
                const result = await this.api('/documents', { method: 'POST', body: form });
                if (result.skipped?.length) {
                    this.error = 'Пропуснати (вече качени): ' + result.skipped.join(', ');
                }
                this.refresh();
            } catch (e) { this.error = e.message; }
            finally {
                this.uploading = false;
                event.target.value = '';
            }
        },
        async reingest(doc) {
            try { await this.api('/documents/' + doc.id + '/reingest', { method: 'POST' }); this.refresh(); }
            catch (e) { this.error = e.message; }
        },
        async deleteDocument(doc) {
            if (!confirm('Изтрий „' + doc.title + '“ от базата знания?')) return;
            try { await this.api('/documents/' + doc.id, { method: 'DELETE' }); this.refresh(); }
            catch (e) { this.error = e.message; }
        },

        // ── Таблица ──
        filteredDocs() {
            let docs = this.documents;
            if (this.selectedFolder !== null) docs = docs.filter(d => d.folder_id === this.selectedFolder);
            if (this.sourceFilter !== 'all') docs = docs.filter(d => d.source_type === this.sourceFilter);
            const q = this.search.trim().toLowerCase();
            if (q) docs = docs.filter(d => (d.title || '').toLowerCase().includes(q));
            const dir = this.sortDir === 'asc' ? 1 : -1;
            const col = this.sortCol;
            return [...docs].sort((a, b) => (a[col] > b[col] ? 1 : a[col] < b[col] ? -1 : 0) * dir);
        },
        pagedDocs() {
            return this.filteredDocs().slice((this.page - 1) * this.pageSize, this.page * this.pageSize);
        },
        totalPages() { return Math.max(1, Math.ceil(this.filteredDocs().length / this.pageSize)); },
        sortBy(col) {
            if (this.sortCol === col) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            else { this.sortCol = col; this.sortDir = 'desc'; }
            this.page = 1;
        },
        sourceLabel(type) {
            return { upload: 'документ', site: 'сайт', url: 'URL', run: 'история' }[type] || type;
        },
        formatSize(bytes) {
            if (!bytes) return '—';
            return bytes > 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
        },

        // ── Тест на търсенето ──
        async runSearchTest() {
            const query = this.searchTest.query.trim();
            if (!query) return;
            this.searchTest.running = true;
            this.searchTest.done = false;
            try {
                this.searchTest.results = (await this.api('/search-test', { method: 'POST', json: { query } })).hits;
                this.searchTest.done = true;
            } catch (e) { this.error = e.message; }
            finally { this.searchTest.running = false; }
        },
    };
}
</script>
@endpush
