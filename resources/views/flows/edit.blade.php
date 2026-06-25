@extends('layouts.app')

@section('title', 'Редактирай ' . $flow->name)

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('flows.show', $flow) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition"><x-icon name="arrow-left" size="4" /> Обратно</a>
        <h1 class="text-2xl font-display font-bold text-ink mt-2">Редактирай flow</h1>
    </div>

    <div class="bg-surface rounded-xl shadow-sm border border-line p-8">
        <form action="{{ route('flows.update', $flow) }}" method="POST" class="space-y-6"
              x-data="flowEditForm()">
            @csrf @method('PUT')

            <div>
                <label class="block text-sm font-medium text-ink mb-1">Наименование</label>
                <input type="text" name="name" value="{{ old('name', $flow->name) }}" x-model="flowName" required
                       class="w-full border border-line rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-sm font-medium text-ink">Описание</label>
                    <button type="button"
                            @click="improveDescription"
                            :disabled="isImproving || !flowDescription.trim()"
                            class="inline-flex items-center gap-1.5 bg-primary hover:bg-primary-hover disabled:opacity-50 disabled:cursor-not-allowed text-primary-fg text-xs font-medium px-3 py-1 rounded-md transition">
                        <span x-show="isImproving" class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                        <x-icon name="sparkles" size="3.5" x-show="!isImproving" />
                        <span x-text="isImproving ? 'Подобрявам...' : 'Подобри с AI'"></span>
                    </button>
                </div>
                <textarea name="description" x-model="flowDescription" rows="6" required
                          class="w-full border border-line rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary/40">{{ old('description', $flow->description) }}</textarea>

                <div x-show="showImprovePreview" x-cloak
                     class="mt-3 bg-info-soft border border-info rounded-xl p-4">
                    <p class="text-xs font-medium text-primary mb-2 inline-flex items-center gap-1"><x-icon name="sparkles" size="3.5" /> AI предлага подобрено описание:</p>
                    <p class="text-sm text-ink leading-relaxed mb-3" x-text="improvedDescription"></p>
                    <div class="flex gap-2">
                        <button type="button" @click="acceptImprovedDescription"
                                class="inline-flex items-center gap-1 bg-primary hover:bg-primary-hover text-primary-fg text-sm font-medium px-4 py-1.5 rounded-md transition">
                            <x-icon name="check" size="4" /> Приеми
                        </button>
                        <button type="button" @click="showImprovePreview = false"
                                class="inline-flex items-center gap-1 bg-surface border border-line-strong text-muted text-sm px-4 py-1.5 rounded-md hover:bg-surface-subtle transition">
                            <x-icon name="x-mark" size="4" /> Откажи
                        </button>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-ink mb-1">Статус</label>
                <select name="status" class="w-full border border-line rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary/40">
                    @foreach(['draft','active','paused'] as $s)
                        <option value="{{ $s }}" {{ old('status', $flow->status) === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-ink mb-2">
                    График на изпълнение
                    <span class="text-subtle font-normal text-xs ml-1">(по избор)</span>
                </label>

                {{-- Hidden input carries the cron value for form submission --}}
                <input type="hidden" name="schedule_cron" :value="cronValue">

                {{-- Preset buttons --}}
                <div class="grid grid-cols-5 gap-2 mb-3">
                    <template x-for="preset in [
                        { id: 'none',    label: 'Никога',   sub: 'само ръчно' },
                        { id: 'hourly',  label: 'На час',   sub: 'всеки час' },
                        { id: 'daily',   label: 'Дневно',   sub: 'веднъж/ден' },
                        { id: 'weekly',  label: 'Седмично', sub: 'веднъж/седм.' },
                        { id: 'monthly', label: 'Месечно',  sub: 'веднъж/месец' },
                    ]" :key="preset.id">
                        <button type="button"
                                @click="schedule.preset = preset.id; schedule.showCustom = false"
                                :class="schedule.preset === preset.id
                                    ? 'bg-primary border-primary text-primary-fg'
                                    : 'bg-surface border-line text-ink hover:border-line-strong'"
                                class="border rounded-lg p-2.5 text-center cursor-pointer transition text-sm">
                            <span class="block font-medium text-xs" x-text="preset.label"></span>
                            <span class="block text-[10px] opacity-70 mt-0.5" x-text="preset.sub"></span>
                        </button>
                    </template>
                </div>

                {{-- Time picker — shown for daily/weekly/monthly --}}
                <div x-show="['daily','weekly','monthly'].includes(schedule.preset)" x-cloak
                     class="flex flex-wrap items-center gap-3 mb-3">

                    <template x-if="schedule.preset === 'weekly'">
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-muted whitespace-nowrap">Ден от седмицата:</label>
                            <select x-model="schedule.dayOfWeek"
                                    class="border border-line rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
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
                            <label class="text-sm text-muted whitespace-nowrap">Ден от месеца:</label>
                            <select x-model="schedule.dayOfMonth"
                                    class="border border-line rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
                                <template x-for="d in Array.from({length:31},(_,i)=>i+1)" :key="d">
                                    <option :value="d" x-text="d"></option>
                                </template>
                            </select>
                        </div>
                    </template>

                    <div class="flex items-center gap-2">
                        <label class="text-sm text-muted whitespace-nowrap">В колко часа:</label>
                        <select x-model="schedule.hour"
                                class="border border-line rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/40">
                            <template x-for="h in Array.from({length:24},(_,i)=>i)" :key="h">
                                <option :value="h" x-text="String(h).padStart(2,'0') + ':00'"></option>
                            </template>
                        </select>
                    </div>
                </div>

                {{-- Human-readable summary --}}
                <div x-show="schedule.preset !== 'none'" x-cloak
                     class="bg-surface-subtle border border-line rounded-lg px-3 py-2 text-xs text-muted flex items-center gap-2 mb-2">
                    <x-icon name="clock" size="4" class="text-subtle shrink-0" />
                    <span>
                        <template x-if="schedule.preset === 'hourly'">
                            <span>Ще се изпълнява <strong class="text-ink">всеки час</strong></span>
                        </template>
                        <template x-if="schedule.preset === 'daily'">
                            <span>Ще се изпълнява <strong class="text-ink">всеки ден в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                        </template>
                        <template x-if="schedule.preset === 'weekly'">
                            <span>Ще се изпълнява <strong class="text-ink">всяка седмица в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                        </template>
                        <template x-if="schedule.preset === 'monthly'">
                            <span>Ще се изпълнява <strong class="text-ink">всеки месец на <span x-text="schedule.dayOfMonth"></span>-ти в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
                        </template>
                        <template x-if="schedule.preset === 'custom'">
                            <span>Cron: <code class="font-mono" x-text="schedule.customCron"></code></span>
                        </template>
                        · <code class="font-mono text-subtle" x-text="cronValue"></code>
                    </span>
                </div>

                {{-- Advanced / custom cron --}}
                <button type="button" @click="schedule.showCustom = !schedule.showCustom; if(schedule.showCustom) schedule.preset = 'custom'"
                        class="inline-flex items-center gap-1 text-xs text-subtle hover:text-muted underline transition">
                    <x-icon name="cog-6-tooth" size="3.5" /> По избор (напреднали)
                </button>
                <div x-show="schedule.showCustom" x-cloak class="mt-2">
                    <input type="text" x-model="schedule.customCron"
                           placeholder="напр. 0 10 * * 1-5"
                           class="w-full border border-line rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary/40">
                    <p class="text-xs text-subtle mt-1">Стандартен cron синтаксис: минута час ден-от-месец месец ден-от-седмица</p>
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <x-button type="submit">Запази</x-button>
                <x-button variant="secondary" :href="route('flows.show', $flow)">Откажи</x-button>
            </div>
        </form>
    </div>
</div>

<script>
const FLOW_EDIT_INITIAL_NAME = @json(old('name', $flow->name));
const FLOW_EDIT_INITIAL_DESCRIPTION = @json(old('description', $flow->description));
const FLOW_EDIT_INITIAL_CRON = @json(old('schedule_cron', $flow->schedule_cron));
const FLOW_EDIT_COMPANY_ID = @json($flow->company_id);
const FLOW_IMPROVE_DESCRIPTION_URL = @json(route('flows.improve-description'));

function flowEditForm() {
    const state = scheduleEditor(FLOW_EDIT_INITIAL_CRON);

    return Object.assign(state, {
        flowName: FLOW_EDIT_INITIAL_NAME || '',
        flowDescription: FLOW_EDIT_INITIAL_DESCRIPTION || '',
        isImproving: false,
        improvedDescription: '',
        showImprovePreview: false,

        async improveDescription() {
            if (!this.flowDescription.trim()) return;

            this.isImproving = true;
            this.showImprovePreview = false;
            this.improvedDescription = '';

            const csrf = document.querySelector('meta[name="csrf-token"]').content;

            try {
                const resp = await fetch(FLOW_IMPROVE_DESCRIPTION_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        description: this.flowDescription,
                        name: this.flowName,
                        company_id: FLOW_EDIT_COMPANY_ID,
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
            this.improvedDescription = '';
        },
    });
}

function scheduleEditor(existingCron) {
    return {
        schedule: { preset: 'none', hour: '10', dayOfWeek: '1', dayOfMonth: '1', customCron: '', showCustom: false },

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
            if (!existingCron) return;
            const parts = existingCron.trim().split(/\s+/);
            if (parts.length !== 5) {
                this.schedule.preset = 'custom';
                this.schedule.customCron = existingCron;
                this.schedule.showCustom = true;
                return;
            }
            const [min, hour, dom, month, dow] = parts;
            if (min==='0' && hour==='*' && dom==='*' && month==='*' && dow==='*') {
                this.schedule.preset = 'hourly'; return;
            }
            if (min==='0' && dom==='*' && month==='*' && dow==='*') {
                this.schedule.preset = 'daily'; this.schedule.hour = hour; return;
            }
            if (min==='0' && dom==='*' && month==='*') {
                this.schedule.preset = 'weekly'; this.schedule.hour = hour; this.schedule.dayOfWeek = dow; return;
            }
            if (min==='0' && month==='*' && dow==='*') {
                this.schedule.preset = 'monthly'; this.schedule.hour = hour; this.schedule.dayOfMonth = dom; return;
            }
            this.schedule.preset = 'custom';
            this.schedule.customCron = existingCron;
            this.schedule.showCustom = true;
        },
    };
}
</script>
@endsection
