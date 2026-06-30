@extends('layouts.client')

@section('title', 'Хроника')

@section('content')
<div x-data="chronicleFeed(@js([
    'cursor' => $page['next_cursor'],
    'lastDay' => $page['last_day'],
    'hasMore' => (bool) $page['next_cursor'],
    'empty' => $page['items'] === [],
]))">
    {{-- Хедър + навигация по време --}}
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Хроника на организацията</h1>
            <p class="mt-1 text-muted">Всяко събитие — кой, какво, върху какво и откъде.</p>
        </div>
        <div class="inline-flex overflow-hidden rounded-md border border-line text-sm">
            @foreach (['today' => 'Днес', '7d' => '7 дни', '30d' => '30 дни'] as $key => $label)
                <button type="button" @click="setPeriod('{{ $key }}')"
                        :class="filters.period === '{{ $key }}' ? 'bg-info-soft text-primary font-medium' : 'text-muted hover:text-ink'"
                        class="px-3 py-1.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Control-room: KPI + хистограма (пълна ширина, сменя се при смяна на период) --}}
    <div x-ref="stats" class="mb-5">
        @include('client.org._chronicle-stats', ['stats' => $stats])
    </div>

    {{-- Филтри --}}
    <div class="mb-5 flex flex-wrap items-center gap-2">
        <button type="button" @click="filters.groups = []; reload()"
                :class="filters.groups.length === 0 ? 'bg-info-soft text-primary border-primary/30' : 'border-line text-muted hover:text-ink'"
                class="rounded-full border bg-surface px-3 py-1.5 text-xs font-medium transition">Всички</button>
        @foreach (\App\Support\ChronicleType::GROUPS as $key => $label)
            <button type="button" @click="toggleGroup('{{ $key }}')"
                    :class="filters.groups.includes('{{ $key }}') ? 'bg-info-soft text-primary border-primary/30' : 'border-line text-muted hover:text-ink'"
                    class="rounded-full border bg-surface px-3 py-1.5 text-xs font-medium transition">{{ $label }}</button>
        @endforeach

        <div class="relative ms-auto min-w-[12rem] flex-1 sm:max-w-xs">
            <span class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-subtle"><x-icon name="magnifying-glass" size="4" /></span>
            <input type="search" x-model="filters.q" @input.debounce.400ms="reload()" placeholder="Търси в събитията"
                   class="h-9 w-full rounded-md border border-line bg-surface ps-9 pe-3 text-sm text-ink focus:border-primary focus:outline-none">
        </div>
        @if ($members->isNotEmpty())
            <select x-model="filters.member" @change="reload()"
                    class="h-9 rounded-md border border-line bg-surface px-2 text-sm text-ink focus:border-primary focus:outline-none">
                <option value="">Всеки актьор</option>
                @foreach ($members as $m)
                    <option value="{{ $m['id'] }}">{{ $m['name'] }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- Two-pane: ляво поток (пълна ширина), дясно sticky панел (детайл / разбивка) --}}
    <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start lg:gap-6">
        <div>
            <div class="overflow-hidden rounded-xl border border-line bg-surface">
                <div x-ref="feed">
                    @include('client.org._chronicle-feed', ['items' => $page['items'], 'afterDay' => null])
                </div>
            </div>

            <div x-show="empty" x-cloak>
                <x-empty-state icon="clock" title="Празна хроника"
                               description="Тук се появява всичко, което организацията върши. Промени филтрите или изчакай първите събития." />
            </div>

            <div class="mt-5 flex justify-center" x-show="hasMore" x-cloak>
                <x-button variant="secondary" size="sm" x-on:click="loadMore()" x-bind:disabled="loading">
                    <span x-show="! loading">Зареди още</span>
                    <span x-show="loading" x-cloak>Зарежда…</span>
                </x-button>
            </div>
        </div>

        <aside class="mt-6 hidden lg:mt-0 lg:block">
            <div class="sticky top-20 space-y-2">
                <div x-show="selectedKey" x-cloak>
                    <button type="button" @click="clearSelection()"
                            class="mb-2 inline-flex items-center gap-1 text-xs text-muted transition hover:text-ink">
                        <x-icon name="arrow-left" size="3.5" /> Обобщение
                    </button>
                    <div class="rounded-xl border border-line bg-surface p-4" x-html="panelHtml"></div>
                </div>
                <div x-show="! selectedKey" x-ref="breakdown">
                    @include('client.org._chronicle-breakdown', ['breakdown' => $breakdown])
                </div>
            </div>
        </aside>
    </div>
</div>

@push('scripts')
<script>
function chronicleFeed(initial) {
    return {
        filters: { period: '30d', groups: [], member: '', q: '' },
        cursor: initial.cursor,
        lastDay: initial.lastDay,
        hasMore: initial.hasMore,
        empty: initial.empty,
        loading: false,
        selectedKey: null,
        panelHtml: '',
        url: @js(route('client.org.chronicle.feed')),

        params() {
            const p = new URLSearchParams();
            p.set('period', this.filters.period);
            this.filters.groups.forEach(g => p.append('groups[]', g));
            if (this.filters.member) p.set('member', this.filters.member);
            if (this.filters.q) p.set('q', this.filters.q);
            return p;
        },

        setPeriod(period) { this.filters.period = period; this.reload(); },
        toggleGroup(g) {
            const i = this.filters.groups.indexOf(g);
            if (i === -1) this.filters.groups.push(g); else this.filters.groups.splice(i, 1);
            this.reload();
        },

        select(e) {
            const row = e.currentTarget;
            const key = row.dataset.key;
            if (this.selectedKey === key) { this.clearSelection(); return; }
            this.selectedKey = key;
            const tpl = row.querySelector('template[data-detail]');
            this.panelHtml = tpl ? tpl.innerHTML : '';
        },
        clearSelection() { this.selectedKey = null; this.panelHtml = ''; },

        async reload() {
            this.loading = true;
            try {
                const r = await fetch(`${this.url}?${this.params().toString()}`, { headers: { 'Accept': 'application/json' } });
                if (r.ok) {
                    const d = await r.json();
                    if (d.stats_html !== undefined) this.$refs.stats.innerHTML = d.stats_html;
                    if (d.breakdown_html !== undefined) this.$refs.breakdown.innerHTML = d.breakdown_html;
                    this.$refs.feed.innerHTML = d.feed_html;
                    this.cursor = d.next_cursor;
                    this.lastDay = d.last_day;
                    this.hasMore = !! d.next_cursor;
                    this.empty = !! d.empty;
                    this.clearSelection();
                }
            } catch (e) { /* тих inline провал */ }
            this.loading = false;
        },

        async loadMore() {
            if (! this.cursor || this.loading) return;
            this.loading = true;
            const p = this.params();
            p.set('cursor', this.cursor);
            if (this.lastDay) p.set('after_day', this.lastDay);
            try {
                const r = await fetch(`${this.url}?${p.toString()}`, { headers: { 'Accept': 'application/json' } });
                if (r.ok) {
                    const d = await r.json();
                    this.$refs.feed.insertAdjacentHTML('beforeend', d.feed_html);
                    this.cursor = d.next_cursor;
                    this.lastDay = d.last_day || this.lastDay;
                    this.hasMore = !! d.next_cursor;
                }
            } catch (e) { /* тих inline провал */ }
            this.loading = false;
        },
    };
}
</script>
@endpush
@endsection
