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
                      x-text="stats.resources + ' ресурса · ' + stats.pages + ' страници · ' + stats.facts + ' факта'"></span>
            </h1>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button @click="toggleEnabled()"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border transition"
                    :class="enabled ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-50 border-gray-200 text-gray-400'">
                <span class="w-2 h-2 rounded-full" :class="enabled ? 'bg-green-500' : 'bg-gray-300'"></span>
                <span x-text="enabled ? 'Включена' : 'Изключена'"></span>
            </button>
            <button @click="openAddModal()"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                ＋ Добави ресурс
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

    <div class="flex gap-6 items-start">

        {{-- ─────────── Sidebar: папки + тип ─────────── --}}
        <div class="w-60 shrink-0 bg-white rounded-xl border border-gray-200 p-4">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Папки</h2>

            <button @click="selectedFolder = null"
                    class="w-full text-left px-2 py-1.5 rounded-lg text-sm mb-1 transition"
                    :class="selectedFolder === null ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-50'">
                Всички
                <span class="text-xs text-gray-400 float-right mt-0.5" x-text="resources.length"></span>
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

            <div class="mt-4 pt-3 border-t border-gray-100">
                <h3 class="text-xs font-semibold text-gray-400 uppercase mb-2">Тип ресурс</h3>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="f in typeFilters" :key="f.key">
                        <button @click="typeFilter = f.key"
                                class="px-2 py-1 rounded-full text-xs font-medium transition"
                                :class="typeFilter === f.key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                                x-text="f.label"></button>
                    </template>
                </div>
            </div>
        </div>

        {{-- ─────────── Main: tabs ─────────── --}}
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1 mb-4">
                <template x-for="t in tabs" :key="t.key">
                    <button @click="tab = t.key"
                            class="px-3.5 py-2 rounded-lg text-sm font-medium transition"
                            :class="tab === t.key ? 'bg-gray-900 text-white' : 'text-gray-500 hover:bg-gray-100'">
                        <span x-text="t.label"></span>
                        <span class="text-xs opacity-60" x-text="'(' + tabCount(t.key) + ')'"></span>
                    </button>
                </template>
                <div class="flex-1"></div>
                <span class="text-xs text-gray-400" x-show="busy">
                    <span class="inline-block animate-spin mr-1">◌</span> обработка…
                </span>
                <span class="text-xs text-gray-400" x-text="'общо $' + (stats.cost_usd || 0).toFixed(4)"></span>
            </div>

            {{-- ── TAB: Ресурси ── --}}
            <div x-show="tab === 'resources'" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
                    <input type="text" x-model="search" placeholder="Търси по заглавие…"
                           class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>

                <div x-show="loading" class="px-4 py-10 text-center text-gray-400 text-sm">Зареждане…</div>

                <div x-show="!loading && filteredResources().length === 0" x-cloak class="px-4 py-12 text-center">
                    <p class="text-2xl mb-2">📚</p>
                    <p class="text-gray-500 font-medium mb-1">Няма ресурси</p>
                    <p class="text-gray-400 text-sm">Добави URL на сайта, ценоразписи, каталози или бележки — агентите ще ги ползват като достоверен източник.</p>
                </div>

                <table x-show="!loading && filteredResources().length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-400 uppercase border-b border-gray-100">
                            <th class="px-4 py-2 cursor-pointer hover:text-gray-600" @click="sortBy('title')">Ресурс</th>
                            <th class="px-2 py-2">Папка</th>
                            <th class="px-2 py-2 cursor-pointer hover:text-gray-600" @click="sortBy('status')">Статус</th>
                            <th class="px-2 py-2 text-right">Чанкове</th>
                            <th class="px-2 py-2 cursor-pointer hover:text-gray-600" @click="sortBy('created_at')">Дата</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in pagedResources()" :key="r.id">
                            <tr class="border-b border-gray-50 hover:bg-gray-50 group">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span x-text="typeIcon(r.type)" :title="typeLabel(r.type)"></span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-gray-800 truncate max-w-sm" x-text="r.title"
                                                 :title="r.original_name || r.url || r.title"></div>
                                            <div class="text-xs text-gray-400 truncate max-w-sm">
                                                <a x-show="r.url" :href="r.url" target="_blank" class="hover:text-indigo-600 hover:underline" x-text="r.url"></a>
                                                <span x-show="r.type === 'upload' || r.type === 'image'" x-text="formatSize(r.size_bytes)"></span>
                                                <button x-show="r.type === 'url' && r.pages_count" @click="openPages(r)"
                                                        class="text-indigo-500 hover:underline"
                                                        x-text="r.pages_count + ' страници' + (r.partial ? ' (частично)' : '')"></button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-2 py-2.5 text-gray-500 text-xs" x-text="folderName(r.folder_id)"></td>
                                <td class="px-2 py-2.5 whitespace-nowrap">
                                    <span x-show="r.status === 'pending'" title="Чака обработка">⏳</span>
                                    <span x-show="r.status === 'processing'" class="text-indigo-500 text-xs">
                                        <span class="inline-block animate-spin">◌</span>
                                        <span x-show="r.progress" x-text="(r.progress?.parsed || 0) + '/' + (r.progress?.discovered || '?')"></span>
                                    </span>
                                    <span x-show="r.status === 'ready'" class="text-green-600" title="Готов">✓</span>
                                    <span x-show="r.status === 'failed'" class="text-red-500 cursor-help" :title="r.error">✗</span>
                                </td>
                                <td class="px-2 py-2.5 text-right text-gray-500" x-text="r.chunk_count"></td>
                                <td class="px-2 py-2.5 text-gray-400 text-xs" x-text="r.created_at"></td>
                                <td class="px-2 py-2.5 text-right whitespace-nowrap">
                                    <div class="opacity-0 group-hover:opacity-100 transition-opacity inline-flex gap-1">
                                        <button x-show="r.has_digest" @click="openDigest(r)" title="Преглед на извлечената информация"
                                                class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">👁</button>
                                        <button x-show="r.type === 'url'" @click="openPages(r)" title="Страници"
                                                class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">📑</button>
                                        <button x-show="r.type === 'note'" @click="openNoteEdit(r)" title="Редактирай бележката"
                                                class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">✏</button>
                                        <a x-show="r.type === 'upload' || r.type === 'image'"
                                           :href="config.base + '/resources/' + r.id + '/download'" title="Свали оригинала"
                                           class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">⬇</a>
                                        <button @click="reingest(r)" title="Преиндексирай"
                                                class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">↻</button>
                                        <button @click="deleteResource(r)" title="Изтрий (забравя знанието)"
                                                class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50">🗑</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div x-show="!loading && filteredResources().length > pageSize" x-cloak
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

            {{-- ── TAB: Факти ── --}}
            <div x-show="tab === 'facts'" x-cloak class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-100 flex-wrap">
                    <template x-for="c in factCategories()" :key="c">
                        <button @click="factCategory = c"
                                class="px-2 py-1 rounded-full text-xs font-medium transition"
                                :class="factCategory === c ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                                x-text="categoryLabel(c)"></button>
                    </template>
                </div>
                <div x-show="filteredFacts().length === 0" class="px-4 py-12 text-center">
                    <p class="text-2xl mb-2">💡</p>
                    <p class="text-gray-500 font-medium mb-1">Още няма факти</p>
                    <p class="text-gray-400 text-sm">Фактите се извличат автоматично при добавяне на ресурси и след всеки успешен run на flow.</p>
                </div>
                <table x-show="filteredFacts().length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-400 uppercase border-b border-gray-100">
                            <th class="px-4 py-2">Факт</th>
                            <th class="px-2 py-2">Категория</th>
                            <th class="px-2 py-2">Локация</th>
                            <th class="px-2 py-2">Източник</th>
                            <th class="px-2 py-2">Обновен</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="f in filteredFacts()" :key="f.id">
                            <tr class="border-b border-gray-50 hover:bg-gray-50 group align-top">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-800" x-text="f.name"></div>
                                    <div class="text-xs text-gray-600 whitespace-pre-line" x-text="f.value"></div>
                                </td>
                                <td class="px-2 py-2.5">
                                    <span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 text-xs" x-text="categoryLabel(f.category)"></span>
                                </td>
                                <td class="px-2 py-2.5 text-xs text-gray-500" x-text="f.location || '—'"></td>
                                <td class="px-2 py-2.5 text-xs">
                                    <a x-show="f.flow_run_id" :href="'/runs/' + f.flow_run_id"
                                       class="text-indigo-600 hover:underline" x-text="'run #' + f.flow_run_id"></a>
                                    <span x-show="!f.flow_run_id" class="text-gray-400" x-text="factSourceLabel(f.source_type)"></span>
                                </td>
                                <td class="px-2 py-2.5 text-xs text-gray-400" x-text="f.updated_at"></td>
                                <td class="px-2 py-2.5 text-right">
                                    <button @click="deleteFact(f)" title="Изтрий факта"
                                            class="opacity-0 group-hover:opacity-100 p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50">🗑</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- ── TAB: История ── --}}
            <div x-show="tab === 'history'" x-cloak class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div x-show="events.length === 0" class="px-4 py-12 text-center text-gray-400 text-sm">
                    Още няма събития — историята записва всяко добавено/обновено/изтрито знание.
                </div>
                <table x-show="events.length > 0" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-400 uppercase border-b border-gray-100">
                            <th class="px-4 py-2">Знание</th>
                            <th class="px-2 py-2">Действие</th>
                            <th class="px-2 py-2">От къде е дошло</th>
                            <th class="px-2 py-2">Кога</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="e in events" :key="e.id">
                            <tr class="border-b border-gray-50 hover:bg-gray-50 group">
                                <td class="px-4 py-2.5">
                                    <span x-text="subjectIcon(e.subject_type)"></span>
                                    <span class="font-medium text-gray-800" x-text="e.title"></span>
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
                                <td class="px-2 py-2.5 text-xs text-gray-500" x-text="e.source || '—'"></td>
                                <td class="px-2 py-2.5 text-xs text-gray-400" x-text="e.created_at"></td>
                                <td class="px-2 py-2.5 text-right">
                                    <button x-show="e.snippet" @click="openSnippet(e)" title="Преглед на съдържанието"
                                            class="opacity-0 group-hover:opacity-100 p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">👁</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- ── TAB: Пропуски ── --}}
            <div x-show="tab === 'gaps'" x-cloak class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs text-gray-400">
                        Агентите (или чатът) са търсили това, но не са намерили добро покритие — качи ресурс по темата.
                        Когато ново знание покрие пропуска, той става <span class="text-green-600 font-medium">готов</span> автоматично.
                    </p>
                    <button x-show="gaps.length" @click="clearGaps()"
                            class="text-xs text-gray-400 hover:text-red-600 transition shrink-0 ml-3">Изчисти всички</button>
                </div>
                <div x-show="!gaps.length" class="text-sm text-gray-400 py-2">
                    Няма пропуски — търсенията намират покритие.
                </div>
                <table x-show="gaps.length" x-cloak class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-400 uppercase border-b border-gray-100">
                            <th class="py-2 pr-2">Заявка</th>
                            <th class="py-2 pr-2">Статус</th>
                            <th class="py-2 pr-2 text-right">Score</th>
                            <th class="py-2 pr-2">Run</th>
                            <th class="py-2">Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="gap in gaps" :key="gap.id">
                            <tr class="border-b border-gray-50">
                                <td class="py-2 pr-2 text-gray-700" x-text="gap.query"></td>
                                <td class="py-2 pr-2">
                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium"
                                          :class="gap.status === 'resolved' ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600'"
                                          :title="gap.resolved_by ? 'Запълнен от: ' + gap.resolved_by : ''"
                                          x-text="gap.status === 'resolved' ? 'готов' : 'отворен'"></span>
                                </td>
                                <td class="py-2 pr-2 text-right font-mono text-xs text-gray-400"
                                    x-text="gap.best_score !== null ? gap.best_score.toFixed(2) : '—'"></td>
                                <td class="py-2 pr-2 text-xs">
                                    <a x-show="gap.flow_run_id" :href="'/runs/' + gap.flow_run_id"
                                       class="text-indigo-600 hover:underline" x-text="'#' + gap.flow_run_id"></a>
                                    <span x-show="!gap.flow_run_id" class="text-gray-300">—</span>
                                </td>
                                <td class="py-2 text-xs text-gray-400" x-text="gap.created_at"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ─────────── Chat: Тествай знанията ─────────── --}}
        <div class="w-96 shrink-0 bg-white rounded-xl border border-gray-200 flex flex-col" style="height: calc(100vh - 180px); position: sticky; top: 1rem;">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-700">💬 Тествай знанията</h2>
                <button @click="newChat()" class="text-xs text-gray-400 hover:text-indigo-600 transition" title="Нов разговор">⟳ нов</button>
            </div>

            <div class="flex-1 overflow-y-auto p-3 space-y-3" x-ref="chatScroll">
                <div x-show="chat.messages.length === 0 && !chat.pending" class="text-center text-gray-400 text-sm py-8 px-4">
                    Питай на човешки език:<br>
                    <button @click="chat.input = 'Каква е цената на лазерна епилация на подмишници?'"
                            class="mt-2 text-xs text-indigo-500 hover:underline">„Каква е цената на лазерна епилация на подмишници?“</button>
                </div>

                <template x-for="m in chat.messages" :key="m.id">
                    <div :class="m.role === 'user' ? 'flex justify-end' : ''">
                        <div class="rounded-xl px-3 py-2 text-sm max-w-[92%]"
                             :class="m.role === 'user' ? 'bg-indigo-600 text-white' : (m.failed ? 'bg-red-50 text-red-700 border border-red-100' : 'bg-gray-50 text-gray-800 border border-gray-100')">
                            <div class="whitespace-pre-wrap" x-text="m.content"></div>
                            <div x-show="m.sources && m.sources.length" class="mt-2 pt-2 border-t border-gray-200 space-y-0.5">
                                <template x-for="s in (m.sources || [])" :key="s.n">
                                    <div class="text-xs text-gray-500 truncate">
                                        <span class="font-mono" x-text="'[' + s.n + ']'"></span>
                                        <a x-show="s.url" :href="s.url" target="_blank" class="hover:text-indigo-600 hover:underline" x-text="s.title"></a>
                                        <span x-show="!s.url" x-text="s.title + (s.kind === 'fact' ? ' (факт)' : '')"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>

                <div x-show="chat.pending" x-cloak>
                    <div class="rounded-xl px-3 py-2 text-sm bg-gray-50 border border-gray-100 text-gray-400 inline-flex items-center gap-2">
                        <span class="inline-block animate-spin">◌</span>
                        <span x-text="chat.stage || 'Мисля…'"></span>
                    </div>
                </div>
            </div>

            <div class="p-3 border-t border-gray-100">
                <div class="flex gap-2">
                    <textarea x-model="chat.input" rows="2" placeholder="Въпрос към базата знания…"
                              @keydown.enter.prevent="$event.shiftKey ? chat.input += '\n' : sendChat()"
                              class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                    <button @click="sendChat()" :disabled="chat.pending || !chat.input.trim()"
                            class="bg-gray-900 hover:bg-black text-white px-3 rounded-lg text-sm font-medium transition disabled:opacity-40">➤</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────── Modal: добави ресурс ─────────── --}}
    <div x-show="addModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="addModal.open = false">
        <div class="absolute inset-0 bg-black/40" @click="addModal.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg p-5">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">＋ Добави ресурс</h3>

            <div class="flex gap-1 mb-4">
                <template x-for="k in [['url','🌐 URL адрес'],['files','📄 Файлове / снимки'],['note','📝 Бележка']]" :key="k[0]">
                    <button @click="addModal.kind = k[0]"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium transition"
                            :class="addModal.kind === k[0] ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                            x-text="k[1]"></button>
                </template>
            </div>

            {{-- URL --}}
            <div x-show="addModal.kind === 'url'">
                <p class="text-xs text-gray-400 mb-2">Сайт или конкретна страница — обхождам всички вътрешни страници (вкл. пагинация), извличам съдържанието и го синтезирам.</p>
                <input type="url" x-model="addModal.url" placeholder="https://example.bg"
                       @keydown.enter="submitUrl()"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            {{-- Files --}}
            <div x-show="addModal.kind === 'files'" x-cloak>
                <p class="text-xs text-gray-400 mb-2">PDF, Word, Excel, CSV, текст или снимки (JPG/PNG → OCR). Ценоразписи, каталози, условия…</p>
                <input type="file" multiple x-ref="fileInput"
                       accept=".pdf,.txt,.md,.docx,.xlsx,.csv,.jpg,.jpeg,.png"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>

            {{-- Note --}}
            <div x-show="addModal.kind === 'note'" x-cloak class="space-y-2">
                <p class="text-xs text-gray-400">Бележка с описание — фирмени факти, които ги няма другаде (условия, вътрешна информация, уточнения).</p>
                <input type="text" x-model="addModal.noteTitle" placeholder="Заглавие"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <textarea x-model="addModal.noteContent" rows="8" placeholder="Съдържание…"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
            </div>

            <div class="flex items-center justify-between mt-4">
                <span class="text-xs text-gray-400" x-show="selectedFolder !== null" x-text="'Папка: ' + folderName(selectedFolder)"></span>
                <span x-show="selectedFolder === null"></span>
                <div class="flex gap-2">
                    <button @click="addModal.open = false"
                            class="px-4 py-2 rounded-lg text-sm text-gray-500 hover:bg-gray-100 transition">Отказ</button>
                    <button @click="submitAdd()" :disabled="addModal.busy"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50">
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
        <div class="absolute inset-0 bg-black/40" @click="noteEdit.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg p-5">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">✏ Редакция на бележка</h3>
            <div class="space-y-2">
                <input type="text" x-model="noteEdit.title"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <textarea x-model="noteEdit.content" rows="10"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <button @click="noteEdit.open = false"
                        class="px-4 py-2 rounded-lg text-sm text-gray-500 hover:bg-gray-100 transition">Отказ</button>
                <button @click="saveNote()" :disabled="noteEdit.busy"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50">
                    Запази (преиндексира)
                </button>
            </div>
        </div>
    </div>

    {{-- ─────────── Modal: digest / snippet преглед ─────────── --}}
    <div x-show="preview.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="preview.open = false">
        <div class="absolute inset-0 bg-black/40" @click="preview.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl p-5 max-h-[85vh] flex flex-col">
            <div class="flex items-start justify-between mb-3">
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-gray-900 truncate" x-text="preview.title"></h3>
                    <a x-show="preview.url" :href="preview.url" target="_blank"
                       class="text-xs text-indigo-500 hover:underline truncate block" x-text="preview.url"></a>
                    <p x-show="preview.subtitle" class="text-xs text-gray-400 mt-0.5" x-text="preview.subtitle"></p>
                </div>
                <button @click="preview.open = false" class="text-gray-400 hover:text-gray-600 ml-3">✕</button>
            </div>
            <div class="overflow-y-auto text-sm text-gray-700 whitespace-pre-wrap border border-gray-100 rounded-lg bg-gray-50 p-4"
                 x-text="preview.loading ? 'Зареждане…' : (preview.content || 'Няма извлечена информация.')"></div>
        </div>
    </div>

    {{-- ─────────── Modal: страници на url ресурс ─────────── --}}
    <div x-show="pagesModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="pagesModal.open = false">
        <div class="absolute inset-0 bg-black/40" @click="pagesModal.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-4xl p-5 max-h-[85vh] flex flex-col">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">📑 Обходени страници</h3>
                    <p class="text-xs text-gray-400" x-text="pagesModal.resource?.url"></p>
                </div>
                <button @click="pagesModal.open = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <div class="overflow-y-auto">
                <div x-show="pagesModal.loading" class="py-8 text-center text-gray-400 text-sm">Зареждане…</div>
                <table x-show="!pagesModal.loading" class="w-full text-sm">
                    <thead class="sticky top-0 bg-white">
                        <tr class="text-left text-xs text-gray-400 uppercase border-b border-gray-100">
                            <th class="py-2 pr-2">Title / URL</th>
                            <th class="py-2 pr-2">Meta описание</th>
                            <th class="py-2 pr-2">Парсната</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="p in pagesModal.pages" :key="p.id">
                            <tr class="border-b border-gray-50 hover:bg-gray-50 group align-top">
                                <td class="py-2 pr-2 max-w-sm">
                                    <div class="font-medium text-gray-800 truncate" x-text="p.title || '(без заглавие)'"></div>
                                    <a :href="p.url" target="_blank"
                                       class="text-xs text-gray-400 hover:text-indigo-600 hover:underline truncate block" x-text="p.url"></a>
                                </td>
                                <td class="py-2 pr-2 text-xs text-gray-500 max-w-xs">
                                    <div class="line-clamp-2" x-text="p.meta_description || '—'"></div>
                                </td>
                                <td class="py-2 pr-2 text-xs text-gray-400 whitespace-nowrap" x-text="p.parsed_at || '—'"></td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <div class="opacity-0 group-hover:opacity-100 inline-flex gap-1">
                                        <a :href="p.url" target="_blank" title="Отвори страницата"
                                           class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">↗</a>
                                        <button @click="openPageDigest(p)" title="Извлечената информация"
                                                class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">👁</button>
                                        <button @click="deletePage(p)" title="Изтрий страницата"
                                                class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50">🗑</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
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
        folders: [], resources: [], facts: [], events: [], gaps: [], stats: {},
        tab: 'resources',
        tabs: [
            { key: 'resources', label: '📚 Ресурси' },
            { key: 'facts', label: '💡 Факти' },
            { key: 'history', label: '🕓 История' },
            { key: 'gaps', label: '🕳 Пропуски' },
        ],
        selectedFolder: null, typeFilter: 'all', factCategory: 'all',
        search: '', sortCol: 'created_at', sortDir: 'desc', page: 1, pageSize: 15,
        newFolderName: '', renaming: null, pollTimer: null,
        typeFilters: [
            { key: 'all', label: 'Всички' },
            { key: 'url', label: '🌐 URL' },
            { key: 'upload', label: '📄 Файлове' },
            { key: 'image', label: '🖼 Снимки' },
            { key: 'note', label: '📝 Бележки' },
        ],
        addModal: { open: false, kind: 'url', url: '', noteTitle: '', noteContent: '', busy: false },
        noteEdit: { open: false, id: null, title: '', content: '', busy: false },
        preview: { open: false, loading: false, title: '', url: null, subtitle: '', content: '' },
        pagesModal: { open: false, loading: false, resource: null, pages: [] },
        chat: { messages: [], input: '', session: null, pending: false, stage: '', pollTimer: null },

        init() {
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
                this.resources = data.resources;
                this.facts = data.facts;
                this.events = data.events;
                this.gaps = data.gaps || [];
                this.stats = data.stats;
                this.busy = data.busy;
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

        tabCount(key) {
            return { resources: this.resources.length, facts: this.facts.length,
                     history: this.events.length, gaps: this.gaps.length }[key] ?? 0;
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
            if (!confirm('Изтрий папка „' + folder.name + '“? Ресурсите в нея остават (падат в корена).')) return;
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
            if (!confirm('Изтрий „' + r.title + '“? Цялото извлечено знание от него ще бъде забравено.')) return;
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
            if (!confirm('Изтрий страницата „' + (p.title || p.url) + '“ от знанията?')) return;
            try {
                await this.api('/pages/' + p.id, { method: 'DELETE' });
                this.pagesModal.pages = this.pagesModal.pages.filter(x => x.id !== p.id);
                this.refresh();
            } catch (e) { this.error = e.message; }
        },

        // ── Факти ──
        factCategories() {
            return ['all', ...new Set(this.facts.map(f => f.category))];
        },
        filteredFacts() {
            return this.factCategory === 'all' ? this.facts : this.facts.filter(f => f.category === this.factCategory);
        },
        categoryLabel(c) {
            return { all: 'Всички', services: 'Услуги', prices: 'Цени', contacts: 'Контакти',
                     locations: 'Локации', about: 'За фирмата', team: 'Екип',
                     competitors: 'Конкуренти', faq: 'ЧЗВ', other: 'Друго' }[c] || c;
        },
        factSourceLabel(t) {
            return { resource: 'ресурс', page: 'страница', run: 'flow run', chat: 'чат' }[t] || t;
        },
        async deleteFact(f) {
            if (!confirm('Изтрий факта „' + f.name + '“?')) return;
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

        // ── Таблица (ресурси) ──
        filteredResources() {
            let rs = this.resources;
            if (this.selectedFolder !== null) rs = rs.filter(r => r.folder_id === this.selectedFolder);
            if (this.typeFilter !== 'all') rs = rs.filter(r => r.type === this.typeFilter);
            const q = this.search.trim().toLowerCase();
            if (q) rs = rs.filter(r => (r.title || '').toLowerCase().includes(q) || (r.url || '').toLowerCase().includes(q));
            const dir = this.sortDir === 'asc' ? 1 : -1;
            const col = this.sortCol;
            return [...rs].sort((a, b) => (a[col] > b[col] ? 1 : a[col] < b[col] ? -1 : 0) * dir);
        },
        pagedResources() {
            return this.filteredResources().slice((this.page - 1) * this.pageSize, this.page * this.pageSize);
        },
        totalPages() { return Math.max(1, Math.ceil(this.filteredResources().length / this.pageSize)); },
        sortBy(col) {
            if (this.sortCol === col) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            else { this.sortCol = col; this.sortDir = 'desc'; }
            this.page = 1;
        },
        typeIcon(t) { return { url: '🌐', upload: '📄', image: '🖼', note: '📝' }[t] || '📄'; },
        typeLabel(t) { return { url: 'URL', upload: 'файл', image: 'снимка', note: 'бележка' }[t] || t; },
        formatSize(bytes) {
            if (!bytes) return '';
            return bytes > 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
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
        },
        async sendChat() {
            const message = this.chat.input.trim();
            if (!message || this.chat.pending) return;
            this.chat.input = '';
            this.chat.messages.push({ id: 'u' + Date.now(), role: 'user', content: message });
            this.chat.pending = true;
            this.chat.stage = 'Мисля…';
            this.scrollChat();
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
                        this.scrollChat();
                        this.refresh(); // нов gap може да се е появил
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
    };
}
</script>
@endpush
