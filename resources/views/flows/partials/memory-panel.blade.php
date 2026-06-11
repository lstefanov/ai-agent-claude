{{-- Памет на flow-а: datatable за запомнено съдържание и поуки.
     Споделя Alpine scope-а на flowBuilder(). --}}
<div x-show="memoryPanel.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
     @keydown.escape.window="memoryPanel.open = false">
    <div class="absolute inset-0 bg-black/40" @click="memoryPanel.open = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col" @click.stop>

        {{-- Header --}}
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3 shrink-0">
            <h3 class="text-lg font-bold text-gray-900">🧠 Памет на flow-а</h3>
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2 cursor-pointer select-none"
                       title="Изключена памет = run-овете не четат и не записват памет">
                    <span class="text-xs text-gray-500 font-medium">Памет</span>
                    <button type="button" @click="toggleMemory()" :disabled="memoryPanel.toggling"
                            class="relative w-9 h-5 rounded-full transition"
                            :class="memoryPanel.enabled ? 'bg-emerald-500' : 'bg-gray-300'">
                        <span class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-all"
                              :class="memoryPanel.enabled ? 'left-[18px]' : 'left-0.5'"></span>
                    </button>
                </label>
                <button @click="memoryPanel.open = false" type="button"
                        class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>
        </div>

        {{-- Tabs + Search --}}
        <div class="flex items-end px-6 pt-3 border-b border-gray-200 gap-3 shrink-0">
            <div class="flex gap-1 flex-1">
                <button type="button"
                        @click="memoryPanel.tab = 'outputs'; memoryPanel.page = 1"
                        :class="memoryPanel.tab === 'outputs'
                            ? 'border-indigo-600 text-indigo-700 font-semibold bg-indigo-50'
                            : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-4 py-2 text-sm border-b-2 -mb-px rounded-t-lg transition">
                    Съдържание
                    <span class="text-xs opacity-60" x-text="'(' + memoryPanel.outputs.length + ')'"></span>
                </button>
                <button type="button"
                        @click="memoryPanel.tab = 'lessons'; memoryPanel.page = 1"
                        :class="memoryPanel.tab === 'lessons'
                            ? 'border-indigo-600 text-indigo-700 font-semibold bg-indigo-50'
                            : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-4 py-2 text-sm border-b-2 -mb-px rounded-t-lg transition">
                    Поуки
                    <span class="text-xs opacity-60" x-text="'(' + memoryPanel.lessons.length + ')'"></span>
                </button>
            </div>
            <div class="pb-2">
                <input type="text" x-model="memoryPanel.search" @input="memoryPanel.page = 1"
                       placeholder="Търси…"
                       class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 w-52 focus:outline-none focus:ring-2 focus:ring-indigo-300 placeholder-gray-400">
            </div>
        </div>

        {{-- Loading / Error --}}
        <div x-show="memoryPanel.loading || memoryPanel.error" class="px-6 py-3 shrink-0">
            <p x-show="memoryPanel.loading" class="text-sm text-gray-400">Зареждане…</p>
            <p x-show="memoryPanel.error" class="text-sm text-red-600" x-text="memoryPanel.error"></p>
        </div>

        {{-- Datatable area (scrollable) --}}
        <div class="overflow-auto flex-1 px-6 pt-2 pb-1">

            {{-- ── Съдържание (output дайджести) ── --}}
            <template x-if="memoryPanel.tab === 'outputs'">
                <div>
                    <p x-show="!memoryPanel.loading && !memoryPanel.error && memoryPanel.outputs.length === 0"
                       class="text-sm text-gray-400 text-center py-12">
                        Все още няма запомнено съдържание — паметта се пълни след всяко успешно изпълнение.
                    </p>
                    <template x-if="memoryPanel.outputs.length > 0">
                        <table class="w-full text-sm border-separate border-spacing-0">
                            <thead class="sticky top-0 bg-white z-10">
                                <tr class="text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-200">
                                    <th @click="memSort('node_name')"
                                        class="text-left py-2.5 pr-3 font-semibold cursor-pointer select-none hover:text-gray-700 w-32 whitespace-nowrap">
                                        Агент
                                        <span x-show="memoryPanel.sortCol === 'node_name'"
                                              x-text="memoryPanel.sortDir === 'asc' ? ' ↑' : ' ↓'"></span>
                                    </th>
                                    <th @click="memSort('created_at')"
                                        class="text-left py-2.5 pr-3 font-semibold cursor-pointer select-none hover:text-gray-700 w-32 whitespace-nowrap">
                                        Дата
                                        <span x-show="memoryPanel.sortCol === 'created_at'"
                                              x-text="memoryPanel.sortDir === 'asc' ? ' ↑' : ' ↓'"></span>
                                    </th>
                                    <th @click="memSort('title')"
                                        class="text-left py-2.5 pr-3 font-semibold cursor-pointer select-none hover:text-gray-700 w-48">
                                        Заглавие
                                        <span x-show="memoryPanel.sortCol === 'title'"
                                              x-text="memoryPanel.sortDir === 'asc' ? ' ↑' : ' ↓'"></span>
                                    </th>
                                    <th class="text-left py-2.5 pr-3 font-semibold">Резюме</th>
                                    <th class="text-center py-2.5 font-semibold w-8"
                                        title="Embedding — записи с ✓ участват в автоматичната проверка за сходство">Emb</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="row in memPagedRows()" :key="row.id">
                                    <tr class="hover:bg-indigo-50/40 transition-colors group">
                                        <td class="py-2 pr-3 w-32">
                                            <div class="truncate text-xs font-medium text-indigo-700"
                                                 :title="row.node_name || row.node_key"
                                                 x-text="row.node_name || row.node_key"></div>
                                        </td>
                                        <td class="py-2 pr-3 text-xs text-gray-400 whitespace-nowrap"
                                            x-text="row.created_at"></td>
                                        <td class="py-2 pr-3 w-48">
                                            <div class="truncate text-xs text-gray-700"
                                                 :title="row.title"
                                                 x-text="row.title ? '„' + row.title + '"' : '—'"></div>
                                        </td>
                                        <td class="py-2 pr-3 align-top">
                                            <div class="text-xs text-gray-500 line-clamp-3 leading-relaxed"
                                                 x-text="row.summary"></div>
                                            <button @click="openMemoryPreview(row.node_name || row.node_key, row.title, row.summary)"
                                                    class="mt-1 text-[10px] text-indigo-400 hover:text-indigo-600 transition-colors">
                                                Виж всичко →
                                            </button>
                                        </td>
                                        <td class="py-2 text-center">
                                            <span x-show="row.has_embedding"
                                                  class="text-emerald-500 text-xs font-bold"
                                                  title="С embedding — участва в проверката за сходство">✓</span>
                                            <span x-show="!row.has_embedding"
                                                  class="text-amber-400 text-xs"
                                                  title="Без embedding — само prompt инжекция">⚠</span>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="memFilteredRows().length === 0 && memoryPanel.search !== ''">
                                    <td colspan="5" class="py-8 text-center text-sm text-gray-400">
                                        Няма резултати за „<span x-text="memoryPanel.search"></span>"
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </div>
            </template>

            {{-- ── Поуки per агент ── --}}
            <template x-if="memoryPanel.tab === 'lessons'">
                <div>
                    <p x-show="!memoryPanel.loading && !memoryPanel.error && memoryPanel.lessons.length === 0"
                       class="text-sm text-gray-400 text-center py-12">
                        Все още няма поуки — те се дестилират от QA/ревизионни събития по време на изпълненията.
                    </p>
                    <template x-if="memoryPanel.lessons.length > 0">
                        <table class="w-full text-sm border-separate border-spacing-0">
                            <thead class="sticky top-0 bg-white z-10">
                                <tr class="text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-200">
                                    <th @click="memSort('node_key')"
                                        class="text-left py-2.5 pr-3 font-semibold cursor-pointer select-none hover:text-gray-700 w-32 whitespace-nowrap">
                                        Агент
                                        <span x-show="memoryPanel.sortCol === 'node_key'"
                                              x-text="memoryPanel.sortDir === 'asc' ? ' ↑' : ' ↓'"></span>
                                    </th>
                                    <th @click="memSort('created_at')"
                                        class="text-left py-2.5 pr-3 font-semibold cursor-pointer select-none hover:text-gray-700 w-32 whitespace-nowrap">
                                        Дата
                                        <span x-show="memoryPanel.sortCol === 'created_at'"
                                              x-text="memoryPanel.sortDir === 'asc' ? ' ↑' : ' ↓'"></span>
                                    </th>
                                    <th class="text-left py-2.5 font-semibold">Поука</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="row in memPagedRows()" :key="row.id">
                                    <tr class="hover:bg-indigo-50/40 transition-colors">
                                        <td class="py-2 pr-3 w-32">
                                            <div class="truncate text-xs font-medium text-indigo-700"
                                                 :title="row.node_key"
                                                 x-text="row.node_key"></div>
                                        </td>
                                        <td class="py-2 pr-3 text-xs text-gray-400 whitespace-nowrap"
                                            x-text="row.created_at"></td>
                                        <td class="py-2 text-xs text-gray-700 leading-relaxed"
                                            x-text="row.summary"></td>
                                    </tr>
                                </template>
                                <tr x-show="memFilteredRows().length === 0 && memoryPanel.search !== ''">
                                    <td colspan="3" class="py-8 text-center text-sm text-gray-400">
                                        Няма резултати за „<span x-text="memoryPanel.search"></span>"
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </div>
            </template>

        </div>

        {{-- Footer: pagination + clear --}}
        <div class="px-6 py-3 border-t border-gray-100 flex items-center justify-between gap-4 shrink-0">
            {{-- Pagination --}}
            <div class="flex items-center gap-2 text-xs text-gray-500 min-h-[26px]"
                 x-show="(memoryPanel.tab === 'outputs' ? memoryPanel.outputs : memoryPanel.lessons).length > 0">
                <span x-text="memPagingLabel()"></span>
                <div class="flex gap-1">
                    <button @click="memoryPanel.page = Math.max(1, memoryPanel.page - 1)"
                            :disabled="memoryPanel.page <= 1"
                            class="px-2 py-1 rounded border border-gray-200 disabled:opacity-30 hover:bg-gray-50 transition text-gray-600">‹</button>
                    <template x-for="p in Array.from({length: memTotalPages()}, (_, i) => i + 1).filter(p =>
                        p === 1 || p === memTotalPages() ||
                        Math.abs(p - memoryPanel.page) <= 1
                    )" :key="p">
                        <button @click="memoryPanel.page = p"
                                :class="memoryPanel.page === p ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-200 hover:bg-gray-50 text-gray-600'"
                                class="min-w-[28px] px-1.5 py-1 rounded border transition text-xs" x-text="p"></button>
                    </template>
                    <button @click="memoryPanel.page = Math.min(memTotalPages(), memoryPanel.page + 1)"
                            :disabled="memoryPanel.page >= memTotalPages()"
                            class="px-2 py-1 rounded border border-gray-200 disabled:opacity-30 hover:bg-gray-50 transition text-gray-600">›</button>
                </div>
            </div>
            <div x-show="(memoryPanel.tab === 'outputs' ? memoryPanel.outputs : memoryPanel.lessons).length === 0"
                 class="text-[11px] text-gray-400">
                Паметта е на ниво flow — всеки flow помни само собствените си изпълнения.
            </div>

            {{-- Clear --}}
            <button type="button" @click="clearMemory()" :disabled="memoryPanel.clearing"
                    class="text-xs px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition disabled:opacity-50 ml-auto">
                <span x-show="!memoryPanel.clearing">🗑 Изчисти паметта</span>
                <span x-show="memoryPanel.clearing" x-cloak>Изчистване…</span>
            </button>
        </div>

    </div>
</div>

{{-- Preview popup: пълното съдържание на един memory запис (z-60 над панела) --}}
<div x-show="memoryPanel.preview.open" x-cloak
     class="fixed inset-0 z-[60] flex items-center justify-center p-6"
     @keydown.escape.window="memoryPanel.preview.open = false">
    <div class="absolute inset-0 bg-black/50" @click="memoryPanel.preview.open = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col" @click.stop>
        <div class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold text-indigo-600 uppercase tracking-wide"
                   x-text="memoryPanel.preview.nodeName"></p>
                <p class="text-base font-bold text-gray-900 mt-0.5"
                   x-show="memoryPanel.preview.title"
                   x-text="'„' + memoryPanel.preview.title + '"'"></p>
            </div>
            <button @click="memoryPanel.preview.open = false" type="button"
                    class="text-gray-400 hover:text-gray-600 text-xl leading-none shrink-0 mt-0.5">✕</button>
        </div>
        <div class="overflow-y-auto px-6 py-5 flex-1">
            <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap"
               x-text="memoryPanel.preview.body"></p>
        </div>
    </div>
</div>
