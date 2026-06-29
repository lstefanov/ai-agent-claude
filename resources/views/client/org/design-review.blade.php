@extends('layouts.client')

@section('title', 'Дизайн на екипа')

@push('head')<style>[x-cloak]{display:none !important}</style>@endpush

@section('content')
<div class="max-w-6xl mx-auto px-6 py-8"
     x-data="design({
        proposeUrl: '{{ route('client.org.design.propose') }}',
        statusTpl: '{{ route('client.org.design.status', ['token' => 'TOKEN']) }}',
        approveUrl: '{{ route('client.org.design.approve') }}',
        genAssistantUrl: '{{ route('client.org.design.generate-assistant') }}',
        genDepartmentUrl: '{{ route('client.org.design.generate-department') }}',
        addStatusTpl: '{{ route('client.org.design.add-status', ['token' => 'TOKEN']) }}',
        suggestUrl: '{{ route('client.org.personas.suggest-field') }}',
        csrf: '{{ csrf_token() }}',
     })">
    <header class="mb-6">
        <h1 class="text-2xl font-semibold text-ink">Управителят предлага екип</h1>
        <p class="text-muted mt-1">Прегледай отделите, директорите и асистентите. Клик върху „Редактирай“ отваря панел.
            Можеш да добавяш и махаш асистенти и цели отдели, преди да одобриш.</p>
    </header>

    {{-- Зареждане --}}
    <template x-if="loading">
        <div class="flex items-center gap-3 text-sm text-muted py-12">
            <x-org.bolt-spinner size="26" />
            <span x-text="stage || 'Управителят композира екипа…'"></span>
        </div>
    </template>
    <p x-show="error" x-text="error" class="text-sm text-danger py-6"></p>

    {{-- Предложение --}}
    <div x-show="!loading && design" x-cloak class="space-y-6">
        {{-- §3-part understanding: какво разбра Управителят --}}
        <template x-if="design && ((design.problems && design.problems.length) || (design.needs && design.needs.length) || (design.opportunities && design.opportunities.length))">
            <div class="grid md:grid-cols-3 gap-3">
                <template x-if="design.problems && design.problems.length">
                    <div class="rounded-xl border border-danger-soft bg-danger-soft/20 p-4">
                        <p class="text-sm font-semibold text-danger-strong mb-2">Проблеми за решаване</p>
                        <ul class="space-y-1">
                            <template x-for="(p, i) in design.problems" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(p)"></span></li></template>
                        </ul>
                    </div>
                </template>
                <template x-if="design.needs && design.needs.length">
                    <div class="rounded-xl border border-info-soft bg-info-soft/20 p-4">
                        <p class="text-sm font-semibold text-info-strong mb-2">Нужди на бизнеса</p>
                        <ul class="space-y-1">
                            <template x-for="(n, i) in design.needs" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(n)"></span></li></template>
                        </ul>
                    </div>
                </template>
                <template x-if="design.opportunities && design.opportunities.length">
                    <div class="rounded-xl border border-success-soft bg-success-soft/20 p-4">
                        <p class="text-sm font-semibold text-success-strong mb-2">Предложени възможности за растеж</p>
                        <ul class="space-y-1">
                            <template x-for="(o, i) in design.opportunities" :key="i"><li class="text-sm text-ink">• <span x-html="$mdInline(o)"></span></li></template>
                        </ul>
                    </div>
                </template>
            </div>
        </template>

        {{-- Приоритети от болките --}}
        <template x-if="design && design.priorities && design.priorities.length">
            <div class="rounded-xl border border-warning-soft bg-warning-soft/30 p-4">
                <p class="text-sm font-semibold text-warning-strong mb-2">Препоръчани приоритети</p>
                <ul class="space-y-1">
                    <template x-for="(q, i) in design.priorities" :key="i">
                        <li class="text-sm text-ink">• <span x-html="$mdInline(q.title)"></span>
                            <span class="text-xs text-muted" x-show="q.rationale" x-html="'— ' + $mdInline(q.rationale)"></span></li>
                    </template>
                </ul>
            </div>
        </template>

        {{-- Отдели (директор + асистенти, винаги видими; редакция в панел) --}}
        <template x-for="(dir, di) in (design ? design.directors : [])" :key="dir.key">
            <section class="rounded-2xl border border-line bg-surface-subtle/40 overflow-hidden">
                {{-- Лента на отдела --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3 border-b border-line"
                     :style="'background: var(--color-char-' + (dir.persona.color || 'blue') + '-soft)'">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="'background: var(--color-char-' + (dir.persona.color || 'blue') + ')'"></span>
                    <span class="text-[11px] font-mono font-semibold uppercase tracking-wider"
                          :style="'color: var(--color-char-' + (dir.persona.color || 'blue') + '-strong)'" x-text="dir.domain"></span>
                    <h2 class="text-base font-semibold text-ink" x-text="dir.title"></h2>
                    <span class="text-xs text-muted" x-text="assistantsFor(dir.key).length + ' асистенти'"></span>
                    <div class="ml-auto flex items-center gap-3">
                        <button type="button" class="inline-flex items-center gap-1 text-xs text-muted hover:text-primary transition"
                                x-on:click="openDeptEditor(dir)">
                            <x-icon name="pencil-square" size="3" /> Редактирай отдела
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 text-xs text-muted hover:text-danger transition"
                                x-on:click="removeDirector(dir.key)">
                            <x-icon name="trash" size="3" /> Премахни отдела
                        </button>
                    </div>
                </div>

                {{-- Описание на отдела: с какво се занимава + какви приоритети има --}}
                <div class="px-5 py-3 border-b border-line bg-surface/40 space-y-2" x-cloak
                     x-show="(dir.mandate && dir.mandate.length) || (dir.priorities && dir.priorities.length)">
                    <p class="text-sm text-ink" x-show="dir.mandate" x-text="dir.mandate"></p>
                    <div class="flex flex-wrap items-center gap-1.5" x-show="dir.priorities && dir.priorities.length">
                        <span class="text-[10px] font-mono uppercase tracking-wider text-subtle shrink-0 mr-1">Приоритети</span>
                        <template x-for="(p, pi) in (dir.priorities || [])" :key="pi">
                            <span class="inline-flex items-center rounded-full border border-line bg-surface px-2.5 py-1 text-xs text-muted" x-text="p"></span>
                        </template>
                    </div>
                </div>

                <div class="p-5 space-y-4">
                    {{-- Карта на директора (изпъкваща) --}}
                    <div class="rounded-xl border border-line bg-surface p-5">
                        <div class="flex items-start gap-4">
                            <div class="shrink-0 w-14 h-14 rounded-full flex items-center justify-center text-white text-lg font-semibold"
                                 :style="'background: var(--color-char-' + (dir.persona.color || 'blue') + ')'"
                                 x-text="initials(dir.persona.name)"></div>
                            <div class="min-w-0 flex-1">
                                <p class="text-lg font-semibold text-ink truncate" x-text="dir.persona.name"></p>
                                <p class="text-sm text-muted" x-text="dir.title + (dir.persona.age ? ' · ' + dir.persona.age + ' г.' : '')"></p>
                            </div>
                            <button type="button" x-on:click="openEditor(dir.persona, dir.title || dir.domain || 'Директор')"
                                    class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-line-strong px-3 py-1.5 text-sm text-ink hover:bg-surface-subtle transition">
                                <x-icon name="pencil-square" size="4" /> Редактирай
                            </button>
                        </div>

                        <div class="grid md:grid-cols-3 gap-4 mt-4">
                            <div>
                                <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1">Опит</p>
                                <p class="text-sm text-ink" x-text="dir.persona.background || '—'"></p>
                            </div>
                            <div>
                                <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1">Тон</p>
                                <p class="text-sm text-ink" x-text="dir.persona.tone || '—'"></p>
                            </div>
                            <div>
                                <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1">Кратко био</p>
                                <p class="text-sm text-ink" x-text="dir.persona.bio || '—'"></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mt-4 pt-4 border-t border-line">
                            @foreach (config('persona.traits') as $key => $tmeta)
                                <div>
                                    <div class="flex items-center justify-between text-[11px] text-muted mb-1">
                                        <span>{{ $tmeta['label'] }}</span>
                                        <span class="tabular-nums" x-text="dir.persona.traits.{{ $key }}"></span>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-line overflow-hidden">
                                        <div class="h-full rounded-full" :style="'width: ' + (dir.persona.traits.{{ $key }} || 0) + '%; background: var(--color-char-' + (dir.persona.color || 'blue') + ')'"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Асистенти (винаги видими) --}}
                    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        <template x-for="a in assistantsFor(dir.key)" :key="a.key">
                            <div class="rounded-xl border border-line bg-surface p-4 flex flex-col">
                                <div class="flex items-start gap-3">
                                    <div class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-semibold"
                                         :style="'background: var(--color-char-' + (a.persona.color || 'blue') + ')'"
                                         x-text="initials(a.persona.name)"></div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-ink truncate" x-text="a.persona.name"></p>
                                        <p class="text-xs text-muted truncate" x-text="a.title"></p>
                                    </div>
                                    <div class="shrink-0 flex items-center gap-0.5">
                                        <button type="button" x-on:click="openEditor(a.persona, a.title || 'Асистент')"
                                                class="p-1.5 rounded-md text-muted hover:text-primary hover:bg-surface-subtle transition" title="Редактирай" aria-label="Редактирай">
                                            <x-icon name="pencil-square" size="4" />
                                        </button>
                                        <button type="button" x-on:click="removeAssistant(a.key)"
                                                class="p-1.5 rounded-md text-muted hover:text-danger hover:bg-surface-subtle transition" title="Премахни" aria-label="Премахни">
                                            <x-icon name="x-mark" size="4" />
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-3 space-y-1.5">
                                    <p class="text-xs"><span class="text-subtle">Опит:</span> <span class="text-ink" x-text="a.persona.background || '—'"></span></p>
                                    <p class="text-xs"><span class="text-subtle">Тон:</span> <span class="text-ink" x-text="a.persona.tone || '—'"></span></p>
                                    <p class="text-xs text-muted line-clamp-3" x-text="a.persona.bio || '—'"></p>
                                </div>
                                {{-- Черти (минималистично): тънки барове по реда риск/креативност/прецизност/автономност/темпо; стилизиран tooltip на hover --}}
                                <div class="mt-auto pt-3 flex items-center gap-2">
                                    <span class="text-[10px] font-mono uppercase tracking-wider text-subtle shrink-0">Черти</span>
                                    <div class="flex-1 flex items-center gap-1.5">
                                        @foreach (config('persona.traits') as $key => $tmeta)
                                            <div class="group/trait relative flex-1">
                                                <div class="h-1.5 rounded-full bg-line overflow-hidden cursor-default">
                                                    <div class="h-full rounded-full transition-all" :style="'width: ' + (a.persona.traits.{{ $key }} || 0) + '%; background: var(--color-char-' + (a.persona.color || 'blue') + ')'"></div>
                                                </div>
                                                <div class="pointer-events-none absolute bottom-full left-1/2 z-30 mb-2 -translate-x-1/2 translate-y-1 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] leading-none opacity-0 shadow-lg transition duration-150 group-hover/trait:opacity-100 group-hover/trait:translate-y-0"
                                                     style="background: var(--color-ink); color: #ffffff;">
                                                    <span style="color: rgba(255,255,255,.92);">{{ $tmeta['label'] }}</span>
                                                    <span class="ml-1.5 font-bold tabular-nums" style="color:#ffffff;" x-text="a.persona.traits.{{ $key }} ?? 0"></span>
                                                    <span class="absolute left-1/2 top-full h-2 w-2 -translate-x-1/2 -translate-y-1/2 rotate-45" style="background: var(--color-ink);"></span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </template>

                        {{-- + Добави асистент --}}
                        {{-- БЕЗ :disabled/:class на самия бутон — Alpine x-bind върху бутон-сиблинг след
                             <template x-for> засяда и блокира кликовете; състоянието „заето" се показва
                             само през x-show на child span-овете (то работи), а JS guard-ът пази от двойно подаване. --}}
                        <button type="button" x-on:click="addAssistant(dir)"
                                class="rounded-xl border border-dashed border-line-strong p-4 min-h-[132px] flex items-center justify-center gap-2 text-sm text-muted hover:text-primary hover:border-primary/50 transition">
                            <span x-show="!addingAssistant[dir.key]" class="inline-flex items-center gap-2"><x-icon name="plus" size="4" /> Добави асистент</span>
                            <span x-show="addingAssistant[dir.key]" x-cloak class="inline-flex items-center gap-2"><x-org.bolt-spinner size="18" /> Управителят пише…</span>
                        </button>
                    </div>
                    <p x-show="addError[dir.key]" x-text="addError[dir.key]" x-cloak class="text-xs text-danger mt-1"></p>
                </div>
            </section>
        </template>

        {{-- + Нов отдел (авто: Управителят решава · или ръчно: клиентът дава име+описание) --}}
        {{-- Обвито в <template x-if> (свеж scope) — bind-ове вътре не засядат като при бутон-сиблинг след x-for. --}}
        <template x-if="design">
            <div>
                {{-- Затворено: само поканващият бутон --}}
                <template x-if="!newDeptOpen">
                    <button type="button" x-on:click="newDeptOpen = true"
                            class="w-full rounded-2xl border border-dashed border-line-strong py-4 flex items-center justify-center gap-2 text-sm font-medium text-muted hover:text-primary hover:border-primary/50 transition">
                        <x-icon name="plus" size="4" /> Нов отдел
                    </button>
                </template>

                {{-- Отворено: форма за ръчно създаване --}}
                <template x-if="newDeptOpen">
                    <div class="rounded-2xl border border-line-strong bg-surface-subtle/40 p-5 space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-ink">Нов отдел</p>
                                <p class="text-xs text-muted mt-0.5">Въведи име и описание — или остави Управителят да реши.</p>
                            </div>
                            <button type="button" x-on:click="newDeptOpen = false"
                                    class="p-1.5 rounded-md text-muted hover:text-ink hover:bg-surface transition" aria-label="Затвори">
                                <x-icon name="x-mark" size="4" />
                            </button>
                        </div>
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
                            <span x-show="addingDept" x-cloak class="inline-flex items-center gap-2 text-sm text-muted mr-auto"><x-org.bolt-spinner size="18" /> Управителят съставя нов отдел…</span>
                            <button type="button" x-on:click="addDepartment()"
                                    class="rounded-lg border border-line-strong px-3 py-2 text-sm text-ink hover:bg-surface transition">
                                Нека Управителят реши
                            </button>
                            <x-button x-on:click="addDepartment({ name: newDept.name, description: newDept.description })">Създай отдел</x-button>
                        </div>
                        <p x-show="addError.dept" x-text="addError.dept" x-cloak class="text-sm text-danger"></p>
                    </div>
                </template>
            </div>
        </template>

        <div class="flex items-center justify-end gap-3 pt-2">
            <p x-show="approveMsg" x-text="approveMsg" class="text-sm text-muted mr-auto"></p>
            <x-button x-on:click="approve()" x-bind:disabled="approving">
                <span x-text="approving ? 'Създавам организацията…' : 'Одобри и създай екипа'"></span>
            </x-button>
        </div>
    </div>

    {{-- Панел за редакция (drawer) — преизползва пълната персона-форма --}}
    <div x-show="editorOpen" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-ink/30" x-on:click="closeEditor()"
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
                <button type="button" x-on:click="closeEditor()" class="p-1.5 rounded-md text-muted hover:text-ink hover:bg-surface-subtle transition" aria-label="Затвори">
                    <x-icon name="x-mark" size="5" />
                </button>
            </div>
            <template x-if="editorOpen && editPersona">
                <div class="p-5 space-y-4">
                    @include('client.org._persona-fields', ['modelPrefix' => 'editPersona'])
                    <div class="flex justify-end pt-2">
                        <x-button x-on:click="closeEditor()">Готово</x-button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Панел за редакция на ОТДЕЛА (име/описание/приоритети) --}}
    <div x-show="deptEditorOpen" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-ink/30" x-on:click="closeDeptEditor()"
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
                <button type="button" x-on:click="closeDeptEditor()" class="p-1.5 rounded-md text-muted hover:text-ink hover:bg-surface-subtle transition" aria-label="Затвори">
                    <x-icon name="x-mark" size="5" />
                </button>
            </div>
            <template x-if="deptEditorOpen && editDept">
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-ink mb-1">Име на отдела</label>
                        <x-input x-model="editDept.title" maxlength="160" placeholder="напр. Директор Финанси" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink mb-1">С какво се занимава</label>
                        <x-textarea x-model="editDept.mandate" rows="3" maxlength="1000" placeholder="Опиши накратко с какво се занимава отделът." />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink mb-1">Приоритети <span class="text-xs text-muted">(какви приоритети има отделът)</span></label>
                        {{-- Tag input: чипове + поле за добавяне. Enter/запетая добавя, × маха, Backspace на празно маха последния. --}}
                        <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-line bg-surface px-2 py-2 transition focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30"
                             x-on:click="$refs.prioInput && $refs.prioInput.focus()">
                            <template x-for="(p, pi) in (editDept.priorities || [])" :key="pi">
                                <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-line bg-surface-subtle py-1 pl-2.5 pr-1 text-xs text-ink">
                                    <span class="truncate" x-text="p"></span>
                                    <button type="button" x-on:click.stop="removePriority(pi)"
                                            class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-subtle transition hover:bg-danger-soft/60 hover:text-danger"
                                            :aria-label="'Премахни приоритет: ' + p">
                                        <x-icon name="x-mark" size="3" />
                                    </button>
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
                    <div class="flex justify-end pt-2">
                        <x-button x-on:click="closeDeptEditor()">Готово</x-button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Цвят = домейн на отдела (огледало на OrgMember::functionColor / config function_colors).
const FUNCTION_COLORS = @js(config('organization.function_colors'));
const DEFAULT_FN_COLOR = @js(config('organization.default_function_color', 'blue'));
function colorForDomain(domain) {
    domain = (domain || '').toString().toLowerCase();
    for (const needle in FUNCTION_COLORS) {
        if (domain && domain.includes(needle.toLowerCase())) return FUNCTION_COLORS[needle];
    }
    return DEFAULT_FN_COLOR;
}
function design(cfg) {
    return {
        // Споделеният персона-форма helper (aiFill/aiBusy) за панела за редакция.
        ...window.personaFormBase({ suggestUrl: cfg.suggestUrl, csrf: cfg.csrf, role: '' }),

        loading: true, stage: '', error: '', design: null, timer: null, approving: false, approveMsg: '', started: false,
        editorOpen: false, editPersona: null, editRole: '', addingDept: false, addingAssistant: {}, addError: {},
        newDeptOpen: false, newDept: { name: '', description: '' }, deptEditorOpen: false, editDept: null, prioDraft: '',

        // personaFormBase override-и → сочат към персоната в редакция (жива референция в design).
        aiRole() { return this.editRole; },
        aiContext() { return this.editPersona || {}; },
        aiApply(field, value) { if (this.editPersona) this.editPersona[field] = value; },

        init() {
            if (this.started) return;   // Alpine може да извика init() повече от веднъж — без двоен propose.
            this.started = true;
            fetch(cfg.proposeUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' } })
                .then(r => r.json()).then(d => d.token ? this.poll(d.token) : this.fail())
                .catch(() => this.fail());
        },
        poll(token) {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }   // никога не оставяй сирак-интервал (трие edit-ите)
            const url = cfg.statusTpl.replace('TOKEN', token);
            let settled = false, fails = 0;
            const stop = () => { settled = true; if (this.timer) { clearInterval(this.timer); this.timer = null; } this.loading = false; };
            const tick = async () => {
                if (settled) return;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (settled) return;
                    if (d.status === 'pending') { this.stage = d.stage || 'Композирам…'; fails = 0; return; }
                    stop();
                    if (d.status === 'completed' && d.design) { this.design = this.normalize(d.design); }
                    else { this.fail(d.error); }
                } catch (e) { if (++fails >= 8) { stop(); this.fail(); } }
            };
            tick(); this.timer = setInterval(tick, 2500);
        },
        assistantsFor(dirKey) { return (this.design.assistants || []).filter(a => a.director === dirKey); },
        usedDomains() { return (this.design.directors || []).map(d => d.domain).filter(Boolean); },
        initials(name) {
            return (name || '').toString().trim().split(/\s+/).slice(0, 2).map(w => (w[0] || '').toUpperCase()).join('') || '—';
        },

        // Гарантира persona обект + всичките 5 черти (LLM понякога пропуска autonomy) + цвят по домейн.
        normalize(design) {
            const def = { risk: 50, creativity: 50, precision: 50, autonomy: 60, tempo: 55 };
            const fix = (m) => { m.persona = m.persona || {}; m.persona.traits = Object.assign({}, def, m.persona.traits || {}); };
            (design.directors || []).forEach(fix);
            (design.assistants || []).forEach(fix);
            // Цвят на чертите по отдел: директор по своя домейн, асистент по домейна на директора му.
            // Тук гарантираме и описание/приоритети на отдела (безопасни за x-text/x-for).
            (design.directors || []).forEach(d => {
                d.persona.color = colorForDomain(d.domain);
                d.mandate = d.mandate || '';
                d.priorities = Array.isArray(d.priorities) ? d.priorities : [];
            });
            (design.assistants || []).forEach(a => {
                const dir = (design.directors || []).find(x => x.key === a.director);
                a.persona.color = colorForDomain(dir ? dir.domain : a.director);
            });
            return design;
        },

        // ── Редакция в панел ──────────────────────────────────────────────
        openEditor(persona, role) { this.editPersona = persona; this.editRole = role; this.aiError = ''; this.editorOpen = true; },
        closeEditor() { this.editorOpen = false; },

        // ── Редакция на ОТДЕЛА (име/описание/приоритети; editDept е жива референция в design) ──
        openDeptEditor(dir) {
            dir.priorities = Array.isArray(dir.priorities) ? dir.priorities : [];
            this.editDept = dir;
            this.prioDraft = '';
            this.deptEditorOpen = true;
        },
        closeDeptEditor() {
            this.addPriority();   // комитни недовършения чернова-таг преди затваряне
            this.deptEditorOpen = false;
        },
        // Приоритети като тагове (мутират editDept.priorities = живата референция в design).
        addPriority() {
            const v = (this.prioDraft || '').trim();
            this.prioDraft = '';
            if (!v || !this.editDept) return;
            this.editDept.priorities = this.editDept.priorities || [];
            if (!this.editDept.priorities.includes(v)) this.editDept.priorities.push(v);
        },
        removePriority(i) {
            const list = this.editDept && this.editDept.priorities;
            if (!Array.isArray(list) || i < 0 || i >= list.length) return;
            list.splice(i, 1);
        },

        // ── Структурни промени (мутират design; одобрението материализира) ──
        removeAssistant(key) { this.design.assistants = (this.design.assistants || []).filter(a => a.key !== key); },
        removeDirector(dirKey) {
            if ((this.design.directors || []).length <= 1) { this.approveMsg = 'Нужен е поне един отдел.'; return; }
            if (this.editorOpen) this.closeEditor();
            this.design.directors = this.design.directors.filter(d => d.key !== dirKey);
            this.design.assistants = (this.design.assistants || []).filter(a => a.director !== dirKey);
            this.approveMsg = '';
        },
        setErr(key, msg) { this.addError = { ...this.addError, [key]: msg }; },
        // Поллва фоновата генерация (org_add_{token}); LLM call-ът е на Horizon worker-а
        // (без 30s уеб лимит). 2с интервал, до ~60 опита (2 мин).
        pollAddition(token, onDone, onFail) {
            const url = cfg.addStatusTpl.replace('TOKEN', token);
            let tries = 0;
            const timer = setInterval(async () => {
                tries++;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (d.status === 'completed') { clearInterval(timer); onDone(d); }
                    else if (d.status === 'failed' || d.status === 'expired') { clearInterval(timer); onFail(d.error || 'Грешка.'); }
                    else if (tries >= 60) { clearInterval(timer); onFail('Отне твърде дълго, опитай пак.'); }
                } catch (e) { if (tries >= 60) { clearInterval(timer); onFail('Услугата не отговори.'); } }
            }, 2000);
        },
        addAssistant(dir) {
            if (this.addingAssistant[dir.key]) return;
            this.addingAssistant = { ...this.addingAssistant, [dir.key]: true };
            this.setErr(dir.key, '');
            const done = () => { this.addingAssistant = { ...this.addingAssistant, [dir.key]: false }; };
            fetch(cfg.genAssistantUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({
                    department: { key: dir.key, title: dir.title, domain: dir.domain, mandate: dir.mandate },
                    existing: this.assistantsFor(dir.key).map(a => ({ key: a.key, title: a.title })),
                }),
            })
                .then(r => r.json())
                .then(d => {
                    if (!d.token) throw new Error(d.error || 'Грешка');
                    this.pollAddition(d.token,
                        (res) => { if (res.assistant) { this.design.assistants.push(res.assistant); this.normalize(this.design); } done(); },
                        (err) => { this.setErr(dir.key, err); done(); });
                })
                .catch(() => { this.setErr(dir.key, 'Не успях да стартирам генерирането.'); done(); });
        },
        addDepartment(custom = null) {
            if (this.addingDept) return;
            this.addingDept = true; this.setErr('dept', '');
            const done = () => { this.addingDept = false; };
            fetch(cfg.genDepartmentUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({
                    existing_domains: this.usedDomains(),
                    name: (custom && custom.name) || '',
                    description: (custom && custom.description) || '',
                }),
            })
                .then(r => r.json())
                .then(d => {
                    if (!d.token) throw new Error(d.error || 'Грешка');
                    this.pollAddition(d.token,
                        (res) => {
                            const dep = res.department;
                            if (dep && dep.director) {
                                this.mergeNewDepartment(dep);
                                this.newDeptOpen = false; this.newDept = { name: '', description: '' };
                            }
                            done();
                        },
                        (err) => { this.setErr('dept', err); done(); });
                })
                .catch(() => { this.setErr('dept', 'Не успях да стартирам генерирането.'); done(); });
        },

        // Слива нов отдел, ГАРАНТИРАЙКИ уникален director key за x-for :key (ако ръчно име
        // колидира със съществуващ домейн) — клиентско огледало на finalizeOrganization `_2`.
        mergeNewDepartment(dep) {
            const dir = dep.director;
            const taken = new Set((this.design.directors || []).map(d => d.key));
            if (taken.has(dir.key)) {
                const base = dir.key; let n = 2, key = base + '_' + n;
                while (taken.has(key)) { key = base + '_' + (++n); }
                (dep.assistants || []).forEach(a => { if (a.director === dir.key) a.director = key; });
                dir.key = key;
            }
            this.design.directors.push(dir);
            (dep.assistants || []).forEach(a => this.design.assistants.push(a));
            this.normalize(this.design);
        },

        approve() {
            if (!this.design) return;
            this.approving = true; this.approveMsg = '';
            fetch(cfg.approveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify({ design: this.design }) })
                .then(r => r.json()).then(d => { if (d.ok && d.redirect) window.location = d.redirect; else { this.approving = false; this.approveMsg = d.error || 'Грешка.'; } })
                .catch(() => { this.approving = false; this.approveMsg = 'Грешка при одобрение.'; });
        },
        fail(msg) { this.loading = false; this.error = msg || 'Дизайнът се провали. Опитай пак.'; },
    };
}
</script>
@endpush
@endsection
