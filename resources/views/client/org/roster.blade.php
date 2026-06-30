@extends('layouts.client')

@section('title', 'Екип')

@push('head')<style>[x-cloak]{display:none !important}</style>@endpush

@section('content')
<div x-data="roster({
        addAssistantUrl: '{{ route('client.org.roster.add-assistant') }}',
        addDepartmentUrl: '{{ route('client.org.roster.add-department') }}',
        updateDeptTpl: '{{ route('client.org.roster.update-department', ['director' => 'PID']) }}',
        removeMemberTpl: '{{ route('client.org.roster.remove-member', ['member' => 'MID']) }}',
        personaTpl: '{{ route('client.org.member.persona', ['member' => 'MID']) }}',
        personaUpdateTpl: '{{ route('client.org.personas.update', ['persona' => 'PERSONA']) }}',
        genAssistantUrl: '{{ route('client.org.design.generate-assistant') }}',
        genDepartmentUrl: '{{ route('client.org.design.generate-department') }}',
        addStatusTpl: '{{ route('client.org.design.add-status', ['token' => 'TOKEN']) }}',
        suggestUrl: '{{ route('client.org.personas.suggest-field') }}',
        csrf: '{{ csrf_token() }}',
        usedDomains: @js(collect($graph['directors'])->pluck('domain')->filter()->values()),
     })">
    <div class="flex items-center justify-between gap-3 mb-6">
        <h1 class="text-2xl font-semibold text-ink">Екип</h1>
        <a href="{{ route('client.org.skill-tree') }}" class="text-sm text-primary font-medium hover:text-primary-hover">Карта на уменията →</a>
    </div>

    @if (! $graph['version'])
        <x-empty-state title="Още нямаш екип" description="Управителят трябва да проектира организацията.">
            <x-button :href="route('client.org.design.review')">Проектирай екипа</x-button>
        </x-empty-state>
    @else
        {{-- Навигация по отдели (цветни чипове → скрол) + „Създай нов отдел". --}}
        @if (count($graph['directors']))
            <nav class="sticky top-16 z-30 -mx-6 px-6 py-3 mb-6 flex flex-wrap items-center gap-2 border-b border-line bg-surface/90 backdrop-blur" aria-label="Отдели">
                @foreach ($graph['directors'] as $dir)
                    @php $cc = $dir['member']['color'] ?? 'blue'; @endphp
                    <a href="#dept-{{ $dir['placement_id'] }}" x-on:click.prevent="scrollToDept({{ $dir['placement_id'] }})"
                       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 transition bg-char-{{ $cc }}-soft text-char-{{ $cc }}-strong ring-char-{{ $cc }}-soft hover:ring-char-{{ $cc }}">
                        <span class="h-2 w-2 rounded-full bg-char-{{ $cc }}"></span>{{ $dir['title'] }}
                    </a>
                @endforeach
                <button type="button" x-on:click="openNewDept()"
                        class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-line-strong px-3 py-1 text-xs font-medium text-muted hover:text-primary hover:border-primary/50 transition">
                    <x-icon name="plus" size="3" /> Създай нов отдел
                </button>
            </nav>
        @endif

        {{-- Запалване / чакащи решения (§F видимост) --}}
        @if (($graph['igniting'] ?? 0) > 0)
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-accent/30 bg-accent/5 px-4 py-3">
                <span class="h-2 w-2 rounded-full bg-accent animate-pulse"></span>
                <p class="text-sm text-muted">Планът се генерира — <span class="font-semibold text-ink tabular-nums">{{ $graph['igniting'] }}</span> {{ $graph['igniting'] === 1 ? 'задача се подготвя' : 'задачи се подготвят' }}. Предложенията ще се появят в Предложения.</p>
            </div>
        @elseif (($graph['decisions']['total'] ?? 0) > 0)
            <a href="{{ route('client.org.decisions') }}" class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-line bg-surface-subtle/40 px-4 py-3 hover:bg-surface-subtle/70 transition">
                <p class="text-sm text-muted"><span class="font-semibold text-ink tabular-nums">{{ $graph['decisions']['total'] }}</span> {{ $graph['decisions']['total'] === 1 ? 'предложение чака' : 'предложения чакат' }} одобрение</p>
                <span class="text-xs font-mono uppercase tracking-wider text-accent">Към Предложения →</span>
            </a>
        @endif

        <div class="space-y-6">
            {{-- Управител (hero) --}}
            @if ($graph['manager'])
                <section>
                    <h2 class="text-xs font-mono uppercase tracking-wider text-muted mb-2">Управител</h2>
                    <div class="relative group">
                        @include('client.org._team-lead-card', ['m' => $graph['manager'], 'role' => $graph['manager']['role']])
                        <div class="absolute top-3 right-3 z-10 pointer-events-none opacity-0 transition group-hover:opacity-100 group-hover:pointer-events-auto focus-within:opacity-100 focus-within:pointer-events-auto">
                            <button type="button" x-on:click="openPersonaEditor({{ $graph['manager']['id'] }}, @js($graph['manager']['role']))"
                                    class="p-1.5 rounded-md bg-surface ring-1 ring-line text-muted shadow-sm hover:text-primary transition" aria-label="Редактирай персона" title="Редактирай персона">
                                <x-icon name="pencil-square" size="4" />
                            </button>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Отдели: директор + неговите асистенти --}}
            @foreach ($graph['directors'] as $dir)
                @php
                    $assistants = collect($graph['assistants'])->where('director_id', $dir['placement_id']);
                    $count = $dir['stats']['assistants_count'];
                    $c = $dir['member']['color'] ?? 'blue';
                    $deptPayload = ['title' => $dir['title'], 'domain' => $dir['domain'], 'mandate' => $dir['mandate'], 'priorities' => $dir['priorities'], 'color' => $dir['color']];
                    $genDept = ['key' => $dir['member']['key'], 'title' => $dir['title'], 'domain' => $dir['domain'], 'mandate' => $dir['mandate'],
                        'existing' => $assistants->map(fn ($a) => ['key' => $a['member']['key'], 'title' => $a['title']])->values()];
                @endphp
                <section id="dept-{{ $dir['placement_id'] }}" class="scroll-mt-32 rounded-2xl border border-line bg-surface-subtle/40 overflow-hidden">
                    {{-- Лента на отдела --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3 border-b border-line bg-char-{{ $c }}-soft">
                        <span class="w-2.5 h-2.5 rounded-full shrink-0 bg-char-{{ $c }}"></span>
                        <span class="text-[11px] font-mono font-semibold uppercase tracking-wider text-char-{{ $c }}-strong">{{ $dir['domain'] }}</span>
                        <h2 class="text-base font-semibold text-ink">{{ $dir['title'] }}</h2>
                        <span class="text-xs text-muted">{{ $count }} {{ $count === 1 ? 'асистент' : 'асистенти' }}</span>
                        <div class="ml-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                            <span><span class="font-semibold text-ink tabular-nums">{{ $dir['stats']['flows_total'] }}</span> flows</span>
                            @if ($dir['stats']['active'] > 0)
                                <span class="inline-flex items-center gap-1 text-accent"><span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>{{ $dir['stats']['active'] }} активни</span>
                            @endif
                            <span class="text-line" aria-hidden="true">·</span>
                            <button type="button" class="inline-flex items-center gap-1 hover:text-primary transition"
                                    x-on:click="openDeptEditor({{ $dir['placement_id'] }}, {{ Illuminate\Support\Js::from($deptPayload) }})">
                                <x-icon name="pencil-square" size="3" /> Редактирай
                            </button>
                            @if (count($graph['directors']) > 1)
                                <button type="button" class="inline-flex items-center gap-1 hover:text-danger transition"
                                        x-on:click="removeDepartment({{ $dir['member']['id'] }}, @js($dir['title']))">
                                    <x-icon name="trash" size="3" /> Премахни
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Описание на отдела: с какво се занимава + приоритети --}}
                    @if (! empty($dir['mandate']) || ! empty($dir['priorities']))
                        <div class="px-5 py-3 border-b border-line bg-surface/40 space-y-2">
                            @if (! empty($dir['mandate']))
                                <p class="text-sm text-ink">{{ $dir['mandate'] }}</p>
                            @endif
                            @if (! empty($dir['priorities']))
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-[10px] font-mono uppercase tracking-wider text-subtle shrink-0 mr-1">Приоритети</span>
                                    @foreach ($dir['priorities'] as $p)
                                        <span class="inline-flex items-center rounded-full border border-line bg-surface px-2.5 py-1 text-xs text-muted">{{ $p }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="p-5 space-y-4">
                        {{-- Карта на директора (изпъкваща) + редакция на персона --}}
                        <div class="relative group">
                            @include('client.org._team-lead-card', ['m' => $dir['member'], 'role' => $dir['title']])
                            <div class="absolute top-3 right-3 z-10 pointer-events-none opacity-0 transition group-hover:opacity-100 group-hover:pointer-events-auto focus-within:opacity-100 focus-within:pointer-events-auto">
                                <button type="button" x-on:click="openPersonaEditor({{ $dir['member']['id'] }}, @js($dir['title']))"
                                        class="p-1.5 rounded-md bg-surface ring-1 ring-line text-muted shadow-sm hover:text-primary transition" aria-label="Редактирай персона" title="Редактирай персона">
                                    <x-icon name="pencil-square" size="4" />
                                </button>
                            </div>
                        </div>

                        {{-- Асистенти + редакция/премахване + добавяне --}}
                        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
                            @foreach ($assistants as $a)
                                <div class="relative group">
                                    @include('client.org._team-assistant-card', ['m' => $a['member'], 'stats' => $a['stats']])
                                    <div class="absolute top-2 right-2 z-10 flex items-center gap-0.5 pointer-events-none opacity-0 transition group-hover:opacity-100 group-hover:pointer-events-auto focus-within:opacity-100 focus-within:pointer-events-auto">
                                        <button type="button" x-on:click="openPersonaEditor({{ $a['member']['id'] }}, @js($a['title']))"
                                                class="p-1.5 rounded-md bg-surface ring-1 ring-line text-muted shadow-sm hover:text-primary transition" aria-label="Редактирай персона" title="Редактирай персона">
                                            <x-icon name="pencil-square" size="4" />
                                        </button>
                                        <button type="button" x-on:click="removeAssistant({{ $a['member']['id'] }}, @js($a['member']['name']))"
                                                class="p-1.5 rounded-md bg-surface ring-1 ring-line text-muted shadow-sm hover:text-danger transition" aria-label="Премахни" title="Премахни">
                                            <x-icon name="trash" size="4" />
                                        </button>
                                    </div>
                                </div>
                            @endforeach

                            {{-- + Добави асистент (Blade @foreach, не Alpine x-for → bind gotcha не важи) --}}
                            <button type="button" x-on:click="addAssistant({{ $dir['member']['id'] }}, {{ Illuminate\Support\Js::from($genDept) }})"
                                    class="rounded-xl border border-dashed border-line-strong p-4 min-h-[132px] flex items-center justify-center gap-2 text-sm text-muted hover:text-primary hover:border-primary/50 transition">
                                <span x-show="!busyAdd[{{ $dir['member']['id'] }}]" class="inline-flex items-center gap-2"><x-icon name="plus" size="4" /> Добави асистент</span>
                                <span x-show="busyAdd[{{ $dir['member']['id'] }}]" x-cloak class="inline-flex items-center gap-2"><x-org.bolt-spinner size="18" /> Управителят пише…</span>
                            </button>
                        </div>
                        <p x-show="addError[{{ $dir['member']['id'] }}]" x-text="addError[{{ $dir['member']['id'] }}]" x-cloak class="text-xs text-danger"></p>
                    </div>
                </section>
            @endforeach
        </div>

        {{-- ─────────────── Drawer-и (веднъж, в scope-а на roster()) ─────────────── --}}

        {{-- Редакция на персона (преизползва пълната персона-форма; запис през PUT personas/{id}) --}}
        <div x-show="personaOpen" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-ink/30" x-on:click="closePersona()"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
            <div class="absolute inset-y-0 right-0 w-full max-w-md bg-surface border-l border-line shadow-xl overflow-y-auto"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
                <div class="sticky top-0 z-10 bg-surface border-b border-line px-5 py-3 flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-ink">Редактирай члена</p>
                        <p class="text-xs text-muted truncate" x-text="editRole"></p>
                    </div>
                    <button type="button" x-on:click="closePersona()" class="p-1.5 rounded-md text-muted hover:text-ink hover:bg-surface-subtle transition" aria-label="Затвори"><x-icon name="x-mark" size="5" /></button>
                </div>
                <template x-if="personaOpen && !editPersona">
                    <div class="flex items-center gap-3 text-sm text-muted p-5"><x-org.bolt-spinner size="22" /> Зареждам персоната…</div>
                </template>
                <template x-if="personaOpen && editPersona">
                    <div class="p-5 space-y-4">
                        @include('client.org._persona-fields', ['modelPrefix' => 'editPersona'])
                        <div class="flex items-center justify-end gap-2 pt-2">
                            <p x-show="aiError" x-text="aiError" class="text-sm text-danger mr-auto"></p>
                            <x-button x-on:click="savePersona()" x-bind:disabled="savingPersona">
                                <span x-text="savingPersona ? 'Запазвам…' : 'Запази'"></span>
                            </x-button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Редакция на ОТДЕЛА (име/мандат/приоритети) — in-place, без нова версия --}}
        <div x-show="deptOpen" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-ink/30" x-on:click="closeDept()"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
            <div class="absolute inset-y-0 right-0 w-full max-w-md bg-surface border-l border-line shadow-xl overflow-y-auto"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
                <div class="sticky top-0 z-10 bg-surface border-b border-line px-5 py-3 flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-ink">Редактирай отдела</p>
                        <p class="text-xs text-muted truncate font-mono uppercase tracking-wider" x-text="editDept ? (editDept.domain || '') : ''"></p>
                    </div>
                    <button type="button" x-on:click="closeDept()" class="p-1.5 rounded-md text-muted hover:text-ink hover:bg-surface-subtle transition" aria-label="Затвори"><x-icon name="x-mark" size="5" /></button>
                </div>
                <template x-if="deptOpen && editDept">
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-ink mb-1">Име на отдела</label>
                            <x-input x-model="editDept.title" maxlength="160" placeholder="напр. Директор Финанси" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-ink mb-1">Домейн <span class="text-xs text-muted">(англ. етикет в лентата)</span></label>
                            <x-input x-model="editDept.domain" maxlength="120" placeholder="напр. operations" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-ink mb-1">С какво се занимава</label>
                            <x-textarea x-model="editDept.mandate" rows="3" maxlength="1000" placeholder="Опиши накратко с какво се занимава отделът." />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-ink mb-1">Приоритети <span class="text-xs text-muted">(какви приоритети има отделът)</span></label>
                            <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-line bg-surface px-2 py-2 transition focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30"
                                 x-on:click="$refs.prioInput && $refs.prioInput.focus()">
                                <template x-for="(p, pi) in (editDept.priorities || [])" :key="pi">
                                    <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-line bg-surface-subtle py-1 pl-2.5 pr-1 text-xs text-ink">
                                        <span class="truncate" x-text="p"></span>
                                        <button type="button" x-on:click.stop="removePriority(pi)"
                                                class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-subtle transition hover:bg-danger-soft/60 hover:text-danger"
                                                :aria-label="'Премахни приоритет: ' + p"><x-icon name="x-mark" size="3" /></button>
                                    </span>
                                </template>
                                <input x-ref="prioInput" type="text" x-model="prioDraft" aria-label="Добави приоритет" maxlength="120"
                                       x-on:keydown.enter.prevent="addPriority()"
                                       x-on:keydown="if ($event.key === ',') { $event.preventDefault(); addPriority(); }"
                                       x-on:keydown.backspace="if (prioDraft === '') removePriority((editDept.priorities || []).length - 1)"
                                       x-on:blur="addPriority()"
                                       class="min-w-[8rem] flex-1 border-0 bg-transparent p-0 text-sm text-ink placeholder:text-subtle focus:outline-none focus:ring-0"
                                       placeholder="Добави приоритет…">
                            </div>
                            <p class="mt-1 text-[11px] text-subtle">Натисни Enter или запетая за нов приоритет.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-ink mb-1">Цвят на отдела</label>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" x-on:click="editDept.color = null"
                                        class="inline-flex items-center rounded-full border px-3 py-1 text-xs transition"
                                        :class="!editDept.color ? 'border-primary text-primary bg-primary/5' : 'border-line text-muted hover:text-ink'"
                                        :aria-pressed="!editDept.color">
                                    Авто
                                </button>
                                @foreach (config('organization.department_colors') as $col => $colName)
                                    <button type="button" x-on:click="editDept.color = '{{ $col }}'"
                                            class="h-8 w-8 rounded-full bg-char-{{ $col }} transition hover:opacity-80"
                                            :class="editDept.color === '{{ $col }}' ? 'ring-2 ring-ink ring-offset-2 ring-offset-surface' : ''"
                                            :aria-pressed="editDept.color === '{{ $col }}'" aria-label="{{ $colName }}"></button>
                                @endforeach
                            </div>
                            <p class="mt-1 text-[11px] text-subtle">„Авто" използва цвят според домейна. Изборът се отразява на целия отдел.</p>
                        </div>
                        <div class="flex items-center justify-end gap-2 pt-2">
                            <p x-show="deptError" x-text="deptError" class="text-sm text-danger mr-auto"></p>
                            <x-button x-on:click="saveDept()" x-bind:disabled="savingDept">
                                <span x-text="savingDept ? 'Запазвам…' : 'Запази'"></span>
                            </x-button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Нов отдел (auto: Управителят решава · или ръчно: име + описание) --}}
        <div x-show="newDeptOpen" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-ink/30" x-on:click="newDeptOpen = false"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
            <div class="absolute inset-y-0 right-0 w-full max-w-md bg-surface border-l border-line shadow-xl overflow-y-auto"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
                <div class="sticky top-0 z-10 bg-surface border-b border-line px-5 py-3 flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-ink">Нов отдел</p>
                        <p class="text-xs text-muted">Въведи име и описание — или остави Управителят да реши.</p>
                    </div>
                    <button type="button" x-on:click="newDeptOpen = false" class="p-1.5 rounded-md text-muted hover:text-ink hover:bg-surface-subtle transition" aria-label="Затвори"><x-icon name="x-mark" size="5" /></button>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-ink mb-1">Име на отдела</label>
                        <x-input x-model="newDept.name" maxlength="160" placeholder="напр. Иновации и развитие" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink mb-1">С какво се занимава и какви приоритети има</label>
                        <x-textarea x-model="newDept.description" rows="3" maxlength="1000"
                                    placeholder="Опиши накратко ролята на отдела — системата ще създаде директор и асистенти." />
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <span x-show="busyDept" x-cloak class="inline-flex items-center gap-2 text-sm text-muted mr-auto"><x-org.bolt-spinner size="18" /> Управителят съставя нов отдел…</span>
                        <button type="button" x-on:click="addDepartment()" x-bind:disabled="busyDept"
                                class="rounded-lg border border-line-strong px-3 py-2 text-sm text-ink hover:bg-surface-subtle transition disabled:opacity-50">
                            Нека Управителят реши
                        </button>
                        <x-button x-on:click="addDepartment({ name: newDept.name, description: newDept.description })" x-bind:disabled="busyDept">Създай отдел</x-button>
                    </div>
                    <p x-show="newDeptError" x-text="newDeptError" x-cloak class="text-sm text-danger"></p>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
function roster(cfg) {
    return {
        // Споделеният персона-форма helper (aiFill/aiBusy) за панела за редакция.
        ...window.personaFormBase({ suggestUrl: cfg.suggestUrl, csrf: cfg.csrf, role: '' }),

        busyAdd: {}, busyDept: false, addError: {},
        personaOpen: false, editPersona: null, editRole: '', editPersonaId: null, savingPersona: false,
        deptOpen: false, editDept: null, editDeptPid: null, prioDraft: '', savingDept: false, deptError: '',
        newDeptOpen: false, newDept: { name: '', description: '' }, newDeptError: '',

        // personaFormBase override-и → сочат към персоната в редакция.
        aiRole() { return this.editRole; },
        aiContext() { return this.editPersona || {}; },
        aiApply(field, value) { if (this.editPersona) this.editPersona[field] = value; },

        // ── навигация ──
        scrollToDept(pid) {
            const el = document.getElementById('dept-' + pid);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        openNewDept() { this.newDeptError = ''; this.newDeptOpen = true; },

        // ── редакция на персона (запис веднага през PUT personas/{id}) ──
        async openPersonaEditor(memberId, role) {
            this.personaOpen = true; this.editRole = role; this.aiError = '';
            this.editPersona = null; this.editPersonaId = null;
            try {
                const d = await (await fetch(cfg.personaTpl.replace('MID', memberId), { headers: { Accept: 'application/json' } })).json();
                this.editPersonaId = d.persona.id;
                this.editPersona = this.normPersona(d.persona);
            } catch (e) { this.aiError = 'Грешка при зареждане на персоната.'; }
        },
        closePersona() { this.personaOpen = false; },
        normPersona(p) {
            const def = { risk: 50, creativity: 50, precision: 50, autonomy: 60, tempo: 55 };
            p.traits = Object.assign({}, def, p.traits || {});
            p.color = p.color || 'purple';
            return p;
        },
        savePersona() {
            if (!this.editPersonaId || this.savingPersona) return;
            this.savingPersona = true; this.aiError = '';
            const p = this.editPersona;
            fetch(cfg.personaUpdateTpl.replace('PERSONA', this.editPersonaId), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ name: p.name, age: p.age, gender: p.gender, ethnicity: p.ethnicity, background: p.background, tone: p.tone, bio: p.bio, traits: p.traits, skills: p.skills }),
            })
                .then(r => { if (r.ok) window.location.reload(); else { this.savingPersona = false; this.aiError = 'Грешка при запис.'; } })
                .catch(() => { this.savingPersona = false; this.aiError = 'Грешка при запис.'; });
        },

        // ── редакция на отдела (in-place) ──
        openDeptEditor(pid, dept) {
            this.editDeptPid = pid;
            this.editDept = { title: dept.title || '', domain: dept.domain || '', mandate: dept.mandate || '', priorities: Array.isArray(dept.priorities) ? [...dept.priorities] : [], color: dept.color ?? null };
            this.prioDraft = ''; this.deptError = ''; this.deptOpen = true;
        },
        closeDept() { this.addPriority(); this.deptOpen = false; },
        addPriority() {
            const v = (this.prioDraft || '').trim(); this.prioDraft = '';
            if (!v || !this.editDept) return;
            this.editDept.priorities = this.editDept.priorities || [];
            if (!this.editDept.priorities.includes(v)) this.editDept.priorities.push(v);
        },
        removePriority(i) {
            const l = this.editDept && this.editDept.priorities;
            if (!Array.isArray(l) || i < 0 || i >= l.length) return;
            l.splice(i, 1);
        },
        saveDept() {
            this.addPriority();
            if (this.savingDept || !this.editDeptPid) return;
            this.savingDept = true; this.deptError = '';
            fetch(cfg.updateDeptTpl.replace('PID', this.editDeptPid), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ title: this.editDept.title, domain: this.editDept.domain, mandate: this.editDept.mandate, priorities: this.editDept.priorities, color: this.editDept.color ?? null }),
            })
                .then(r => { if (r.ok) window.location.reload(); else { this.savingDept = false; this.deptError = 'Грешка при запис.'; } })
                .catch(() => { this.savingDept = false; this.deptError = 'Грешка при запис.'; });
        },

        // ── добави асистент (генериране → прилагане) ──
        setAddErr(k, m) { this.addError = { ...this.addError, [k]: m }; },
        fetchErrorMessage(d, fallback) {
            if (d.errors) {
                const first = Object.values(d.errors).flat()[0];
                if (first) return first;
            }
            return d.message || d.error || fallback;
        },
        addAssistant(dirMemberId, dept) {
            if (this.busyAdd[dirMemberId]) return;
            this.busyAdd = { ...this.busyAdd, [dirMemberId]: true };
            this.setAddErr(dirMemberId, '');
            const done = () => { this.busyAdd = { ...this.busyAdd, [dirMemberId]: false }; };
            fetch(cfg.genAssistantUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ department: { key: dept.key, title: dept.title, domain: dept.domain, mandate: dept.mandate }, existing: dept.existing || [] }),
            })
                .then(async r => {
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) throw new Error(this.fetchErrorMessage(d, 'Грешка при стартиране.'));
                    if (!d.token) throw new Error(d.error || 'Грешка');
                    return d;
                })
                .then(d => {
                    this.pollAddition(d.token,
                        (res) => { if (res.assistant) this.applyAssistant(dirMemberId, res.assistant); else done(); },
                        (err) => { this.setAddErr(dirMemberId, err); done(); });
                })
                .catch(e => { this.setAddErr(dirMemberId, e.message || 'Не успях да стартирам генерирането.'); done(); });
        },
        applyAssistant(dirMemberId, assistant) {
            fetch(cfg.addAssistantUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ director_member_id: dirMemberId, assistant }),
            })
                .then(r => r.json())
                .then(d => { if (d.ok) window.location.reload(); else { this.setAddErr(dirMemberId, d.error || 'Грешка.'); this.busyAdd = { ...this.busyAdd, [dirMemberId]: false }; } })
                .catch(() => { this.setAddErr(dirMemberId, 'Грешка при добавяне.'); this.busyAdd = { ...this.busyAdd, [dirMemberId]: false }; });
        },

        // ── създай отдел (генериране → прилагане) ──
        addDepartment(custom = null) {
            if (this.busyDept) return;
            this.busyDept = true; this.newDeptError = '';
            fetch(cfg.genDepartmentUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ existing_domains: cfg.usedDomains || [], name: (custom && custom.name) || '', description: (custom && custom.description) || '' }),
            })
                .then(async r => {
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) throw new Error(this.fetchErrorMessage(d, 'Грешка при стартиране.'));
                    if (!d.token) throw new Error(d.error || 'Грешка');
                    return d;
                })
                .then(d => {
                    this.pollAddition(d.token,
                        (res) => { if (res.department && res.department.director) this.applyDepartment(res.department); else { this.newDeptError = 'Празен резултат.'; this.busyDept = false; } },
                        (err) => { this.newDeptError = err; this.busyDept = false; });
                })
                .catch(e => { this.newDeptError = e.message || 'Не успях да стартирам генерирането.'; this.busyDept = false; });
        },
        applyDepartment(department) {
            fetch(cfg.addDepartmentUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ department }),
            })
                .then(r => r.json())
                .then(d => { if (d.ok) window.location.reload(); else { this.newDeptError = d.error || 'Грешка.'; this.busyDept = false; } })
                .catch(() => { this.newDeptError = 'Грешка при създаване.'; this.busyDept = false; });
        },

        // ── премахване ──
        removeAssistant(memberId, name) {
            if (!confirm('Премахни „' + name + '" от екипа?')) return;
            this.del(memberId);
        },
        removeDepartment(memberId, title) {
            if (!confirm('Премахни целия отдел „' + title + '" заедно с асистентите му? Те ще бъдат архивирани.')) return;
            this.del(memberId);
        },
        del(memberId) {
            fetch(cfg.removeMemberTpl.replace('MID', memberId), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
            })
                .then(r => r.json())
                .then(d => { if (d.ok) window.location.reload(); else alert(d.error || 'Грешка.'); })
                .catch(() => alert('Грешка при премахване.'));
        },

        // Поллва фоновата генерация (org_add_{token}); 2с, до ~60 опита (2 мин).
        pollAddition(token, onDone, onFail) {
            const url = cfg.addStatusTpl.replace('TOKEN', token);
            let tries = 0;
            const timer = setInterval(async () => {
                tries++;
                try {
                    const d = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
                    if (d.status === 'completed') { clearInterval(timer); onDone(d); }
                    else if (d.status === 'failed' || d.status === 'expired') { clearInterval(timer); onFail(d.error || 'Грешка.'); }
                    else if (tries >= 60) { clearInterval(timer); onFail('Отне твърде дълго, опитай пак.'); }
                } catch (e) { if (tries >= 60) { clearInterval(timer); onFail('Услугата не отговори.'); } }
            }, 2000);
        },
    };
}
</script>
@endpush
