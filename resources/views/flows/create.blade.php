@extends('layouts.app')

@section('title', 'Нов flow — ' . $company->name)

@section('content')
<div x-data="flowCreator()" x-init="init()">

    <div class="mb-6">
        <a href="{{ route('companies.show', $company) }}" class="text-indigo-600 hover:underline text-sm">
            ← {{ $company->name }}
        </a>
        <h1 class="text-3xl font-bold text-gray-900 mt-2">Нов flow</h1>
        <p class="text-gray-500 mt-1">Опиши flow-а. След запис AI ще генерира агентите автоматично в Граф Редактора.</p>
    </div>

    <form action="{{ route('companies.flows.store', $company) }}" method="POST" id="flow-form">
        @csrf

        {{-- Основна информация --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Основна информация</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Наименование на flow-а</label>
                    <input type="text" name="name" x-model="flowName" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="напр. Ежедневен Facebook пост">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-sm font-medium text-gray-700">Описание на flow-а</label>
                        <button type="button"
                                @click="improveDescription"
                                :disabled="isImproving || !flowDescription.trim()"
                                class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                            <span x-show="isImproving" class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                            <span x-text="isImproving ? 'Подобрявам...' : '✨ Подобри с AI'"></span>
                        </button>
                    </div>
                    <textarea name="description" x-model="flowDescription" rows="4" required
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                              placeholder="Опиши подробно какво трябва да прави flow-ът. Колкото по-детайлно, толкова по-добри агенти ще генерира AI."></textarea>

                    {{-- AI preview panel --}}
                    <div x-show="showImprovePreview" x-cloak
                         class="mt-3 bg-indigo-50 border border-indigo-200 rounded-xl p-4">
                        <p class="text-xs font-semibold text-indigo-700 mb-2">✨ AI предлага подобрено описание:</p>
                        <p class="text-sm text-gray-700 leading-relaxed mb-3" x-text="improvedDescription"></p>
                        <div class="flex gap-2">
                            <button type="button" @click="acceptImprovedDescription"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                                ✓ Приеми
                            </button>
                            <button type="button" @click="showImprovePreview = false"
                                    class="bg-white border border-gray-300 text-gray-600 text-sm px-4 py-1.5 rounded-lg hover:bg-gray-50 transition">
                                ✕ Откажи
                            </button>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Статус</label>
                        <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                        </select>
                    </div>
                </div>

                {{-- Schedule picker (full width) --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        График на изпълнение
                        <span class="text-gray-400 font-normal text-xs ml-1">(по избор)</span>
                    </label>

                    {{-- Hidden input carries the cron value for form submission --}}
                    <input type="hidden" name="schedule_cron" :value="cronValue">

                    {{-- Preset buttons --}}
                    <div class="grid grid-cols-5 gap-2 mb-3">
                        <template x-for="preset in [
                            { id: 'none',    icon: '—',  label: 'Никога',   sub: 'само ръчно' },
                            { id: 'hourly',  icon: '🕐', label: 'На час',   sub: 'всеки час' },
                            { id: 'daily',   icon: '📅', label: 'Дневно',   sub: 'веднъж/ден' },
                            { id: 'weekly',  icon: '📆', label: 'Седмично', sub: 'веднъж/седм.' },
                            { id: 'monthly', icon: '🗓', label: 'Месечно',  sub: 'веднъж/месец' },
                        ]" :key="preset.id">
                            <button type="button"
                                    @click="schedule.preset = preset.id; schedule.showCustom = false"
                                    :class="schedule.preset === preset.id
                                        ? 'bg-indigo-600 border-indigo-600 text-white'
                                        : 'bg-white border-gray-200 text-gray-700 hover:border-indigo-300'"
                                    class="border rounded-xl p-2.5 text-center cursor-pointer transition text-sm">
                                <span class="block text-lg leading-none mb-1" x-text="preset.icon"></span>
                                <span class="block font-semibold text-xs" x-text="preset.label"></span>
                                <span class="block text-[10px] opacity-70" x-text="preset.sub"></span>
                            </button>
                        </template>
                    </div>

                    {{-- Time picker — shown for daily/weekly/monthly --}}
                    <div x-show="['daily','weekly','monthly'].includes(schedule.preset)" x-cloak
                         class="flex flex-wrap items-center gap-3 mb-3">

                        <template x-if="schedule.preset === 'weekly'">
                            <div class="flex items-center gap-2">
                                <label class="text-sm text-gray-600 whitespace-nowrap">Ден от седмицата:</label>
                                <select x-model="schedule.dayOfWeek"
                                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <option value="1">Понеделник</option>
                                    <option value="2">Вторник</option>
                                    <option value="3">Сряда</option>
                                    <option value="4">Четвъртък</option>
                                    <option value="5">Петък</option>
                                    <option value="6">Събота</option>
                                    <option value="0">Неделя</option>
                                </select>
                            </div>
                        </template>

                        <template x-if="schedule.preset === 'monthly'">
                            <div class="flex items-center gap-2">
                                <label class="text-sm text-gray-600 whitespace-nowrap">Ден от месеца:</label>
                                <select x-model="schedule.dayOfMonth"
                                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <template x-for="d in Array.from({length:31},(_,i)=>i+1)" :key="d">
                                        <option :value="d" x-text="d"></option>
                                    </template>
                                </select>
                            </div>
                        </template>

                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600 whitespace-nowrap">В колко часа:</label>
                            <select x-model="schedule.hour"
                                    class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <template x-for="h in Array.from({length:24},(_,i)=>i)" :key="h">
                                    <option :value="h" x-text="String(h).padStart(2,'0') + ':00'"></option>
                                </template>
                            </select>
                        </div>
                    </div>

                    {{-- Human-readable summary --}}
                    <div x-show="schedule.preset !== 'none'" x-cloak
                         class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-xs text-gray-500 flex items-center gap-2 mb-2">
                        <span>📋</span>
                        <span>
                            <template x-if="schedule.preset === 'hourly'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всеки час</strong></span>
                            </template>
                            <template x-if="schedule.preset === 'daily'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всеки ден в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                            </template>
                            <template x-if="schedule.preset === 'weekly'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всяка седмица в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                            </template>
                            <template x-if="schedule.preset === 'monthly'">
                                <span>Ще се изпълнява <strong class="text-gray-700">всеки месец на <span x-text="schedule.dayOfMonth"></span>-ти в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                            </template>
                            <template x-if="schedule.preset === 'custom'">
                                <span>Cron: <code class="font-mono" x-text="schedule.customCron"></code></span>
                            </template>
                            · <code class="font-mono text-gray-400" x-text="cronValue"></code>
                        </span>
                    </div>

                    {{-- Advanced / custom cron --}}
                    <button type="button" @click="schedule.showCustom = !schedule.showCustom; if(schedule.showCustom) schedule.preset = 'custom'"
                            class="text-xs text-gray-400 hover:text-gray-600 underline transition">
                        ⚙ По избор (напреднали)
                    </button>
                    <div x-show="schedule.showCustom" x-cloak class="mt-2">
                        <input type="text" x-model="schedule.customCron"
                               placeholder="напр. 0 10 * * 1-5"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <p class="text-xs text-gray-400 mt-1">Стандартен cron синтаксис: минута час ден-от-месец месец ден-от-седмица</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('companies.show', $company) }}"
               class="px-5 py-2.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition font-medium">
                Откажи
            </a>
            <button type="submit"
                    :disabled="!flowName.trim() || !flowDescription.trim()"
                    class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white font-medium transition">
                ✨ Запази и генерирай агенти
            </button>
        </div>
    </form>
</div>

<script>
function flowCreator() {
    return {
        flowName: '',
        flowDescription: '',
        isImproving: false,
        improvedDescription: '',
        showImprovePreview: false,
        schedule: {
            preset: 'none',
            hour: '10',
            dayOfWeek: '1',
            dayOfMonth: '1',
            customCron: '',
            showCustom: false,
        },

        get cronValue() {
            const s = this.schedule;
            if (s.preset === 'none') return '';
            if (s.preset === 'hourly') return '0 * * * *';
            if (s.preset === 'daily') return `0 ${s.hour} * * *`;
            if (s.preset === 'weekly') return `0 ${s.hour} * * ${s.dayOfWeek}`;
            if (s.preset === 'monthly') return `0 ${s.hour} ${s.dayOfMonth} * *`;
            if (s.preset === 'custom') return s.customCron;
            return '';
        },

        init() {
            // Sync text inputs with x-model after a server-side validation bounce.
            const nameEl = document.querySelector('input[name="name"]');
            if (nameEl?.value) this.flowName = nameEl.value;
            const descEl = document.querySelector('textarea[name="description"]');
            if (descEl?.value) this.flowDescription = descEl.value;
        },

        async improveDescription() {
            if (!this.flowDescription.trim()) return;
            this.isImproving = true;
            this.showImprovePreview = false;
            this.improvedDescription = '';

            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            try {
                const resp = await fetch('{{ route('flows.improve-description') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        description: this.flowDescription,
                        name: this.flowName,
                        company_id: {{ $company->id }},
                    }),
                });
                const data = await resp.json();
                if (resp.ok && data.improved) {
                    this.improvedDescription = data.improved;
                    this.showImprovePreview = true;
                } else {
                    alert(data.error || 'Грешка при подобряването. Опитай отново.');
                }
            } catch (e) {
                alert('Мрежова грешка: ' + e.message);
            } finally {
                this.isImproving = false;
            }
        },

        acceptImprovedDescription() {
            this.flowDescription = this.improvedDescription;
            this.showImprovePreview = false;
        },
    };
}
</script>
@endsection
