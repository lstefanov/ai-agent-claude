@extends('layouts.app')

@section('title', 'База знания — ' . $company->name)

@push('head')
{{-- Markdown рендиране + sanitизация на чат отговорите (CDN, като останалите libs). --}}
<script src="https://cdn.jsdelivr.net/npm/marked@12/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<style>
    /* Чат: стилизиран markdown (token-driven) */
    .kb-prose { line-height: 1.55; }
    .kb-prose > *:first-child { margin-top: 0; }
    .kb-prose > *:last-child { margin-bottom: 0; }
    .kb-prose p { margin: 0 0 0.5rem; }
    .kb-prose ul, .kb-prose ol { margin: 0.25rem 0 0.6rem; padding-left: 1.15rem; }
    .kb-prose li { margin: 0.15rem 0; }
    .kb-prose ul { list-style: disc; }
    .kb-prose ol { list-style: decimal; }
    .kb-prose strong { font-weight: 600; color: var(--color-ink); }
    .kb-prose a { color: var(--color-primary); text-decoration: underline; }
    .kb-prose code { background: var(--color-info-soft); color: var(--color-primary-hover); padding: 1px 5px; border-radius: 4px; font-size: 0.85em; font-family: var(--font-mono); }
    .kb-prose pre { background: var(--color-surface-subtle); border: 1px solid var(--color-line); border-radius: 8px; padding: 0.6rem 0.75rem; overflow-x: auto; margin: 0 0 0.6rem; }
    .kb-prose pre code { background: none; padding: 0; color: var(--color-muted); }
    .kb-prose h2, .kb-prose h3, .kb-prose h4 { font-weight: 600; color: var(--color-ink); margin: 0.6rem 0 0.35rem; font-size: 0.95rem; }
    .kb-prose blockquote { border-left: 3px solid var(--color-line-strong); padding-left: 0.65rem; color: var(--color-muted); margin: 0 0 0.6rem; }
    .kb-prose table { border-collapse: collapse; width: 100%; margin: 0.25rem 0 0.7rem; font-size: 0.85em; }
    .kb-prose th, .kb-prose td { border: 1px solid var(--color-line); padding: 4px 8px; text-align: left; }
    .kb-prose th { background: var(--color-surface-subtle); font-weight: 600; }

    /* Цитати [N] → badge с tooltip (заглавието на източника) */
    .kb-cite { display: inline-flex; align-items: center; justify-content: center; min-width: 15px; height: 15px; padding: 0 4px; margin: 0 1px; font-size: 10px; font-weight: 600; line-height: 1; vertical-align: super; background: var(--color-info-soft); color: var(--color-primary-hover); border: 1px solid color-mix(in srgb, var(--color-primary) 30%, white); border-radius: 4px; text-decoration: none; cursor: pointer; }
    .kb-cite:hover { background: var(--color-primary); color: #fff; }
    .kb-cite--fact { cursor: default; }
    /* Tooltip за цитат — fixed елемент извън scroll-кутията (не се реже от overflow) */
    .kb-tip { position: fixed; z-index: 60; transform: translate(-50%, -100%); max-width: 280px; white-space: normal; overflow-wrap: anywhere; background: var(--color-ink); color: #fff; font-size: 11px; font-weight: 400; line-height: 1.35; padding: 5px 9px; border-radius: 6px; pointer-events: none; box-shadow: var(--shadow-popover); }
    .kb-tip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 5px solid transparent; border-top-color: var(--color-ink); }

    /* Loading: три точки на вълна (azure) */
    .kb-dots { display: inline-flex; align-items: center; gap: 4px; }
    .kb-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; animation: kb-bounce 1.2s ease-in-out infinite; }
    .kb-dot--1 { background: var(--color-primary); animation-delay: 0s; }
    .kb-dot--2 { background: var(--color-accent); animation-delay: 0.18s; }
    .kb-dot--3 { background: color-mix(in srgb, var(--color-accent) 60%, white); animation-delay: 0.36s; }
    @keyframes kb-bounce { 0%, 80%, 100% { transform: translateY(0); opacity: 0.5; } 40% { transform: translateY(-5px); opacity: 1; } }
</style>
@endpush

@section('content')
<div x-data="knowledgePage(@js($config))" x-init="init()">

    {{-- Споделен tooltip за цитати (fixed → не се отрязва от scroll-кутията) --}}
    <div x-show="chat.tip.show" x-cloak class="kb-tip"
         :style="`left:${chat.tip.x}px; top:${chat.tip.y}px;`" x-text="chat.tip.text"></div>

    {{-- ─────────── Header ─────────── --}}
    <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
            <a :href="config.backUrl" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition"><x-icon name="arrow-left" size="4" /> {{ $company->name }}</a>
            <h1 class="text-2xl font-display font-bold text-ink mt-2 flex items-center gap-3 flex-wrap">
                База знания
                <span class="text-sm font-normal text-subtle tabular-nums" x-show="!loading"
                      x-text="stats.resources + ' ресурса · ' + stats.pages + ' страници · ' + stats.facts + ' факта'"></span>
            </h1>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button @click="toggleEnabled()"
                    class="inline-flex items-center gap-2 px-3 py-2 rounded-md text-sm font-medium border transition"
                    :class="enabled ? 'bg-success-soft border-success/30 text-success-strong' : 'bg-surface-subtle border-line text-subtle'">
                <span class="w-2 h-2 rounded-full" :class="enabled ? 'bg-success' : 'bg-line-strong'"></span>
                <span x-text="enabled ? 'Включена' : 'Изключена'"></span>
            </button>
            <button @click="openAddModal()"
                    class="inline-flex items-center gap-2 bg-primary hover:bg-primary-hover text-primary-fg px-4 py-2 rounded-md text-sm font-medium transition">
                <x-icon name="plus" size="4" /> Добави ресурс
            </button>
        </div>
    </div>

    {{-- Provider mismatch banner --}}
    <div x-show="stats.foreign_provider_chunks > 0" x-cloak
         class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl text-sm">
        ⚠ <span x-text="stats.foreign_provider_chunks"></span> откъса са индексирани с друг embedding провайдър
        (текущ: <span class="font-mono" x-text="stats.provider_tag"></span>) и не участват в търсенето —
        преиндексирай засегнатите ресурси с ↻.
    </div>

    <div x-show="error" x-cloak class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
        <span x-text="error"></span>
        <button class="float-right text-red-400 hover:text-red-600" @click="error = ''">✕</button>
    </div>

    {{-- ─────────── Folder sub-header ─────────── --}}
    <div class="flex items-center gap-1.5 mb-4 bg-surface border border-line rounded-xl px-3 py-2 flex-wrap">
        <button @click="selectedFolder = null; rTab.page = 1; if (tab === 'resources') loadResources()"
                class="px-3 py-1.5 rounded-lg text-sm font-medium transition"
                :class="selectedFolder === null ? 'bg-info-soft text-primary' : 'text-muted hover:bg-neutral-soft'">
            Всички
            <span class="text-xs text-subtle ml-0.5" x-text="'(' + (stats.resources || 0) + ')'"></span>
        </button>

        <template x-for="folder in folderTree()" :key="folder.id">
            <div class="group relative inline-flex items-center">
                <button x-show="renaming !== folder.id"
                        @click="selectedFolder = folder.id; rTab.page = 1; if (tab === 'resources') loadResources()"
                        class="px-3 py-1.5 rounded-lg text-sm font-medium transition"
                        :class="selectedFolder === folder.id ? 'bg-info-soft text-primary' : 'text-muted hover:bg-neutral-soft'">
                    📁 <span x-text="folder.name"></span>
                    <span class="text-xs text-subtle ml-0.5" x-text="'(' + folder.doc_count + ')'"></span>
                </button>
                <input x-show="renaming === folder.id" x-cloak type="text"
                       class="border border-primary rounded-lg px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary/40 w-36"
                       :id="'rename-' + folder.id" :value="folder.name"
                       @keydown.enter="renameFolder(folder.id, $event.target.value)"
                       @keydown.escape="renaming = null">
                <div x-show="renaming !== folder.id"
                     class="opacity-0 group-hover:opacity-100 flex shrink-0 ml-0.5 transition-opacity">
                    <button @click.stop="startRename(folder.id)" title="Преименувай"
                            class="p-1 text-subtle hover:text-primary text-xs leading-none">✏</button>
                    <button @click.stop="deleteFolder(folder)" title="Изтрий"
                            class="p-1 text-subtle hover:text-red-600 text-xs leading-none">🗑</button>
                </div>
            </div>
        </template>

        <div class="flex items-center gap-1 ml-auto">
            <button x-show="!addingFolder" @click="addingFolder = true; $nextTick(() => $refs.newFolderInput?.focus())"
                    class="px-2 py-1 text-xs text-subtle hover:text-primary hover:bg-neutral-soft rounded-lg transition">
                ＋ Нова папка
            </button>
            <div x-show="addingFolder" x-cloak class="flex gap-1 items-center">
                <input x-ref="newFolderInput" type="text" x-model="newFolderName" placeholder="Нова папка…"
                       @keydown.enter="createFolder(); addingFolder = false"
                       @keydown.escape="addingFolder = false; newFolderName = ''"
                       class="border border-line rounded-lg px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary/40 w-36">
                <button @click="createFolder(); addingFolder = false"
                        class="px-2.5 py-1 bg-primary hover:bg-primary-hover text-white rounded-lg text-sm transition">＋</button>
                <button @click="addingFolder = false; newFolderName = ''"
                        class="px-2 py-1 text-subtle hover:text-muted text-sm">✕</button>
            </div>
        </div>
    </div>

    <div class="flex gap-6 items-start">

        {{-- ─────────── Main: tabs ─────────── --}}
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1 mb-4">
                <template x-for="t in tabs" :key="t.key">
                    <button @click="tab = t.key"
                            class="px-3.5 py-2 rounded-lg text-sm font-medium transition"
                            :class="tab === t.key ? 'bg-ink text-white' : 'text-muted hover:bg-neutral-soft'">
                        <span x-text="t.label"></span>
                        <span class="text-xs opacity-60" x-text="'(' + tabCount(t.key) + ')'"></span>
                    </button>
                </template>
                <div class="flex-1"></div>
                <span class="text-xs text-subtle" x-show="busy">
                    <span class="inline-block animate-spin mr-1">◌</span> обработка…
                </span>
                <span class="text-xs text-subtle" x-text="'общо $' + (stats.cost_usd || 0).toFixed(4)"></span>
            </div>

            {{-- ── TAB: Ресурси ── --}}
            <div x-show="tab === 'resources'" class="bg-surface rounded-xl border border-line overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-line">
                    <input type="text" x-model="rTab.search" placeholder="Търси по заглавие или URL…"
                           class="flex-1 border border-line rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-primary/40">
                </div>

                <div x-show="loading || rTab.loading" class="px-4 py-10 text-center text-subtle text-sm">Зареждане…</div>

                <div x-show="!loading && !rTab.loading && rTab.items.length === 0" x-cloak class="px-4 py-12 text-center">
                    <div class="flex justify-center mb-3"><span class="flex items-center justify-center w-12 h-12 rounded-xl bg-surface-subtle border border-line text-subtle"><x-icon name="book-open" size="6" /></span></div>
                    <p class="text-ink font-medium mb-1">Няма ресурси</p>
                    <p class="text-muted text-sm max-w-md mx-auto">Добави URL на сайта, ценоразписи, каталози или бележки — агентите ще ги ползват като достоверен източник.</p>
                </div>

                <table x-show="!loading && !rTab.loading && rTab.items.length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-subtle uppercase border-b border-line">
                            <th class="px-4 py-2 cursor-pointer hover:text-muted select-none" @click="sortBy('title')">
                                Ресурс <span class="opacity-50" x-text="rTab.sort === 'title' ? (rTab.dir === 'asc' ? '↑' : '↓') : '↕'"></span>
                            </th>
                            <th class="px-2 py-2">Папка</th>
                            <th class="px-2 py-2 cursor-pointer hover:text-muted select-none" @click="sortBy('status')">
                                Статус <span class="opacity-50" x-text="rTab.sort === 'status' ? (rTab.dir === 'asc' ? '↑' : '↓') : '↕'"></span>
                            </th>
                            <th class="px-2 py-2 text-right cursor-pointer hover:text-muted select-none" @click="sortBy('chunk_count')">
                                Чанкове <span class="opacity-50" x-text="rTab.sort === 'chunk_count' ? (rTab.dir === 'asc' ? '↑' : '↓') : '↕'"></span>
                            </th>
                            <th class="px-2 py-2 cursor-pointer hover:text-muted select-none" @click="sortBy('created_at')">
                                Дата <span class="opacity-50" x-text="rTab.sort === 'created_at' ? (rTab.dir === 'asc' ? '↑' : '↓') : '↕'"></span>
                            </th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in rTab.items" :key="r.id">
                            <tr class="border-b border-line hover:bg-surface-subtle group">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span x-text="typeIcon(r.type)" :title="typeLabel(r.type)"></span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-ink truncate max-w-sm" x-text="r.title"
                                                 :title="r.original_name || r.url || r.title"></div>
                                            <div class="text-xs text-subtle truncate max-w-sm">
                                                <a x-show="r.url" :href="r.url" target="_blank" class="hover:text-primary hover:underline" x-text="r.url"></a>
                                                <span x-show="r.type === 'upload' || r.type === 'image'" x-text="formatSize(r.size_bytes)"></span>
                                                <button x-show="r.type === 'url' && r.pages_count" @click="openPages(r)"
                                                        class="text-primary hover:underline"
                                                        x-text="r.pages_count + ' страници' + (r.partial ? ' (частично)' : '')"></button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-2 py-2.5 text-muted text-xs" x-text="folderName(r.folder_id)"></td>
                                <td class="px-2 py-2.5 whitespace-nowrap">
                                    <span x-show="r.status === 'pending'" class="text-subtle" title="Чака обработка"><x-icon name="clock" size="4" /></span>
                                    <span x-show="r.status === 'processing'" class="inline-flex items-center gap-1 text-primary text-xs">
                                        <x-icon name="arrow-path" size="4" class="animate-spin" />
                                        <span x-show="r.progress" class="tabular-nums" x-text="(r.progress?.parsed || 0) + '/' + (r.progress?.discovered || '?')"></span>
                                    </span>
                                    <span x-show="r.status === 'ready'" class="text-success" title="Готов"><x-icon name="check-circle" size="4" /></span>
                                    <span x-show="r.status === 'failed'" class="text-danger cursor-help" :title="r.error"><x-icon name="x-circle" size="4" /></span>
                                </td>
                                <td class="px-2 py-2.5 text-right text-muted" x-text="r.chunk_count"></td>
                                <td class="px-2 py-2.5 text-subtle text-xs" x-text="r.created_at"></td>
                                <td class="px-2 py-2.5 text-right whitespace-nowrap">
                                    <div class="opacity-0 group-hover:opacity-100 transition-opacity inline-flex gap-1">
                                        <button x-show="r.has_digest" @click="openDigest(r)" title="Преглед на извлечената информация" aria-label="Преглед"
                                                class="p-1 rounded text-subtle hover:text-primary hover:bg-info-soft"><x-icon name="eye" size="4" /></button>
                                        <button x-show="r.type === 'url'" @click="openPages(r)" title="Страници" aria-label="Страници"
                                                class="p-1 rounded text-subtle hover:text-primary hover:bg-info-soft"><x-icon name="document-duplicate" size="4" /></button>
                                        <button x-show="r.type === 'note'" @click="openNoteEdit(r)" title="Редактирай бележката" aria-label="Редактирай"
                                                class="p-1 rounded text-subtle hover:text-primary hover:bg-info-soft"><x-icon name="pencil-square" size="4" /></button>
                                        <a x-show="r.type === 'upload' || r.type === 'image'"
                                           :href="config.base + '/resources/' + r.id + '/download'" title="Свали оригинала" aria-label="Свали"
                                           class="p-1 rounded text-subtle hover:text-primary hover:bg-info-soft"><x-icon name="arrow-down-tray" size="4" /></a>
                                        <button @click="reingest(r)" title="Преиндексирай" aria-label="Преиндексирай"
                                                class="p-1 rounded text-subtle hover:text-primary hover:bg-info-soft"><x-icon name="arrow-path" size="4" /></button>
                                        <button @click="deleteResource(r)" title="Изтрий (забравя знанието)" aria-label="Изтрий"
                                                class="p-1 rounded text-subtle hover:text-danger hover:bg-danger-soft"><x-icon name="trash" size="4" /></button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div x-show="!loading && rTab.pages > 1" x-cloak
                     class="flex items-center justify-between px-4 py-2.5 border-t border-line text-sm text-muted">
                    <span x-text="'Стр. ' + rTab.page + ' от ' + rTab.pages + ' (' + rTab.total + ' общо)'"></span>
                    <div class="flex gap-1">
                        <button @click="rTab.page = Math.max(1, rTab.page - 1); loadResources()" :disabled="rTab.page === 1"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">←</button>
                        <button @click="rTab.page = Math.min(rTab.pages, rTab.page + 1); loadResources()" :disabled="rTab.page === rTab.pages"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">→</button>
                    </div>
                </div>
            </div>

            {{-- ── TAB: Факти ── --}}
            <div x-show="tab === 'facts'" x-cloak class="bg-surface rounded-xl border border-line overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-line">
                    <input type="text" x-model="fTab.search" placeholder="Търси по факт или стойност…"
                           class="flex-1 border border-line rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-primary/40">
                </div>
                <div class="flex items-center gap-2 px-4 py-2 border-b border-line flex-wrap">
                    <template x-for="c in factCategories" :key="c">
                        <button @click="fTab.category = c; fTab.page = 1; loadFacts()"
                                class="px-2 py-1 rounded-full text-xs font-medium transition"
                                :class="fTab.category === c ? 'bg-primary text-white' : 'bg-neutral-soft text-muted hover:bg-neutral-soft'"
                                x-text="categoryLabel(c)"></button>
                    </template>
                </div>
                <div x-show="fTab.loading" class="px-4 py-10 text-center text-subtle text-sm">Зареждане…</div>
                <div x-show="!fTab.loading && fTab.items.length === 0" class="px-4 py-12 text-center">
                    <p class="text-2xl mb-2">💡</p>
                    <p class="text-muted font-medium mb-1">Още няма факти</p>
                    <p class="text-subtle text-sm">Фактите се извличат автоматично при добавяне на ресурси и след всеки успешен run на flow.</p>
                </div>
                <table x-show="!fTab.loading && fTab.items.length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-subtle uppercase border-b border-line">
                            <th class="px-4 py-2">Факт</th>
                            <th class="px-2 py-2">Категория</th>
                            <th class="px-2 py-2">Локация</th>
                            <th class="px-2 py-2">Източник</th>
                            <th class="px-2 py-2">Обновен</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="f in fTab.items" :key="f.id">
                            <tr class="border-b border-line hover:bg-surface-subtle group align-top">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-ink" x-text="f.name"></div>
                                    <div class="text-xs text-muted whitespace-pre-line" x-text="f.value"></div>
                                </td>
                                <td class="px-2 py-2.5">
                                    <span class="px-1.5 py-0.5 rounded bg-neutral-soft text-muted text-xs" x-text="categoryLabel(f.category)"></span>
                                </td>
                                <td class="px-2 py-2.5 text-xs text-muted" x-text="f.location || '—'"></td>
                                <td class="px-2 py-2.5 text-xs">
                                    <a x-show="f.flow_run_id" :href="'/runs/' + f.flow_run_id"
                                       class="text-primary hover:underline" x-text="'run #' + f.flow_run_id"></a>
                                    <span x-show="!f.flow_run_id" class="text-subtle" x-text="factSourceLabel(f.source_type)"></span>
                                </td>
                                <td class="px-2 py-2.5 text-xs text-subtle" x-text="f.updated_at"></td>
                                <td class="px-2 py-2.5 text-right">
                                    <button @click="deleteFact(f)" title="Изтрий факта"
                                            class="opacity-0 group-hover:opacity-100 p-1 rounded text-subtle hover:text-red-600 hover:bg-red-50">🗑</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="!fTab.loading && fTab.pages > 1" x-cloak
                     class="flex items-center justify-between px-4 py-2.5 border-t border-line text-sm text-muted">
                    <span x-text="'Стр. ' + fTab.page + ' от ' + fTab.pages + ' (' + fTab.total + ' общо)'"></span>
                    <div class="flex gap-1">
                        <button @click="fTab.page = Math.max(1, fTab.page - 1); loadFacts()" :disabled="fTab.page === 1"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">←</button>
                        <button @click="fTab.page = Math.min(fTab.pages, fTab.page + 1); loadFacts()" :disabled="fTab.page === fTab.pages"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">→</button>
                    </div>
                </div>
            </div>

            {{-- ── TAB: История ── --}}
            <div x-show="tab === 'history'" x-cloak class="bg-surface rounded-xl border border-line overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-line">
                    <input type="text" x-model="hTab.search" placeholder="Търси по заглавие…"
                           class="flex-1 border border-line rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-primary/40">
                </div>
                <div x-show="hTab.loading" class="px-4 py-10 text-center text-subtle text-sm">Зареждане…</div>
                <div x-show="!hTab.loading && hTab.items.length === 0" class="px-4 py-12 text-center text-subtle text-sm">
                    Още няма събития — историята записва всяко добавено/обновено/изтрито знание.
                </div>
                <table x-show="!hTab.loading && hTab.items.length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-subtle uppercase border-b border-line">
                            <th class="px-4 py-2">Знание</th>
                            <th class="px-2 py-2">Действие</th>
                            <th class="px-2 py-2">От къде е дошло</th>
                            <th class="px-2 py-2">Кога</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="e in hTab.items" :key="e.id">
                            <tr class="border-b border-line hover:bg-surface-subtle group">
                                <td class="px-4 py-2.5">
                                    <span x-text="subjectIcon(e.subject_type)"></span>
                                    <span class="font-medium text-ink" x-text="e.title"></span>
                                </td>
                                <td class="px-2 py-2.5">
                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium"
                                          :class="{
                                              'bg-green-50 text-green-600': e.action === 'added',
                                              'bg-blue-50 text-blue-600': e.action === 'updated',
                                              'bg-red-50 text-red-500': e.action === 'deleted',
                                          }"
                                          x-text="{added: 'добавено', updated: 'обновено', deleted: 'изтрито'}[e.action] || e.action"></span>
                                </td>
                                <td class="px-2 py-2.5 text-xs text-muted" x-text="e.source || '—'"></td>
                                <td class="px-2 py-2.5 text-xs text-subtle" x-text="e.created_at"></td>
                                <td class="px-2 py-2.5 text-right">
                                    <button x-show="e.snippet" @click="openSnippet(e)" title="Преглед на съдържанието"
                                            class="opacity-0 group-hover:opacity-100 p-1 rounded text-subtle hover:text-primary hover:bg-info-soft">👁</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="!hTab.loading && hTab.pages > 1" x-cloak
                     class="flex items-center justify-between px-4 py-2.5 border-t border-line text-sm text-muted">
                    <span x-text="'Стр. ' + hTab.page + ' от ' + hTab.pages + ' (' + hTab.total + ' общо)'"></span>
                    <div class="flex gap-1">
                        <button @click="hTab.page = Math.max(1, hTab.page - 1); loadEvents()" :disabled="hTab.page === 1"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">←</button>
                        <button @click="hTab.page = Math.min(hTab.pages, hTab.page + 1); loadEvents()" :disabled="hTab.page === hTab.pages"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">→</button>
                    </div>
                </div>
            </div>

            {{-- ── TAB: Пропуски ── --}}
            <div x-show="tab === 'gaps'" x-cloak class="bg-surface rounded-xl border border-line overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-line">
                    <input type="text" x-model="gTab.search" placeholder="Търси по заявка…"
                           class="flex-1 border border-line rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-primary/40">
                </div>
                <div class="p-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs text-subtle">
                        Агентите (или чатът) са търсили това, но не са намерили добро покритие — качи ресурс по темата.
                        Когато ново знание покрие пропуска, той става <span class="text-green-600 font-medium">готов</span> автоматично.
                    </p>
                    <button x-show="stats.gaps > 0" @click="clearGaps()"
                            class="text-xs text-subtle hover:text-red-600 transition shrink-0 ml-3">Изчисти всички</button>
                </div>
                <div x-show="gTab.loading" class="py-8 text-center text-subtle text-sm">Зареждане…</div>
                <div x-show="!gTab.loading && gTab.items.length === 0" class="text-sm text-subtle py-2">
                    Няма пропуски — търсенията намират покритие.
                </div>
                <table x-show="!gTab.loading && gTab.items.length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-subtle uppercase border-b border-line">
                            <th class="py-2 pr-2">Заявка</th>
                            <th class="py-2 pr-2">Статус</th>
                            <th class="py-2 pr-2 text-right">Score</th>
                            <th class="py-2 pr-2">Run</th>
                            <th class="py-2">Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="gap in gTab.items" :key="gap.id">
                            <tr class="border-b border-line">
                                <td class="py-2 pr-2 text-ink" x-text="gap.query"></td>
                                <td class="py-2 pr-2">
                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium"
                                          :class="gap.status === 'resolved' ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600'"
                                          :title="gap.resolved_by ? 'Запълнен от: ' + gap.resolved_by : ''"
                                          x-text="gap.status === 'resolved' ? 'готов' : 'отворен'"></span>
                                </td>
                                <td class="py-2 pr-2 text-right font-mono text-xs text-subtle"
                                    x-text="gap.best_score !== null ? gap.best_score.toFixed(2) : '—'"></td>
                                <td class="py-2 pr-2 text-xs">
                                    <a x-show="gap.flow_run_id" :href="'/runs/' + gap.flow_run_id"
                                       class="text-primary hover:underline" x-text="'#' + gap.flow_run_id"></a>
                                    <span x-show="!gap.flow_run_id" class="text-subtle">—</span>
                                </td>
                                <td class="py-2 text-xs text-subtle" x-text="gap.created_at"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="!gTab.loading && gTab.pages > 1" x-cloak
                     class="flex items-center justify-between pt-3 mt-3 border-t border-line text-sm text-muted">
                    <span x-text="'Стр. ' + gTab.page + ' от ' + gTab.pages + ' (' + gTab.total + ' общо)'"></span>
                    <div class="flex gap-1">
                        <button @click="gTab.page = Math.max(1, gTab.page - 1); loadGaps()" :disabled="gTab.page === 1"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">←</button>
                        <button @click="gTab.page = Math.min(gTab.pages, gTab.page + 1); loadGaps()" :disabled="gTab.page === gTab.pages"
                                class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">→</button>
                    </div>
                </div>
                </div>{{-- /p-4 --}}
            </div>

            {{-- ─────────── Tab: Конфликти ─────────── --}}
            <div x-show="tab === 'conflicts'" x-cloak class="bg-surface rounded-xl border border-line overflow-hidden">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs text-subtle">
                            Факти за едно и също нещо от различни източници, но с различни стойности. Избери вярната — другите се архивират.
                        </p>
                        <button @click="scanConflicts()" :disabled="cTab.scanning"
                                class="text-xs text-primary hover:text-primary-hover transition shrink-0 ml-3 disabled:opacity-50">
                            <span x-show="!cTab.scanning">↻ Сканирай за конфликти</span>
                            <span x-show="cTab.scanning" x-cloak>Сканиране…</span>
                        </button>
                    </div>

                    <div x-show="cTab.loading" class="py-8 text-center text-subtle text-sm">Зареждане…</div>
                    <div x-show="!cTab.loading && cTab.items.length === 0" class="text-sm text-subtle py-2">
                        Няма открити конфликти. Натисни „Сканирай за конфликти", ако току-що си добавил ресурси.
                    </div>

                    <div class="space-y-3" x-show="!cTab.loading && cTab.items.length > 0" x-cloak>
                        <template x-for="c in cTab.items" :key="c.id">
                            <div class="border border-amber-200 rounded-xl overflow-hidden">
                                <div class="flex items-center justify-between gap-3 px-4 py-2.5 bg-amber-50 border-b border-amber-100">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-ink truncate" x-text="c.subject"></div>
                                        <div class="text-[11px] text-amber-700">
                                            <span x-text="c.category"></span><span x-show="c.location" x-text="' · ' + c.location"></span>
                                        </div>
                                    </div>
                                    <button @click="ignoreConflict(c.id)"
                                            class="text-xs text-subtle hover:text-ink transition shrink-0">Не е конфликт</button>
                                </div>
                                <div class="divide-y divide-line">
                                    <template x-for="cand in c.candidates" :key="cand.fact_id">
                                        <div class="flex items-start gap-3 px-4 py-3">
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm text-ink" x-text="cand.value"></div>
                                                <div class="text-[11px] text-subtle mt-0.5">
                                                    <span x-text="cand.source"></span> · <span x-text="cand.created_at"></span>
                                                    <span x-show="cand.confidence" x-text="' · увереност ' + Math.round(cand.confidence * 100) + '%'"></span>
                                                </div>
                                            </div>
                                            <button @click="resolveConflict(c.id, cand.fact_id)"
                                                    class="shrink-0 bg-primary hover:bg-primary-hover text-white text-xs font-medium px-3 py-1.5 rounded-lg transition">
                                                Избери тази
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div x-show="!cTab.loading && cTab.pages > 1" x-cloak
                         class="flex items-center justify-between pt-3 mt-3 border-t border-line text-sm text-muted">
                        <span x-text="'Стр. ' + cTab.page + ' от ' + cTab.pages + ' (' + cTab.total + ' общо)'"></span>
                        <div class="flex gap-1">
                            <button @click="cTab.page = Math.max(1, cTab.page - 1); loadConflicts()" :disabled="cTab.page === 1"
                                    class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">←</button>
                            <button @click="cTab.page = Math.min(cTab.pages, cTab.page + 1); loadConflicts()" :disabled="cTab.page === cTab.pages"
                                    class="px-2 py-1 rounded hover:bg-neutral-soft disabled:opacity-40">→</button>
                        </div>
                    </div>
                </div>{{-- /p-4 --}}
            </div>
        </div>

        {{-- ─────────── Chat: Тествай знанията ─────────── --}}
        <div :class="chat.fullscreen
                ? 'fixed inset-0 z-50 bg-surface flex'
                : 'w-96 shrink-0 bg-surface rounded-xl border border-line flex'"
             :style="chat.fullscreen ? '' : 'height: calc(100vh - 350px); min-height: 320px; position: sticky; top: 5rem;'"
             @keydown.escape.window="chat.fullscreen = false; chat.showSessions = false">

            {{-- Sessions sidebar (само на цял екран) --}}
            <div x-show="chat.fullscreen" x-cloak class="w-64 shrink-0 border-r border-line bg-surface-subtle flex flex-col">
                <div class="p-3 border-b border-line">
                    <button @click="newChat()" class="w-full flex items-center gap-2 bg-surface border border-line rounded-lg px-3 py-2 text-sm text-ink hover:border-primary transition">
                        <span class="text-base leading-none">＋</span> Нов разговор
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-2 space-y-1">
                    <template x-for="s in chat.sessions" :key="s.session">
                        <button @click="openSession(s.session)"
                                :class="s.session === chat.session ? 'bg-surface border-info' : 'border-transparent hover:bg-surface'"
                                class="w-full text-left px-2.5 py-2 rounded-lg border transition">
                            <div class="text-xs text-ink truncate" x-text="s.title"></div>
                            <div class="text-[10px] text-subtle mt-0.5" x-text="s.last_at"></div>
                        </button>
                    </template>
                    <p x-show="chat.sessions.length === 0" class="text-xs text-subtle text-center py-6">Няма минали разговори</p>
                </div>
            </div>

            {{-- Chat колона --}}
            <div class="flex-1 flex flex-col min-w-0">
                <div class="flex items-center justify-between px-4 py-3 border-b border-line relative">
                    <h2 class="text-sm font-semibold text-ink inline-flex items-center gap-1.5"><x-icon name="chat-bubble-left-right" size="4" class="text-primary" /> Тествай знанията</h2>
                    <div class="flex items-center gap-0.5">
                        {{-- История (dropdown — компактен изглед) --}}
                        <div x-show="!chat.fullscreen" class="relative">
                            <button @click="toggleSessions()" class="w-7 h-7 flex items-center justify-center rounded-lg text-subtle hover:text-primary hover:bg-surface-subtle transition" title="История">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>
                            </button>
                            <div x-show="chat.showSessions" x-cloak @click.outside="chat.showSessions = false"
                                 class="absolute right-0 top-9 w-72 max-h-80 overflow-y-auto bg-surface border border-line rounded-xl shadow-lg z-30 p-1">
                                <p class="text-[10px] uppercase tracking-wide text-subtle px-2.5 py-1.5">Минали разговори</p>
                                <template x-for="s in chat.sessions" :key="s.session">
                                    <button @click="openSession(s.session); chat.showSessions = false"
                                            class="w-full text-left px-2.5 py-2 rounded-lg hover:bg-surface-subtle transition">
                                        <div class="text-xs text-ink truncate" x-text="s.title"></div>
                                        <div class="text-[10px] text-subtle mt-0.5" x-text="s.last_at"></div>
                                    </button>
                                </template>
                                <p x-show="chat.sessions.length === 0" class="text-xs text-subtle text-center py-4">Няма минали разговори</p>
                            </div>
                        </div>
                        {{-- Нов разговор --}}
                        <button @click="newChat()" class="w-7 h-7 flex items-center justify-center rounded-lg text-subtle hover:text-primary hover:bg-surface-subtle transition" title="Нов разговор">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.5l4 4L7 21l-4 1 1-4L16.5 3.5z"/></svg>
                        </button>
                        {{-- Цял екран / намали --}}
                        <button @click="toggleFullscreen()" class="w-7 h-7 flex items-center justify-center rounded-lg text-subtle hover:text-primary hover:bg-surface-subtle transition" :title="chat.fullscreen ? 'Намали' : 'Цял екран'">
                            <svg x-show="!chat.fullscreen" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
                            <svg x-show="chat.fullscreen" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto overflow-x-hidden p-3 space-y-3" x-ref="chatScroll"
                     @mouseover="citeHover($event)" @mouseout="citeOut($event)" @scroll="chat.tip.show = false">
                    <div :class="chat.fullscreen ? 'max-w-3xl mx-auto w-full space-y-3' : 'space-y-3'">
                        <div x-show="chat.messages.length === 0 && !chat.pending" class="py-6 px-3">
                            <div class="flex flex-col items-center mb-4">
                                <div class="w-10 h-10 rounded-lg bg-info-soft text-info-strong flex items-center justify-center mb-2"><x-icon name="chat-bubble-left-right" size="5" /></div>
                                <p class="text-sm font-medium text-ink">Питай на човешки език</p>
                                <p class="text-xs text-subtle mt-0.5">Тествай какво знае базата знания</p>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <template x-for="q in [
                                    'Каква е цената на лазерна епилация на подмишници?',
                                    'Какви процедури предлагате?',
                                    'Как да се запишем за час?',
                                    'Има ли промоции в момента?',
                                    'Колко трае процедурата?',
                                ]" :key="q">
                                    <button @click="chat.input = q; sendChat()"
                                            class="w-full text-left text-xs text-muted bg-surface-subtle hover:bg-info-soft hover:text-primary border border-line hover:border-info rounded-lg px-3 py-2 transition-colors leading-snug"
                                            x-text="'↗ ' + q"></button>
                                </template>
                            </div>
                        </div>

                        <template x-for="m in chat.messages" :key="m.id">
                            <div :class="m.role === 'user' ? 'flex justify-end' : ''">
                                <div class="rounded-xl px-3 py-2 text-sm max-w-[92%]"
                                     :class="m.role === 'user' ? 'bg-primary text-white' : (m.failed ? 'bg-red-50 text-red-700 border border-red-100' : 'bg-surface-subtle text-ink border border-line')">
                                    {{-- Badge: интернет отговор --}}
                                    <div x-show="m.role === 'assistant' && m.source_type === 'web'" class="mb-1.5">
                                        <span class="inline-flex items-center gap-1 text-[10px] font-medium text-teal-700 bg-teal-50 border border-teal-100 rounded-full px-2 py-0.5"><x-icon name="globe-alt" size="3" /> От интернет</span>
                                    </div>
                                    {{-- Съдържание: потребител = чист текст; асистент = markdown --}}
                                    <div x-show="m.role === 'user'" class="whitespace-pre-wrap" x-text="m.content"></div>
                                    <div x-show="m.role === 'assistant'" class="kb-prose" x-html="renderAssistant(m)"></div>
                                    {{-- Източници (chip-ове) --}}
                                    <div x-show="m.sources && m.sources.length" class="mt-2 pt-2 border-t border-line flex flex-wrap gap-1">
                                        <template x-for="s in (m.sources || [])" :key="s.n">
                                            <span>
                                                <a x-show="s.url" :href="s.url" target="_blank"
                                                   class="inline-flex items-center gap-1 text-[11px] text-muted bg-surface border border-line rounded-full px-2 py-0.5 hover:border-primary hover:text-primary transition w-[280px]">
                                                    <span class="font-mono text-subtle" x-text="'['+s.n+']'"></span>
                                                    <span class="truncate" x-text="s.title"></span>
                                                </a>
                                                <span x-show="!s.url" class="inline-flex items-center gap-1 text-[11px] text-muted bg-surface border border-line rounded-full px-2 py-0.5 w-[280px]">
                                                    <span class="font-mono text-subtle" x-text="'['+s.n+']'"></span>
                                                    <span class="truncate" x-text="s.title + (s.kind === 'fact' ? ' (факт)' : '')"></span>
                                                </span>
                                            </span>
                                        </template>
                                    </div>
                                    {{-- Footer: 👍/👎 (само web) + „Детайли" (винаги) --}}
                                    <div x-show="m.role === 'assistant' && !m.failed" class="mt-2 pt-2 border-t border-line flex items-center gap-2 flex-wrap">
                                        <template x-if="m.source_type === 'web'">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span x-show="!m.feedback" class="text-[11px] text-subtle">Полезно?</span>
                                                {{-- Емоджитата пренебрегват CSS color, затова „избраното" се показва с фон+ring, а не с цвят на текста. --}}
                                                <button @click="sendFeedback(m, 'up')" :disabled="!!m.feedback"
                                                        :class="m.feedback === 'up' ? 'bg-success-soft text-success-strong ring-1 ring-success/30' : (m.feedback ? 'opacity-30' : 'text-subtle hover:text-success hover:bg-success-soft')"
                                                        class="w-6 h-6 flex items-center justify-center rounded-md transition disabled:cursor-default" title="Запиши в знанието" aria-label="Полезно"><x-icon name="hand-thumb-up" size="4" /></button>
                                                <button @click="sendFeedback(m, 'down')" :disabled="!!m.feedback"
                                                        :class="m.feedback === 'down' ? 'bg-danger-soft text-danger-strong ring-1 ring-danger/30' : (m.feedback ? 'opacity-30' : 'text-subtle hover:text-danger hover:bg-danger-soft')"
                                                        class="w-6 h-6 flex items-center justify-center rounded-md transition disabled:cursor-default" title="Не помогна" aria-label="Не помогна"><x-icon name="hand-thumb-down" size="4" /></button>
                                                <span x-show="m.feedback === 'up'" x-cloak class="text-[11px] text-green-600">✓ Записано в знанието</span>
                                                <span x-show="m.feedback === 'down'" x-cloak class="text-[11px] text-subtle">✓ Благодаря за обратната връзка</span>
                                            </span>
                                        </template>
                                        <button @click="openDetail(m)"
                                                class="text-[11px] text-subtle hover:text-primary transition ml-auto inline-flex items-center gap-1"
                                                title="Покажи изхода на модела">⋯ Детайли</button>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div x-show="chat.pending" x-cloak>
                            <div class="rounded-xl px-3 py-2 text-sm bg-surface-subtle border border-line text-muted inline-flex items-center gap-2.5">
                                <span class="kb-dots"><span class="kb-dot kb-dot--1"></span><span class="kb-dot kb-dot--2"></span><span class="kb-dot kb-dot--3"></span></span>
                                <span x-text="chat.stage || 'Мисля…'"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-3 border-t border-line">
                    <div class="flex gap-2" :class="chat.fullscreen ? 'max-w-3xl mx-auto w-full' : ''">
                        <textarea x-model="chat.input" rows="2" placeholder="Въпрос към базата знания…"
                                  @keydown.enter.prevent="$event.shiftKey ? chat.input += '\n' : sendChat()"
                                  class="flex-1 border border-line rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
                        <button @click="sendChat()" :disabled="chat.pending || !chat.input.trim()" aria-label="Изпрати"
                                class="inline-flex items-center justify-center bg-primary hover:bg-primary-hover text-primary-fg px-3 rounded-lg text-sm font-medium transition disabled:opacity-40"><x-icon name="paper-airplane" size="4" /></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────── Modal: добави ресурс ─────────── --}}
    <div x-show="addModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="addModal.open = false">
        <div class="absolute inset-0 bg-ink/50" @click="addModal.open = false"></div>
        <div class="relative bg-surface rounded-xl shadow-popover w-full max-w-lg p-5">
            <h3 class="text-base font-medium text-ink mb-4 inline-flex items-center gap-2"><x-icon name="plus" size="5" class="text-primary" /> Добави ресурс</h3>

            <div class="flex gap-1 mb-4">
                <template x-for="k in [['url','URL адрес'],['files','Файлове / снимки'],['note','Бележка']]" :key="k[0]">
                    <button @click="addModal.kind = k[0]"
                            class="px-3 py-1.5 rounded-md text-sm font-medium transition"
                            :class="addModal.kind === k[0] ? 'bg-primary text-primary-fg' : 'bg-neutral-soft text-muted hover:text-ink'"
                            x-text="k[1]"></button>
                </template>
            </div>

            {{-- URL --}}
            <div x-show="addModal.kind === 'url'">
                <p class="text-xs text-subtle mb-2">Сайт или конкретна страница — обхождам всички вътрешни страници (вкл. пагинация), извличам съдържанието и го синтезирам.</p>
                <input type="url" x-model="addModal.url" placeholder="https://example.bg"
                       @keydown.enter="submitUrl()"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>

            {{-- Files --}}
            <div x-show="addModal.kind === 'files'" x-cloak>
                <p class="text-xs text-subtle mb-2">PDF, Word, Excel, CSV, текст или снимки (JPG/PNG → OCR). Ценоразписи, каталози, условия…</p>
                <input type="file" multiple x-ref="fileInput"
                       accept=".pdf,.txt,.md,.docx,.xlsx,.csv,.jpg,.jpeg,.png"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm">
            </div>

            {{-- Note --}}
            <div x-show="addModal.kind === 'note'" x-cloak class="space-y-2">
                <p class="text-xs text-subtle">Бележка с описание — фирмени факти, които ги няма другаде (условия, вътрешна информация, уточнения).</p>
                <input type="text" x-model="addModal.noteTitle" placeholder="Заглавие"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
                <textarea x-model="addModal.noteContent" rows="8" placeholder="Съдържание…"
                          class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
            </div>

            <div class="flex items-center justify-between mt-4">
                <span class="text-xs text-subtle" x-show="selectedFolder !== null" x-text="'Папка: ' + folderName(selectedFolder)"></span>
                <span x-show="selectedFolder === null"></span>
                <div class="flex gap-2">
                    <button @click="addModal.open = false"
                            class="px-4 py-2 rounded-lg text-sm text-muted hover:bg-neutral-soft transition">Отказ</button>
                    <button @click="submitAdd()" :disabled="addModal.busy"
                            class="bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50">
                        <span x-show="!addModal.busy">Добави</span>
                        <span x-show="addModal.busy">Добавяне…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────── Modal: редакция на бележка ─────────── --}}
    <div x-show="noteEdit.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="noteEdit.open = false">
        <div class="absolute inset-0 bg-ink/50" @click="noteEdit.open = false"></div>
        <div class="relative bg-surface rounded-2xl shadow-xl w-full max-w-lg p-5">
            <h3 class="text-lg font-semibold text-ink mb-4">Редакция на бележка</h3>
            <div class="space-y-2">
                <input type="text" x-model="noteEdit.title"
                       class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
                <textarea x-model="noteEdit.content" rows="10"
                          class="w-full border border-line rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <button @click="noteEdit.open = false"
                        class="px-4 py-2 rounded-lg text-sm text-muted hover:bg-neutral-soft transition">Отказ</button>
                <button @click="saveNote()" :disabled="noteEdit.busy"
                        class="bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50">
                    Запази (преиндексира)
                </button>
            </div>
        </div>
    </div>

    {{-- ─────────── Modal: digest / snippet преглед (z-60 — над pagesModal) ─────────── --}}
    <div x-show="preview.open" x-cloak class="fixed inset-0 z-60 flex items-center justify-center p-4"
         @keydown.escape.window="preview.open = false">
        <div class="absolute inset-0 bg-ink/50" @click="preview.open = false"></div>
        <div class="relative bg-surface rounded-2xl shadow-xl w-full max-w-2xl p-5 max-h-[85vh] flex flex-col">
            <div class="flex items-start justify-between mb-3">
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-ink truncate" x-text="preview.title"></h3>
                    <a x-show="preview.url" :href="preview.url" target="_blank"
                       class="text-xs text-primary hover:underline truncate block" x-text="preview.url"></a>
                    <p x-show="preview.subtitle" class="text-xs text-subtle mt-0.5" x-text="preview.subtitle"></p>
                </div>
                <button @click="preview.open = false" class="text-subtle hover:text-muted ml-3">✕</button>
            </div>
            <div class="overflow-y-auto text-sm text-ink whitespace-pre-wrap border border-line rounded-lg bg-surface-subtle p-4"
                 x-text="preview.loading ? 'Зареждане…' : (preview.content || 'Няма извлечена информация.')"></div>
        </div>
    </div>

    {{-- ─────────── Modal: страници на url ресурс ─────────── --}}
    <div x-show="pagesModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="pagesModal.open = false">
        <div class="absolute inset-0 bg-ink/50" @click="pagesModal.open = false"></div>
        <div class="relative bg-surface rounded-2xl shadow-xl w-full max-w-4xl p-5 max-h-[85vh] flex flex-col">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-lg font-semibold text-ink">Обходени страници</h3>
                    <p class="text-xs text-subtle" x-text="pagesModal.resource?.url"></p>
                </div>
                <button @click="pagesModal.open = false" class="text-subtle hover:text-muted">✕</button>
            </div>
            <div class="overflow-y-auto">
                <div x-show="pagesModal.loading" class="py-8 text-center text-subtle text-sm">Зареждане…</div>
                <table x-show="!pagesModal.loading" class="w-full text-sm">
                    <thead class="sticky top-0 bg-surface">
                        <tr class="text-left text-xs text-subtle uppercase border-b border-line">
                            <th class="py-2 pr-2">Title / URL</th>
                            <th class="py-2 pr-2">Meta описание</th>
                            <th class="py-2 pr-2">Парсната</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="p in pagesModal.pages" :key="p.id">
                            <tr class="border-b border-line hover:bg-surface-subtle group align-top">
                                <td class="py-2 pr-2 max-w-sm">
                                    <div class="font-medium text-ink truncate" x-text="p.title || '(без заглавие)'"></div>
                                    <a :href="p.url" target="_blank"
                                       class="text-xs text-subtle hover:text-primary hover:underline truncate block" x-text="p.url"></a>
                                </td>
                                <td class="py-2 pr-2 text-xs text-muted max-w-xs">
                                    <div class="line-clamp-2" x-text="p.meta_description || '—'"></div>
                                </td>
                                <td class="py-2 pr-2 text-xs text-subtle whitespace-nowrap" x-text="p.parsed_at || '—'"></td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <div class="opacity-0 group-hover:opacity-100 inline-flex gap-1">
                                        <a :href="p.url" target="_blank" title="Отвори страницата"
                                           class="p-1 rounded text-subtle hover:text-primary hover:bg-info-soft">↗</a>
                                        <button @click="openPageDigest(p)" title="Извлечената информация"
                                                class="p-1 rounded text-subtle hover:text-primary hover:bg-info-soft">👁</button>
                                        <button @click="deletePage(p)" title="Изтрий страницата"
                                                class="p-1 rounded text-subtle hover:text-red-600 hover:bg-red-50">🗑</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ─────────── Modal: Детайли на чат-отговор (изход на модела) ─────────── --}}
    <div x-show="detail.open" x-cloak class="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto"
         @keydown.escape.window="detail.open = false">
        <div class="absolute inset-0 bg-ink/50" @click="detail.open = false"></div>
        <div class="relative bg-surface rounded-2xl shadow-xl w-full max-w-3xl my-8">
            <div class="flex items-center justify-between px-5 py-3 border-b border-line">
                <h3 class="font-semibold text-ink">Детайли на отговора</h3>
                <button @click="detail.open = false" class="text-subtle hover:text-ink text-xl leading-none">&times;</button>
            </div>
            <div class="p-5">
                <div x-show="detail.loading" class="py-8 text-center text-subtle text-sm">Зареждане…</div>

                <div x-show="!detail.loading && detail.data && !detail.data.available" x-cloak class="py-6 text-center text-subtle text-sm">
                    Няма запазени детайли за това съобщение.
                </div>

                <template x-if="!detail.loading && detail.data && detail.data.available">
                  <div class="space-y-4">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-2 text-sm">
                        <div><span class="text-subtle">Време:</span> <span class="text-ink" x-text="detail.data.created_at"></span></div>
                        <div><span class="text-subtle">Провайдър:</span> <span class="text-ink" x-text="detail.data.provider"></span></div>
                        <div><span class="text-subtle">Модел:</span> <span class="text-ink" x-text="detail.data.model"></span></div>
                        <div><span class="text-subtle">Тип:</span> <span class="text-ink" x-text="detail.data.kind || '—'"></span></div>
                        <div><span class="text-subtle">Цена:</span> <span class="text-ink" x-text="'$' + Number(detail.data.cost_usd || 0).toFixed(6)"></span></div>
                        <div><span class="text-subtle">Времетраене:</span> <span class="text-ink" x-text="detail.data.duration_ms != null ? (detail.data.duration_ms/1000).toFixed(2) + ' сек.' : '—'"></span></div>
                    </div>

                    <div x-show="detail.data.options && Object.keys(detail.data.options).length" x-cloak>
                        <p class="text-xs text-subtle mb-1">Опции</p>
                        <pre class="text-xs bg-surface-subtle border border-line rounded-lg p-3 overflow-x-auto" x-text="JSON.stringify(detail.data.options, null, 2)"></pre>
                    </div>

                    <div x-show="detail.data.user_message">
                        <p class="text-xs text-subtle mb-1">Заявка</p>
                        <pre class="whitespace-pre-wrap text-sm text-ink bg-surface-subtle border border-line rounded-lg p-3 max-h-40 overflow-y-auto" x-text="detail.data.user_message"></pre>
                    </div>

                    <div>
                        <p class="text-xs text-subtle mb-1">Изход на модела</p>
                        <pre class="whitespace-pre-wrap text-sm text-ink bg-surface-subtle border border-line rounded-lg p-4 max-h-[55vh] overflow-y-auto" x-text="detail.data.response_text || '(празно)'"></pre>
                    </div>
                  </div>
                </template>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function knowledgePage(config) {
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    return {
        config,
        enabled: true, loading: true, error: '', busy: false,
        folders: [], stats: {}, factCategories: ['all'],
        tab: 'resources',
        tabs: [
            { key: 'resources', label: 'Ресурси' },
            { key: 'facts', label: 'Факти' },
            { key: 'history', label: 'История' },
            { key: 'gaps', label: 'Пропуски' },
            { key: 'conflicts', label: 'Конфликти' },
        ],
        selectedFolder: null,
        newFolderName: '', renaming: null, addingFolder: false, pollTimer: null,
        rTab: { items: [], total: 0, pages: 1, page: 1, search: '', sort: 'created_at', dir: 'desc', loading: false },
        fTab: { items: [], total: 0, pages: 1, page: 1, search: '', category: 'all', loading: false },
        hTab: { items: [], total: 0, pages: 1, page: 1, search: '', loading: false },
        gTab: { items: [], total: 0, pages: 1, page: 1, search: '', loading: false },
        cTab: { items: [], total: 0, pages: 1, page: 1, loading: false, scanning: false },
        addModal: { open: false, kind: 'url', url: '', noteTitle: '', noteContent: '', busy: false },
        noteEdit: { open: false, id: null, title: '', content: '', busy: false },
        preview: { open: false, loading: false, title: '', url: null, subtitle: '', content: '' },
        pagesModal: { open: false, loading: false, resource: null, pages: [] },
        detail: { open: false, loading: false, data: null },
        chat: { messages: [], input: '', session: null, pending: false, stage: '', pollTimer: null, fullscreen: false, sessions: [], showSessions: false, tip: { show: false, text: '', x: 0, y: 0 } },

        init() {
            this.$watch('tab', key => this.loadTab(key));
            this.$watch('rTab.search', debounce(() => { this.rTab.page = 1; this.loadResources(); }, 300));
            this.$watch('fTab.search', debounce(() => { this.fTab.page = 1; this.loadFacts(); }, 300));
            this.$watch('hTab.search', debounce(() => { this.hTab.page = 1; this.loadEvents(); }, 300));
            this.$watch('gTab.search', debounce(() => { this.gTab.page = 1; this.loadGaps(); }, 300));
            this.refresh();
            this.loadChatHistory();
        },

        async api(path, options = {}) {
            const res = await fetch((options.absolute ? '' : this.config.base) + path, {
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
                this.stats = data.stats;
                this.factCategories = ['all', ...(data.fact_categories || [])];
                this.busy = data.busy;
                this.error = '';
                clearTimeout(this.pollTimer);
                if (this.busy) this.pollTimer = setTimeout(() => this.refresh(), 4000);
                this.loadTab(this.tab);
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        loadTab(key) {
            if (key === 'resources') this.loadResources();
            else if (key === 'facts') this.loadFacts();
            else if (key === 'history') this.loadEvents();
            else if (key === 'gaps') this.loadGaps();
            else if (key === 'conflicts') this.loadConflicts();
        },

        async loadResources() {
            this.rTab.loading = true;
            try {
                const params = new URLSearchParams({
                    search: this.rTab.search,
                    sort: this.rTab.sort,
                    dir: this.rTab.dir,
                    page: this.rTab.page,
                });
                if (this.selectedFolder !== null) params.set('folder_id', this.selectedFolder);
                const data = await this.api('/resources?' + params);
                this.rTab.items = data.items;
                this.rTab.total = data.total;
                this.rTab.pages = data.pages;
            } catch (e) { this.error = e.message; }
            finally { this.rTab.loading = false; }
        },

        async loadFacts() {
            this.fTab.loading = true;
            try {
                const params = new URLSearchParams({ search: this.fTab.search, category: this.fTab.category, page: this.fTab.page });
                const data = await this.api('/facts?' + params);
                this.fTab.items = data.items;
                this.fTab.total = data.total;
                this.fTab.pages = data.pages;
            } catch (e) { this.error = e.message; }
            finally { this.fTab.loading = false; }
        },

        async loadEvents() {
            this.hTab.loading = true;
            try {
                const params = new URLSearchParams({ search: this.hTab.search, page: this.hTab.page });
                const data = await this.api('/events?' + params);
                this.hTab.items = data.items;
                this.hTab.total = data.total;
                this.hTab.pages = data.pages;
            } catch (e) { this.error = e.message; }
            finally { this.hTab.loading = false; }
        },

        async loadGaps() {
            this.gTab.loading = true;
            try {
                const params = new URLSearchParams({ search: this.gTab.search, page: this.gTab.page });
                const data = await this.api('/gaps?' + params);
                this.gTab.items = data.items;
                this.gTab.total = data.total;
                this.gTab.pages = data.pages;
            } catch (e) { this.error = e.message; }
            finally { this.gTab.loading = false; }
        },

        // ── Конфликти ──
        async loadConflicts() {
            this.cTab.loading = true;
            try {
                const data = await this.api('/conflicts?page=' + this.cTab.page);
                this.cTab.items = data.items;
                this.cTab.total = data.total;
                this.cTab.pages = data.pages;
            } catch (e) { this.error = e.message; }
            finally { this.cTab.loading = false; }
        },
        async scanConflicts() {
            this.cTab.scanning = true;
            try {
                await this.api('/conflicts/scan', { method: 'POST' });
                this.cTab.page = 1;
                await this.loadConflicts();
                this.refresh(); // обнови бейджа
            } catch (e) { this.error = e.message; }
            finally { this.cTab.scanning = false; }
        },
        async resolveConflict(id, factId) {
            try {
                await this.api('/conflicts/' + id + '/resolve', { method: 'POST', json: { winner_fact_id: factId } });
                await this.loadConflicts();
                this.refresh();
            } catch (e) { this.error = e.message; }
        },
        async ignoreConflict(id) {
            try {
                await this.api('/conflicts/' + id + '/ignore', { method: 'POST' });
                await this.loadConflicts();
                this.refresh();
            } catch (e) { this.error = e.message; }
        },

        async toggleEnabled() {
            try { this.enabled = (await this.api('/toggle', { method: 'POST' })).enabled; }
            catch (e) { this.error = e.message; }
        },

        tabCount(key) {
            if (key === 'resources') return this.stats.resources ?? 0;
            if (key === 'facts') return this.stats.facts ?? 0;
            if (key === 'history') return this.stats.events ?? 0;
            if (key === 'gaps') return this.stats.gaps ?? 0;
            if (key === 'conflicts') return this.stats.conflicts ?? 0;
            return 0;
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
        folderName(id) { return id ? (this.folders.find(f => f.id === id)?.name || '—') : '—'; },
        async createFolder(parentId = null) {
            const name = this.newFolderName.trim();
            if (!name) return;
            try {
                await this.api('/folders', { method: 'POST', json: { name, parent_id: parentId } });
                this.newFolderName = '';
                this.refresh();
            } catch (e) { this.error = e.message; }
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
            if (!confirm('Изтрий папка „' + folder.name + '"? Ресурсите в нея остават (падат в корена).')) return;
            try {
                await this.api('/folders/' + folder.id, { method: 'DELETE' });
                if (this.selectedFolder === folder.id) this.selectedFolder = null;
                this.refresh();
            } catch (e) { this.error = e.message; }
        },

        // ── Добавяне на ресурси ──
        openAddModal() {
            this.addModal = { open: true, kind: 'url', url: '', noteTitle: '', noteContent: '', busy: false };
        },
        async submitAdd() {
            if (this.addModal.kind === 'url') return this.submitUrl();
            if (this.addModal.kind === 'note') return this.submitNote();
            return this.submitFiles();
        },
        async submitUrl() {
            const url = this.addModal.url.trim();
            if (!url) return;
            this.addModal.busy = true;
            try {
                await this.api('/urls', { method: 'POST', json: { url, folder_id: this.selectedFolder } });
                this.addModal.open = false;
                this.refresh();
            } catch (e) { this.error = e.message; }
            finally { this.addModal.busy = false; }
        },
        async submitNote() {
            const title = this.addModal.noteTitle.trim();
            const content = this.addModal.noteContent.trim();
            if (!title || !content) return;
            this.addModal.busy = true;
            try {
                await this.api('/notes', { method: 'POST', json: { title, content, folder_id: this.selectedFolder } });
                this.addModal.open = false;
                this.refresh();
            } catch (e) { this.error = e.message; }
            finally { this.addModal.busy = false; }
        },
        async submitFiles() {
            const files = this.$refs.fileInput?.files || [];
            if (!files.length) return;
            this.addModal.busy = true;
            const form = new FormData();
            [...files].forEach(f => form.append('files[]', f));
            if (this.selectedFolder) form.append('folder_id', this.selectedFolder);
            try {
                const result = await this.api('/uploads', { method: 'POST', body: form });
                if (result.skipped?.length) this.error = 'Пропуснати (вече качени): ' + result.skipped.join(', ');
                this.addModal.open = false;
                this.refresh();
            } catch (e) { this.error = e.message; }
            finally { this.addModal.busy = false; }
        },

        // ── Ресурси ──
        async reingest(r) {
            try { await this.api('/resources/' + r.id + '/reingest', { method: 'POST', json: {} }); this.refresh(); }
            catch (e) { this.error = e.message; }
        },
        async deleteResource(r) {
            if (!confirm('Изтрий „' + r.title + '"? Цялото извлечено знание от него ще бъде забравено.')) return;
            try { await this.api('/resources/' + r.id, { method: 'DELETE' }); this.refresh(); }
            catch (e) { this.error = e.message; }
        },
        async openDigest(r) {
            this.preview = { open: true, loading: true, title: r.title, url: r.url, subtitle: 'Извлечена информация', content: '' };
            try {
                const data = await this.api('/resources/' + r.id + '/digest');
                this.preview.content = data.digest;
            } catch (e) { this.preview.content = e.message; }
            finally { this.preview.loading = false; }
        },
        openNoteEdit(r) {
            this.noteEdit = { open: true, id: r.id, title: r.title, content: r.content || '', busy: false };
        },
        async saveNote() {
            this.noteEdit.busy = true;
            try {
                await this.api('/notes/' + this.noteEdit.id, {
                    method: 'PATCH',
                    json: { title: this.noteEdit.title, content: this.noteEdit.content },
                });
                this.noteEdit.open = false;
                this.refresh();
            } catch (e) { this.error = e.message; }
            finally { this.noteEdit.busy = false; }
        },

        // ── Таблица (сортиране) ──
        sortBy(col) {
            if (this.rTab.sort === col) this.rTab.dir = this.rTab.dir === 'asc' ? 'desc' : 'asc';
            else { this.rTab.sort = col; this.rTab.dir = 'desc'; }
            this.rTab.page = 1;
            this.loadResources();
        },
        typeIcon(t) { return { url: '🌐', upload: '📄', image: '🖼', note: '📝' }[t] || '📄'; },
        typeLabel(t) { return { url: 'URL', upload: 'файл', image: 'снимка', note: 'бележка' }[t] || t; },
        formatSize(bytes) {
            if (!bytes) return '';
            return bytes > 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
        },

        // ── Страници ──
        async openPages(r) {
            this.pagesModal = { open: true, loading: true, resource: r, pages: [] };
            try {
                this.pagesModal.pages = (await this.api('/resources/' + r.id + '/pages')).pages;
            } catch (e) { this.error = e.message; }
            finally { this.pagesModal.loading = false; }
        },
        async openPageDigest(p) {
            this.preview = { open: true, loading: true, title: p.title || p.url, url: p.url, subtitle: '', content: '' };
            try {
                const data = await this.api('/pages/' + p.id + '/digest');
                this.preview.content = data.digest;
                this.preview.subtitle = (data.meta_description ? data.meta_description + ' · ' : '') + 'парсната ' + (data.parsed_at || '');
            } catch (e) { this.preview.content = e.message; }
            finally { this.preview.loading = false; }
        },
        async deletePage(p) {
            if (!confirm('Изтрий страницата „' + (p.title || p.url) + '" от знанията?')) return;
            try {
                await this.api('/pages/' + p.id, { method: 'DELETE' });
                this.pagesModal.pages = this.pagesModal.pages.filter(x => x.id !== p.id);
                this.refresh();
            } catch (e) { this.error = e.message; }
        },

        // ── Факти ──
        categoryLabel(c) {
            return { all: 'Всички', services: 'Услуги', prices: 'Цени', contacts: 'Контакти',
                     locations: 'Локации', about: 'За фирмата', team: 'Екип',
                     competitors: 'Конкуренти', faq: 'ЧЗВ', other: 'Друго' }[c] || c;
        },
        factSourceLabel(t) {
            return { resource: 'ресурс', page: 'страница', run: 'flow run', chat: 'чат' }[t] || t;
        },
        async deleteFact(f) {
            if (!confirm('Изтрий факта „' + f.name + '"?')) return;
            try { await this.api('/facts/' + f.id, { method: 'DELETE' }); this.refresh(); }
            catch (e) { this.error = e.message; }
        },

        // ── История ──
        subjectIcon(t) { return { resource: '📚', page: '🌐', fact: '💡' }[t] || '•'; },
        openSnippet(e) {
            this.preview = {
                open: true, loading: false, title: e.title, url: null,
                subtitle: (e.source || '') + ' · ' + e.created_at, content: e.snippet,
            };
        },

        // ── Пропуски ──
        async clearGaps() {
            if (!confirm('Изчисти всички записани пропуски?')) return;
            try { await this.api('/gaps', { method: 'DELETE' }); this.refresh(); }
            catch (e) { this.error = e.message; }
        },

        // ── Чат ──
        async loadChatHistory() {
            try {
                const data = await this.api('/chat/history');
                this.chat.session = data.session;
                this.chat.messages = data.messages || [];
                this.scrollChat();
            } catch { /* историята не е критична */ }
        },
        newChat() {
            this.chat.session = crypto.randomUUID();
            this.chat.messages = [];
            this.chat.showSessions = false;
        },
        toggleFullscreen() {
            this.chat.fullscreen = !this.chat.fullscreen;
            if (this.chat.fullscreen) this.loadSessions();
            this.scrollChat();
        },
        toggleSessions() {
            this.chat.showSessions = !this.chat.showSessions;
            if (this.chat.showSessions) this.loadSessions();
        },
        async loadSessions() {
            try {
                const data = await this.api('/chat/sessions');
                this.chat.sessions = data.sessions || [];
            } catch { /* списъкът не е критичен */ }
        },
        async openSession(uuid) {
            if (!uuid) return;
            this.chat.showSessions = false;
            try {
                const data = await this.api('/chat/history?session=' + encodeURIComponent(uuid));
                this.chat.session = data.session || uuid;
                this.chat.messages = data.messages || [];
                this.scrollChat();
            } catch (e) { this.error = e.message; }
        },
        // Markdown → sanitized HTML + цитати [N] като линкове с tooltip.
        renderAssistant(m) {
            if (!m || m.role !== 'assistant' || !m.content) return '';
            let html;
            try {
                html = window.marked ? window.marked.parse(m.content, { breaks: true, gfm: true }) : this.escapeHtml(m.content);
            } catch (e) { html = this.escapeHtml(m.content); }
            const sources = m.sources || [];
            html = html.replace(/\[(\d+)\]/g, (match, n) => {
                const s = sources[parseInt(n, 10) - 1];
                if (!s) return match;
                const tip = String(s.title || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
                if (s.url) {
                    return `<a href="${s.url}" target="_blank" rel="noopener" class="kb-cite" data-tip="${tip}">${n}</a>`;
                }
                return `<span class="kb-cite kb-cite--fact" data-tip="${tip}">${n}</span>`;
            });
            return window.DOMPurify ? window.DOMPurify.sanitize(html, { ADD_ATTR: ['target', 'data-tip', 'rel'] }) : html;
        },
        // Tooltip за цитат [N]: позиционира споделения .kb-tip спрямо badge-а (fixed, без отрязване/съкращаване).
        citeHover(e) {
            const el = e.target.closest && e.target.closest('.kb-cite[data-tip]');
            if (!el) return;
            const text = el.getAttribute('data-tip');
            if (!text) return;
            const r = el.getBoundingClientRect();
            const m = 8, half = 140; // half ≈ max-width/2, държи tooltip-а в екрана хоризонтално
            const x = Math.min(Math.max(r.left + r.width / 2, m + half), window.innerWidth - m - half);
            this.chat.tip = { show: true, text, x, y: r.top - 6 };
        },
        citeOut(e) {
            if (e.target.closest && e.target.closest('.kb-cite[data-tip]')) this.chat.tip.show = false;
        },
        escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        },
        async sendFeedback(m, vote) {
            if (!m || m.feedback || !m.id || String(m.id).startsWith('e') || String(m.id).startsWith('u')) return;
            const prev = m.feedback || null;
            m.feedback = vote; // оптимистично
            try {
                await this.api('/chat/' + m.id + '/feedback', { method: 'POST', json: { vote } });
                if (vote === 'up') this.refresh(); // нов ресурс → опресни статистики/ресурси
            } catch (e) {
                m.feedback = prev;
                this.error = e.message;
            }
        },
        async openDetail(m) {
            if (!m || !m.id || String(m.id).startsWith('e') || String(m.id).startsWith('u')) {
                this.detail = { open: true, loading: false, data: { available: false } };
                return;
            }
            this.detail = { open: true, loading: true, data: null };
            try {
                this.detail.data = await this.api('/chat/' + m.id + '/detail');
            } catch (e) {
                this.detail.data = { available: false };
                this.error = e.message;
            } finally {
                this.detail.loading = false;
            }
        },
        async sendChat() {
            const message = this.chat.input.trim();
            if (!message || this.chat.pending) return;
            this.chat.input = '';
            this.chat.messages.push({ id: 'u' + Date.now(), role: 'user', content: message });
            this.chat.pending = true;
            this.chat.stage = 'Мисля…';
            this.scrollToQuestion();
            try {
                const res = await this.api('/chat', { method: 'POST', json: { message, session: this.chat.session } });
                this.chat.session = res.session;
                this.pollChat(res.token);
            } catch (e) {
                this.chat.pending = false;
                this.chat.messages.push({ id: 'e' + Date.now(), role: 'assistant', content: e.message, failed: true });
            }
        },
        pollChat(token) {
            clearTimeout(this.chat.pollTimer);
            this.chat.pollTimer = setTimeout(async () => {
                try {
                    const data = await this.api('/knowledge-chat-status/' + token, { absolute: true });
                    if (data.status === 'completed') {
                        this.chat.pending = false;
                        this.chat.messages.push(data.message);
                        this.scrollToQuestion();
                        this.refresh();
                        return;
                    }
                    if (data.status === 'failed' || data.status === 'expired') {
                        this.chat.pending = false;
                        this.chat.messages.push({ id: 'e' + Date.now(), role: 'assistant', content: data.error || 'Грешка.', failed: true });
                        return;
                    }
                    this.chat.stage = data.stage || 'Мисля…';
                    this.pollChat(token);
                } catch {
                    this.pollChat(token);
                }
            }, 1500);
        },
        scrollChat() {
            this.$nextTick(() => {
                const el = this.$refs.chatScroll;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        scrollToQuestion() {
            this.$nextTick(() => {
                const el = this.$refs.chatScroll;
                if (!el) return;
                const userBubbles = el.querySelectorAll('.flex.justify-end');
                const last = userBubbles[userBubbles.length - 1];
                if (last) last.scrollIntoView({ block: 'start' });
                else el.scrollTop = el.scrollHeight;
            });
        },
    };
}
</script>
@endpush
