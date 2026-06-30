@extends('layouts.client')

@section('title', 'Карта на уменията')

@push('head')<style>[x-cloak]{display:none !important}</style>@endpush

@section('content')
<div>
    <div class="flex items-start justify-between gap-3 mb-2">
        <h1 class="text-2xl font-semibold text-ink">Карта на уменията</h1>
        <a href="{{ route('client.org.roster') }}" class="text-sm text-primary font-medium hover:text-primary-hover whitespace-nowrap">← Към екипа</a>
    </div>

    @if (! $graph['version'])
        <x-empty-state title="Още няма карта" description="Управителят трябва да проектира организацията.">
            <x-button :href="route('client.org.design.review')">Проектирай екипа</x-button>
        </x-empty-state>
    @else
        @php
            $sm = $lens['summary'];
            $allMemberVis = collect($lens['by_department'])->flatMap(fn ($d) => array_map(fn ($n) => $n['vis'], $d['members']))->values()->all();
            $allSkillVis = array_map(fn ($s) => $s['vis'], $lens['skills']);
        @endphp

        <p class="text-sm text-muted mb-5 max-w-2xl">Какви умения има екипът · кой може да направи нещо · къде е концентрирана компетентност. За хората и управлението им → <a href="{{ route('client.org.roster') }}" class="text-primary hover:text-primary-hover">Екип</a>.</p>

        <div x-data="skillMap({ members: @js($allMemberVis), skills: @js($allSkillVis) })">

            @if ($sm['distinct_skills'] === 0)
                <div class="mb-5 flex items-start gap-3 rounded-xl border border-line bg-surface-subtle/60 px-4 py-3">
                    <x-icon name="sparkles" size="5" class="text-subtle shrink-0 mt-0.5" />
                    <p class="text-sm text-muted">Уменията още не са зададени за този състав. Добави ги в <a href="{{ route('client.org.roster') }}" class="text-primary hover:text-primary-hover">профила</a> на всеки служител, за да се запълни картата — структурата на екипа е по-долу.</p>
                </div>
            @endif

            {{-- (c) Обзорна лента за способности --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-xs text-muted">Умения</p>
                    <p class="mt-0.5 text-2xl font-semibold text-ink tabular-nums">{{ $sm['distinct_skills'] }}</p>
                </div>
                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-xs text-muted">Със зададени умения</p>
                    <p class="mt-0.5 text-2xl font-semibold text-ink tabular-nums">{{ $sm['members_with_skills'] }}<span class="text-base font-normal text-subtle"> / {{ $sm['members_total'] }}</span></p>
                </div>
                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-xs text-muted">Покритие</p>
                    <p class="mt-0.5 text-2xl font-semibold text-ink tabular-nums">{{ $sm['coverage_pct'] }}%</p>
                </div>
                @if ($sm['single_point_count'] > 0)
                    <button type="button" x-on:click="lens = 'skill'"
                            class="text-left rounded-xl border border-warning-soft bg-warning-soft p-4 transition hover:border-warning focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                            title="Виж кои умения имат единствен носител">
                        <p class="text-xs text-warning-strong inline-flex items-center gap-1"><x-icon name="exclamation-triangle" size="4" /> Единствен носител</p>
                        <p class="mt-0.5 text-2xl font-semibold text-warning-strong tabular-nums">{{ $sm['single_point_count'] }}</p>
                    </button>
                @else
                    <div class="rounded-xl border border-line bg-surface p-4">
                        <p class="text-xs text-muted inline-flex items-center gap-1"><x-icon name="shield-check" size="4" /> Единствен носител</p>
                        <p class="mt-0.5 text-2xl font-semibold text-success-strong tabular-nums">0</p>
                    </div>
                @endif
            </div>

            {{-- Топ умения (кликаеми филтри) --}}
            @if (! empty($sm['top_skills']))
                <div class="flex flex-wrap items-center gap-2 mb-5">
                    <span class="text-[10px] font-mono uppercase tracking-wider text-subtle shrink-0">Топ умения</span>
                    @foreach ($sm['top_skills'] as $ts)
                        @include('client.org._skill-chip', ['skill' => $ts['name'], 'color' => $ts['color'], 'count' => $ts['count'], 'slug' => $ts['slug'], 'filter' => true])
                    @endforeach
                </div>
            @endif

            {{-- (d) Контроли: леща · търсене · мин. ниво · изчисти --}}
            <div class="flex flex-wrap items-center gap-3 mb-6">
                {{-- Превключвател на леща (ръчно-навит, вързан към skillMap().lens) --}}
                <div class="inline-flex rounded-lg border border-line bg-surface-subtle p-0.5" role="radiogroup" aria-label="Изглед">
                    <button type="button" role="radio" :aria-checked="lens === 'dept'" x-on:click="lens = 'dept'"
                            class="px-3 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                            :class="lens === 'dept' ? 'bg-surface text-ink shadow-card' : 'text-muted hover:text-ink'">По отдели</button>
                    <button type="button" role="radio" :aria-checked="lens === 'skill'" x-on:click="lens = 'skill'"
                            class="px-3 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                            :class="lens === 'skill' ? 'bg-surface text-ink shadow-card' : 'text-muted hover:text-ink'">По умения</button>
                </div>

                {{-- Търсене --}}
                <div class="flex-1 min-w-[200px] flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 transition focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30">
                    <x-icon name="magnifying-glass" size="4" class="text-subtle shrink-0" />
                    <input type="text" x-model.debounce.200ms="query" aria-label="Търси по умение, име или роля"
                           placeholder="Търси по умение, име или роля…"
                           class="w-full border-0 bg-transparent p-0 text-base sm:text-sm text-ink placeholder:text-subtle focus:outline-none focus:ring-0">
                    <button type="button" x-show="query" x-cloak x-on:click="query = ''" aria-label="Изчисти търсенето" class="shrink-0 text-subtle hover:text-ink transition"><x-icon name="x-mark" size="4" /></button>
                </div>

                {{-- Минимално ниво (★) — server-rendered, без x-for --}}
                <div class="inline-flex items-center gap-2">
                    <span class="text-xs text-muted shrink-0">Мин. ниво</span>
                    <div class="inline-flex items-center gap-0.5" role="group" aria-label="Минимално ниво">
                        @for ($n = 1; $n <= 5; $n++)
                            <button type="button" x-on:click="minStars = (minStars === {{ $n }} ? 0 : {{ $n }})"
                                    class="text-base leading-none rounded px-0.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                    :class="{{ $n }} <= minStars ? 'text-star' : 'text-subtle hover:text-muted'"
                                    aria-label="минимум {{ $n }} {{ $n === 1 ? 'звезда' : 'звезди' }}"
                                    :aria-pressed="{{ $n }} <= minStars">★</button>
                        @endfor
                    </div>
                </div>

                <button type="button" x-show="hasFilters" x-cloak x-on:click="clearFilters()"
                        class="text-xs text-muted hover:text-ink underline shrink-0">Изчисти филтрите</button>
            </div>

            {{-- ════════════ Леща A — По отдели ════════════ --}}
            <div x-show="lens === 'dept'">
                <div class="space-y-5">
                    @foreach ($lens['by_department'] as $dept)
                        @php
                            $dc = $dept['color'] ?? 'blue';
                            $pid = $dept['placement_id'];
                            $deptVis = array_map(fn ($n) => $n['vis'], $dept['members']);
                        @endphp
                        <section x-show="!hasFilters || deptHasVisible(@js($deptVis))"
                                 class="rounded-2xl border border-line bg-surface-subtle/40 overflow-hidden">
                            {{-- Лента на отдела --}}
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3 bg-char-{{ $dc }}-soft">
                                <button type="button" x-on:click="toggleDept({{ $pid }})"
                                        class="shrink-0 text-char-{{ $dc }}-strong rounded transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                        :class="collapsed[{{ $pid }}] ? '-rotate-90' : ''"
                                        :aria-expanded="!collapsed[{{ $pid }}]" aria-label="Сгъни или разгъни отдела">
                                    <x-icon name="chevron-down" size="4" />
                                </button>
                                <span class="h-2.5 w-2.5 rounded-full shrink-0 bg-char-{{ $dc }}"></span>
                                <span class="text-[11px] font-mono font-semibold uppercase tracking-wider text-char-{{ $dc }}-strong">{{ $dept['domain'] }}</span>
                                <span class="text-base font-semibold text-ink">{{ $dept['title'] }}</span>
                                <x-stars :count="$dept['director_stars']" class="ml-auto" />
                            </div>

                            <div x-show="!collapsed[{{ $pid }}]">
                                {{-- Умения на отдела (хистограма, кликаеми) --}}
                                @if (! empty($dept['skill_tags']))
                                    <div class="flex flex-wrap items-center gap-1.5 px-5 py-3 border-b border-line bg-surface/40">
                                        <span class="text-[10px] font-mono uppercase tracking-wider text-subtle shrink-0 mr-1">Умения</span>
                                        @foreach ($dept['skill_tags'] as $tag)
                                            @include('client.org._skill-chip', ['skill' => $tag['name'], 'color' => $tag['color'], 'count' => $tag['count'], 'slug' => $tag['slug'], 'filter' => true])
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Възли: служители + техните умения --}}
                                <div class="p-5 grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    @forelse ($dept['members'] as $node)
                                        @include('client.org._skill-member-node', ['m' => $node['member'], 'title' => $node['title'], 'stats' => $node['stats'], 'vis' => $node['vis']])
                                    @empty
                                        <p class="text-sm text-subtle">Няма служители в този отдел.</p>
                                    @endforelse
                                </div>
                            </div>
                        </section>
                    @endforeach
                </div>
                <p x-show="!anyMember" x-cloak class="text-sm text-subtle py-8 text-center">Няма съвпадащи служители. <button type="button" x-on:click="clearFilters()" class="text-primary hover:text-primary-hover underline">Изчисти филтрите</button></p>
            </div>

            {{-- ════════════ Леща B — По умения (умение → хора) ════════════ --}}
            <div x-show="lens === 'skill'" x-cloak>
                <div class="space-y-3">
                    @foreach ($lens['skills'] as $sk)
                        @php
                            $skc = $sk['members'][0]['color'] ?? 'blue';
                        @endphp
                        <section x-show="skillGroupVisible(@js($sk['vis']))" x-transition.opacity.duration.150ms
                                 class="rounded-xl border bg-surface p-4 {{ $sk['single_point'] ? 'border-warning-soft' : 'border-line' }}">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 mb-3">
                                @include('client.org._skill-chip', ['skill' => $sk['name'], 'color' => $skc, 'slug' => $sk['slug'], 'filter' => true])
                                <span class="text-xs text-muted tabular-nums">{{ $sk['count'] }} {{ $sk['count'] === 1 ? 'служител' : 'служители' }}</span>
                                @if (! empty($sk['departments']))
                                    <span class="text-xs text-subtle truncate">{{ implode(' · ', $sk['departments']) }}</span>
                                @endif
                                @if ($sk['single_point'])
                                    <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning-strong">
                                        <x-icon name="exclamation-triangle" size="3" /> Единствен носител
                                    </span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($sk['members'] as $mem)
                                    <a href="{{ route('client.org.member', $mem['id']) }}"
                                       class="inline-flex items-center gap-2 rounded-full border border-line bg-surface py-1 pl-1 pr-3 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                        @include('client.org._member-avatar', ['m' => $mem, 'size' => 'sm', 'ring' => false])
                                        <span class="text-sm text-ink">{{ $mem['name'] }}</span>
                                        <x-stars :count="$mem['stars']" />
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
                <p x-show="!anySkill" x-cloak class="text-sm text-subtle py-8 text-center">Няма съвпадащи умения. <button type="button" x-on:click="clearFilters()" class="text-primary hover:text-primary-hover underline">Изчисти филтрите</button></p>
            </div>

            {{-- Легенда --}}
            <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-subtle border-t border-line pt-4">
                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-char-blue"></span> цвят = функция</span>
                <span class="inline-flex items-center gap-1"><span class="text-star">★</span> = ниво (модел/цена)</span>
                <span class="inline-flex items-center gap-1"><x-icon name="exclamation-triangle" size="3" class="text-warning-strong" /> единствен носител на умение</span>
                <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-accent"></span> текущо изпълнение</span>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
function skillMap(cfg) {
    cfg = cfg || {};
    return {
        lens: 'dept',
        query: '',
        minStars: 0,
        skill: '',
        collapsed: {},
        members: cfg.members || [],
        skills: cfg.skills || [],

        // ── филтър-предикати (викат се от x-show върху server-rendered възли) ──
        memberVisible(m) {
            if (this.minStars && (m.stars || 0) < this.minStars) return false;
            if (this.skill && !(m.skills || []).includes(this.skill)) return false;
            const q = (this.query || '').trim().toLowerCase();
            if (q && !((m.search || '').includes(q))) return false;
            return true;
        },
        skillGroupVisible(g) {
            if (this.skill && g.slug !== this.skill) return false;
            if (this.minStars && (g.max_stars || 0) < this.minStars) return false;
            const q = (this.query || '').trim().toLowerCase();
            if (q && !((g.search || '').includes(q))) return false;
            return true;
        },
        deptHasVisible(list) { return (list || []).some(m => this.memberVisible(m)); },

        get anyMember() { return this.members.some(m => this.memberVisible(m)); },
        get anySkill() { return this.skills.some(g => this.skillGroupVisible(g)); },
        get hasFilters() { return !!(this.query || this.minStars || this.skill); },

        toggleSkill(slug) { this.skill = (this.skill === slug) ? '' : slug; },
        toggleDept(id) { this.collapsed = { ...this.collapsed, [id]: !this.collapsed[id] }; },
        clearFilters() { this.query = ''; this.minStars = 0; this.skill = ''; },
    };
}
</script>
@endpush
