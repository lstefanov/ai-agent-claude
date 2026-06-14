@extends('layouts.app')

@section('title', ($case ? 'Редакция' : 'Нов тест') . ' — ' . $flow->name)

@php
    $action = $case ? route('flows.eval.update', [$flow, $case]) : route('flows.eval.store', $flow);
    $promptVal = old('prompt', $case->input_data['prompt'] ?? '');
    $varsVal = old('variables_json');
    if ($varsVal === null) {
        $vars = $case->input_data['variables'] ?? null;
        $varsVal = $vars ? json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }
    $field = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';
    $fieldSm = 'w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';
@endphp

@section('content')
<div class="max-w-3xl mx-auto" x-data="evalCaseForm(@js($case?->criteria ?? []))">
    <div class="mb-6">
        <div class="text-sm text-gray-400 mb-1">
            <a href="{{ route('companies.show', $flow->company) }}" class="hover:text-indigo-600">{{ $flow->company->name }}</a>
            <span class="mx-1">/</span>
            <a href="{{ route('flows.show', $flow) }}" class="hover:text-indigo-600">{{ $flow->name }}</a>
            <span class="mx-1">/</span>
            <a href="{{ route('flows.eval.index', $flow) }}" class="hover:text-indigo-600">Eval</a>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $case ? '✏️ Редакция на тест' : '➕ Нов тест' }}</h1>
        <p class="text-sm text-gray-500 mt-1">Тестът = заявка към flow-а + критерии, по които AI-judge оценява изхода.</p>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
            <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ $action }}" @submit="syncCriteria()" class="space-y-5">
        @csrf
        @if($case) @method('PUT') @endif
        <input type="hidden" name="criteria_json" x-ref="criteriaJson">

        {{-- 1. Основни --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">1 · Основни</div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Име на теста</label>
                <input type="text" name="name" value="{{ old('name', $case?->name) }}" required
                       placeholder="напр. Промо оферта — подмишници" class="{{ $field }}">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Описание <span class="text-gray-400 font-normal">(само за теб, не влиза в AI-то)</span></label>
                <input type="text" name="description" value="{{ old('description', $case?->description) }}" class="{{ $field }}">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Тежест на теста</label>
                <input type="number" step="0.1" min="0" name="weight" value="{{ old('weight', $case?->weight ?? 1.0) }}" class="{{ $field }} max-w-[140px]">
                <p class="text-xs text-gray-400 mt-1">Колко тежи спрямо другите тестове при агрегиране (1.0 = нормално).</p>
            </div>
        </div>

        {{-- 2. Вход на flow-а --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">2 · Вход на flow-а</div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Заявка (prompt)</label>
                <textarea name="prompt" rows="4" required class="{{ $field }}"
                          placeholder="Каквото реален потребител би въвел в полето за вход на flow-а при истински run.">{{ $promptVal }}</textarea>
                <p class="text-xs text-gray-400 mt-1">Това е входът, който flow-ът получава за този тест.</p>
            </div>
            <div x-data="{ open: {{ $varsVal ? 'true' : 'false' }} }">
                <button type="button" @click="open = !open" class="text-sm text-indigo-600 hover:text-indigo-800">
                    <span x-show="!open">+ Допълнителни променливи (по избор)</span>
                    <span x-show="open">− Скрий променливите</span>
                </button>
                <div x-show="open" x-cloak class="mt-2">
                    <textarea name="variables_json" rows="3" class="{{ $field }} font-mono text-xs"
                              placeholder='{"tone": "официален", "max_words": 150}'>{{ $varsVal }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">JSON с допълнителни плейсхолдъри, ако flow-ът ползва такива. Остави празно, ако не ти трябват.</p>
                </div>
            </div>
        </div>

        {{-- 3. Критерии --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5" x-data="{ help: false }">
            <div class="flex items-center justify-between mb-1">
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">3 · Критерии за качество</div>
                <div class="flex items-center gap-3">
                    <button type="button" @click="help = !help" class="text-sm text-gray-400 hover:text-gray-600" x-text="help ? '− Скрий помощта' : '? Как работят критериите'"></button>
                    <button type="button" @click="addCriterion()" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">+ Критерий</button>
                </div>
            </div>
            <p class="text-xs text-gray-400 mb-3">Започни с 2–3. <b>llm_judge</b> = AI оценява (за субективни неща); <b>rule/regex</b> = автоматична проверка (дължина, ключова дума, формат).</p>

            {{-- Help панел --}}
            <div x-show="help" x-cloak class="mb-4 border border-gray-200 rounded-lg p-4 bg-gray-50 text-xs text-gray-600 space-y-2">
                <div>💡 <b>Тестът = реален вход В РАМКИТЕ на задачата на flow-а</b> (напр. различна зона/тема), НЕ различна задача. Ако подадеш съвсем друга задача, агентите (с фиксирани промптове) ще се справят зле — това не мери качество.</div>
                <div><b>Всеки критерий</b> дава оценка 0–100; крайният Score е среднопретеглен по <b>тежест</b> (важен критерий = по-висока тежест, напр. точност 2.0, граматика 0.5).</div>
                <div class="pt-1"><b>Типове:</b></div>
                <ul class="list-disc list-inside space-y-1 ml-1">
                    <li><b>llm_judge</b> — AI чете изхода и дава оценка спрямо твоята инструкция. За субективни неща: <i>„Текстът е на официален тон", „Цените са конкретни и реални"</i>.</li>
                    <li><b>rule</b> — детерминистична проверка (без AI):
                        <ul class="list-[circle] list-inside ml-4 mt-1 space-y-0.5">
                            <li><code>word_count</code> — брой думи между мин и макс (напр. 300–600).</li>
                            <li><code>contains_keyword</code> — изходът съдържа дума (напр. „промоция").</li>
                            <li><code>no_placeholder</code> — няма незапълнени шаблони (<code>[ИМЕ]</code>, <code>@{{…}}</code>).</li>
                            <li><code>valid_json</code> — изходът е валиден JSON.</li>
                        </ul>
                    </li>
                    <li><b>regex</b> — съвпадение с регулярен израз (напр. имейл/телефон формат).</li>
                </ul>
                <div class="pt-1">✅ Винаги слагай поне 1 <b>rule</b> критерий — той е детерминистичен и не зависи от AI judge-а.</div>
            </div>

            <template x-for="(c, i) in criteria" :key="i">
                <div class="border border-gray-200 rounded-lg p-4 mb-3 bg-gray-50/50">
                    <div class="grid sm:grid-cols-12 gap-3">
                        <div class="sm:col-span-5">
                            <label class="block text-xs text-gray-500 mb-1">Етикет</label>
                            <input type="text" x-model="c.label" placeholder="Точност на цените" class="{{ $fieldSm }}">
                        </div>
                        <div class="sm:col-span-4">
                            <label class="block text-xs text-gray-500 mb-1">Тип</label>
                            <select x-model="c.type" class="{{ $fieldSm }}">
                                <option value="llm_judge">llm_judge — AI оценява</option>
                                <option value="rule">rule — авто проверка</option>
                                <option value="regex">regex — формат</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-xs text-gray-500 mb-1">Тежест</label>
                            <input type="number" step="0.1" min="0" x-model.number="c.weight" class="{{ $fieldSm }}">
                        </div>

                        <div class="sm:col-span-12" x-show="c.type !== 'regex'">
                            <label class="block text-xs text-gray-500 mb-1">
                                <span x-show="c.type === 'llm_judge'">Инструкция за judge-а — какво точно да провери (колкото по-конкретно, толкова по-добра оценка)</span>
                                <span x-show="c.type === 'rule'">Описание (за справка)</span>
                            </label>
                            <textarea x-model="c.description" rows="3" class="{{ $fieldSm }}"
                                      placeholder="напр. Всички цитирани цени са конкретни и реални (не общи фрази); посочена е апаратурата; условията (брой сесии, подготовка) са пълни."></textarea>
                            <p class="text-[11px] text-gray-400 mt-1" x-show="c.type === 'llm_judge'">Опиши какво е „добър" изход по този критерий — judge-ът (AI) дава 0–100 спрямо това.</p>
                        </div>

                        {{-- rule params --}}
                        <template x-if="c.type === 'rule'">
                            <div class="sm:col-span-12 grid sm:grid-cols-12 gap-3">
                                <div class="sm:col-span-4">
                                    <label class="block text-xs text-gray-500 mb-1">Правило</label>
                                    <select x-model="c.rule" class="{{ $fieldSm }}">
                                        <option value="word_count">word_count — брой думи</option>
                                        <option value="contains_keyword">contains_keyword — дума</option>
                                        <option value="no_placeholder">no_placeholder — без [ШАБЛОН]</option>
                                        <option value="valid_json">valid_json</option>
                                    </select>
                                </div>
                                <template x-if="c.rule === 'word_count'">
                                    <div class="sm:col-span-5 flex gap-2">
                                        <div class="flex-1"><label class="block text-xs text-gray-500 mb-1">Мин думи</label><input type="number" x-model.number="c.min" placeholder="50" class="{{ $fieldSm }}"></div>
                                        <div class="flex-1"><label class="block text-xs text-gray-500 mb-1">Макс думи</label><input type="number" x-model.number="c.max" placeholder="600" class="{{ $fieldSm }}"></div>
                                    </div>
                                </template>
                                <template x-if="c.rule === 'contains_keyword'">
                                    <div class="sm:col-span-5"><label class="block text-xs text-gray-500 mb-1">Ключова дума</label><input type="text" x-model="c.keyword" placeholder="PrimeLase" class="{{ $fieldSm }}"></div>
                                </template>
                            </div>
                        </template>

                        {{-- regex params --}}
                        <template x-if="c.type === 'regex'">
                            <div class="sm:col-span-12">
                                <label class="block text-xs text-gray-500 mb-1">Регулярен израз</label>
                                <input type="text" x-model="c.pattern" placeholder="[\w.+-]+@[\w.-]+\.\w+" class="{{ $fieldSm }} font-mono">
                                <label class="inline-flex items-center gap-2 text-xs text-gray-500 mt-2">
                                    <input type="checkbox" x-model="c.should_match" class="rounded border-gray-300 text-indigo-600"> трябва да съвпада
                                </label>
                            </div>
                        </template>
                    </div>
                    <div class="text-right mt-2">
                        <button type="button" @click="criteria.splice(i, 1)" class="text-xs text-gray-400 hover:text-red-600">премахни критерий</button>
                    </div>
                </div>
            </template>

            <p x-show="criteria.length === 0" class="text-sm text-gray-400 text-center py-4">Няма критерии. Натисни „+ Критерий".</p>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2 rounded-lg">Запази теста</button>
            <a href="{{ route('flows.eval.index', $flow) }}" class="text-sm text-gray-500 hover:text-gray-700">Отказ</a>
        </div>
    </form>
</div>

@push('scripts')
<script>
function evalCaseForm(initial) {
    return {
        criteria: (initial || []).map(c => ({
            key: c.key ?? '', label: c.label ?? '', description: c.description ?? '',
            weight: c.weight ?? 1.0, type: c.type ?? 'llm_judge',
            rule: c.rule ?? 'word_count', min: c.min ?? null, max: c.max ?? null,
            keyword: c.keyword ?? '', pattern: c.pattern ?? '', should_match: c.should_match ?? true,
        })),
        addCriterion() {
            this.criteria.push({ key: '', label: '', description: '', weight: 1.0, type: 'llm_judge',
                rule: 'word_count', min: null, max: null, keyword: '', pattern: '', should_match: true });
        },
        syncCriteria() {
            // ключът се генерира от етикета, ако липсва (контролерът също подсигурява).
            this.criteria.forEach(c => { if (!c.key && c.label) c.key = c.label.toLowerCase().replace(/[^a-zа-я0-9]+/gi, '_').replace(/^_|_$/g, '').slice(0, 40); });
            this.$refs.criteriaJson.value = JSON.stringify(this.criteria);
        },
        init() {
            if (this.criteria.length === 0) this.addCriterion();
        },
    };
}
</script>
@endpush
@endsection
