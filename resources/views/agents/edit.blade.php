@extends('layouts.app')

@section('title', 'Редактирай агент — ' . $agent->name)

@php
$tones   = ['Neutral','Friendly','Formal','Cold','Analytical','Creative','Ironic','Persuasive','Empathetic','Authoritative'];
$styles  = ['Professional','Academic','Creative','Critical','Journalistic','Conversational','Technical','Narrative','Minimalist','Descriptive'];
$formats = ['Report','Blog post','Brochure','Proposal','Email','Social media post','Press release','Newsletter','Product description','Executive summary','FAQ','Listicle'];
$langs   = ['bg' => 'Български', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'ru' => 'Русский'];

$cfg     = $agent->config ?? [];
@endphp

@section('content')
<div class="mb-6">
    <a href="{{ route('flows.show', $flow) }}" class="text-indigo-600 hover:underline text-sm">
        ← {{ $flow->name }}
    </a>
    <h1 class="text-3xl font-bold text-gray-900 mt-2">Редактирай агент</h1>
    <p class="text-gray-500 mt-1">{{ $agent->name }}
        <span class="text-xs font-mono bg-gray-100 text-gray-500 px-2 py-0.5 rounded ml-1">{{ $agent->type }}</span>
    </p>
</div>

<form action="{{ route('agents.update', [$flow, $agent]) }}" method="POST" class="space-y-6 max-w-3xl" x-data="{ tab: 'basic' }">
    @csrf
    @method('PUT')

    {{-- Tab nav --}}
    <div class="flex gap-1 border-b border-gray-200">
        @foreach(['basic' => '⚙ Основни', 'output' => '🎨 Output', 'params' => '🎛 Параметри'] as $key => $label)
        <button type="button" @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'border-b-2 border-indigo-600 text-indigo-600 font-medium' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 text-sm transition -mb-px">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- ── TAB: ОСНОВНИ ─────────────────────────────────────────── --}}
    <div x-show="tab === 'basic'" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Име</label>
            <input type="text" name="name" value="{{ old('name', $agent->name) }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-sm font-medium text-gray-700">Роля / Описание</label>
                <button type="button" onclick="generateAgentField('role', this)"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 transition disabled:opacity-40">
                    ✨ Генерирай с AI
                </button>
            </div>
            <textarea name="role" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      required>{{ old('role', $agent->role) }}</textarea>
            @error('role') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-sm font-medium text-gray-700">System промпт</label>
                <button type="button" onclick="generateAgentField('system_prompt', this)"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 transition disabled:opacity-40">
                    ✨ Генерирай с AI
                </button>
            </div>
            <p class="text-xs text-gray-400 mb-1">Описва ролята и поведението на агента. Инжектира се автоматично при всяко изпълнение.</p>
            <textarea name="system_prompt" rows="4"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder="Ти си специализиран агент за...">{{ old('system_prompt', $agent->system_prompt) }}</textarea>
            @error('system_prompt') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-sm font-medium text-gray-700">Промпт шаблон</label>
                <button type="button" onclick="generateAgentField('prompt_template', this)"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 transition disabled:opacity-40">
                    ✨ Генерирай с AI
                </button>
            </div>
            <p class="text-xs text-gray-400 mb-1">Използвай <code class="bg-gray-100 px-1 rounded">@{{AgentName}}</code> за контекст от предишен агент</p>
            <textarea name="prompt_template" rows="8"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      required>{{ old('prompt_template', $agent->prompt_template) }}</textarea>
            @error('prompt_template') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Модел</label>
            <select name="model" id="agent-model-select">
                <option value="{{ old('model', $agent->model) }}" selected>{{ old('model', $agent->model) }}</option>
            </select>
            @error('model') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            <script>
            (function () {
                const models   = @json($models);
                const agentType = '{{ $agent->type }}';
                const current  = '{{ old('model', $agent->model) }}';

                const recommended = models.filter(m => (m.is_default_for || []).includes(agentType));
                const others      = models.filter(m => !(m.is_default_for || []).includes(agentType));

                const options    = [];
                const optgroups  = [];

                if (recommended.length) {
                    optgroups.push({ value: 'recommended', label: 'Препоръчани за ' + agentType });
                    recommended.forEach(m => options.push({ value: m.ollama_tag, text: m.display_name, description: m.description || '', optgroup: 'recommended' }));
                }
                if (others.length) {
                    optgroups.push({ value: 'others', label: 'Останали' });
                    others.forEach(m => options.push({ value: m.ollama_tag, text: m.display_name, description: m.description || '', optgroup: 'others' }));
                }

                new TomSelect('#agent-model-select', {
                    options,
                    optgroups,
                    optgroupField: 'optgroup',
                    items: [current],
                    maxItems: 1,
                    create: false,
                    render: {
                        option: (data, escape) =>
                            `<div class="py-0.5">
                                <div class="font-medium text-gray-800">${escape(data.text)}</div>
                                ${data.description ? `<div class="text-xs text-gray-400 mt-0.5 leading-tight">${escape(data.description)}</div>` : ''}
                            </div>`,
                        item: (data, escape) =>
                            `<div>${escape(data.text)}</div>`,
                    },
                });
            })();
            </script>
        </div>

        @if($agent->is_verifier)
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">QA праг (%)</label>
            <input type="number" name="qa_threshold" min="0" max="100"
                   value="{{ old('qa_threshold', $agent->qa_threshold) }}"
                   class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        @endif

        <div class="flex items-center gap-3">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" id="is_active" value="1"
                   {{ old('is_active', $agent->is_active) ? 'checked' : '' }}
                   class="w-4 h-4 text-indigo-600 border-gray-300 rounded">
            <label for="is_active" class="text-sm font-medium text-gray-700">Активен</label>
        </div>

        @if(!$agent->is_verifier)
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Роля в изхода</label>
            <select name="output_role" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">{{ '(авто от тип)' }}</option>
                <option value="body" {{ old('output_role', $agent->output_role) === 'body' ? 'selected' : '' }}>Основно съдържание</option>
                <option value="appendix" {{ old('output_role', $agent->output_role) === 'appendix' ? 'selected' : '' }}>Добавка (хаштагове, SEO)</option>
                <option value="hidden" {{ old('output_role', $agent->output_role) === 'hidden' ? 'selected' : '' }}>Скрит (изследване, анализ)</option>
            </select>
            @error('output_role') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        @endif
    </div>

    {{-- ── TAB: OUTPUT PREFERENCES ──────────────────────────────── --}}
    <div x-show="tab === 'output'" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <p class="text-xs text-gray-500 bg-indigo-50 rounded-lg px-3 py-2">
            Тези настройки се <strong>инжектират автоматично</strong> в system prompt-а на агента при изпълнение.
            Не е нужно да ги пишеш ръчно в промпта.
        </p>

        <div class="grid grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Език на изхода</label>
                <select name="output_language" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    @foreach($langs as $code => $label)
                        <option value="{{ $code }}" {{ old('output_language', $agent->output_language ?? 'bg') === $code ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Тон</label>
                <select name="output_tone" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">— без предпочитание —</option>
                    @foreach($tones as $tone)
                        <option value="{{ $tone }}" {{ old('output_tone', $agent->output_tone) === $tone ? 'selected' : '' }}>
                            {{ $tone }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Стил</label>
                <select name="output_style" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">— без предпочитание —</option>
                    @foreach($styles as $style)
                        <option value="{{ $style }}" {{ old('output_style', $agent->output_style) === $style ? 'selected' : '' }}>
                            {{ $style }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Формат</label>
                <select name="output_format" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">— без предпочитание —</option>
                    @foreach($formats as $format)
                        <option value="{{ $format }}" {{ old('output_format', $agent->output_format) === $format ? 'selected' : '' }}>
                            {{ $format }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Preview of what gets injected --}}
        <div class="bg-gray-50 rounded-lg p-3 text-xs text-gray-500 font-mono space-y-1 border border-gray-200">
            <p class="font-semibold text-gray-400 uppercase tracking-widest text-[10px] mb-2">Ще бъде добавено към system prompt:</p>
            <p>Language: Always respond in <strong>{{ $langs[old('output_language', $agent->output_language ?? 'bg')] ?? 'Bulgarian' }}</strong>.</p>
            @if($agent->output_tone) <p>Tone: Use a <strong>{{ strtolower($agent->output_tone) }}</strong> tone.</p> @endif
            @if($agent->output_style) <p>Style: Write in a <strong>{{ strtolower($agent->output_style) }}</strong> style.</p> @endif
            @if($agent->output_format) <p>Format: Structure your response as a <strong>{{ strtolower($agent->output_format) }}</strong>.</p> @endif
        </div>
    </div>

    {{-- ── TAB: ПАРАМЕТРИ ───────────────────────────────────────── --}}
    <div x-show="tab === 'params'" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-950 space-y-2">
            <h2 class="font-semibold text-indigo-900">Как да мислим за тези параметри</h2>
            <p>
                Оставените <strong>празни полета</strong> използват стойностите по подразбиране на модела.
                Променяй по една настройка наведнъж, защото Temperature, Top P и Top K заедно контролират колко свободно моделът избира следващия токен.
            </p>
            <p class="text-xs text-indigo-700">
                За точни QA/verifier или research агенти дръж стойностите по-консервативни. За creative writer, идеи и маркетинг можеш да дадеш повече свобода.
                Виж и <a href="https://github.com/ollama/ollama/blob/main/docs/modelfile.md#valid-parameters-and-values" target="_blank" class="font-medium text-indigo-700 hover:underline">Ollama docs</a>.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Temperature
                    <span class="text-xs font-normal text-gray-400">(0 – 2, default: 0.7)</span>
                </label>
                <input type="number" name="config[temperature]" step="0.05" min="0" max="2"
                       value="{{ old('config.temperature', $cfg['temperature'] ?? '') }}"
                       placeholder="0.7"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <div class="rounded-lg bg-white border border-gray-200 p-3 text-xs text-gray-600 space-y-1">
                    <p><strong class="text-gray-700">Какво прави:</strong> контролира колко смело моделът избира следващата дума.</p>
                    <p><strong class="text-gray-700">Ниско: по-предвидими и повторяеми отговори</strong>, подходящи за факти, проверки и структурирани задачи.</p>
                    <p><strong class="text-gray-700">Високо: повече разнообразие</strong>, идеи и по-креативен стил, но и по-голям риск от отклонения.</p>
                </div>
                @error('config.temperature') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Top P
                    <span class="text-xs font-normal text-gray-400">(0 – 1, default: 0.9)</span>
                </label>
                <input type="number" name="config[top_p]" step="0.05" min="0" max="1"
                       value="{{ old('config.top_p', $cfg['top_p'] ?? '') }}"
                       placeholder="0.9"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <div class="rounded-lg bg-white border border-gray-200 p-3 text-xs text-gray-600 space-y-1">
                    <p><strong class="text-gray-700">Какво прави:</strong> Top P избира най-вероятните токени, докато общата им вероятност стигне зададения праг.</p>
                    <p><strong class="text-gray-700">По-ниско:</strong> моделът избира от по-малък и по-сигурен набор, което помага за последователност.</p>
                    <p><strong class="text-gray-700">По-високо:</strong> позволява по-богат речник и повече вариации, особено при писане на съдържание.</p>
                </div>
                @error('config.top_p') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Top K
                    <span class="text-xs font-normal text-gray-400">(1 – 200, default: 40)</span>
                </label>
                <input type="number" name="config[top_k]" step="1" min="1" max="200"
                       value="{{ old('config.top_k', $cfg['top_k'] ?? '') }}"
                       placeholder="40"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <div class="rounded-lg bg-white border border-gray-200 p-3 text-xs text-gray-600 space-y-1">
                    <p><strong class="text-gray-700">Какво прави:</strong> Top K поставя твърд лимит колко възможни токена се разглеждат на всяка стъпка.</p>
                    <p><strong class="text-gray-700">По-ниско:</strong> по-стегнат и безопасен избор, но текстът може да стане по-еднообразен.</p>
                    <p><strong class="text-gray-700">По-високо:</strong> повече варианти за модела, което е полезно за творчески задачи.</p>
                </div>
                @error('config.top_k') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Repeat Penalty
                    <span class="text-xs font-normal text-gray-400">(0 – 2, default: 1.1)</span>
                </label>
                <input type="number" name="config[repeat_penalty]" step="0.05" min="0" max="2"
                       value="{{ old('config.repeat_penalty', $cfg['repeat_penalty'] ?? '') }}"
                       placeholder="1.1"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <div class="rounded-lg bg-white border border-gray-200 p-3 text-xs text-gray-600 space-y-1">
                    <p><strong class="text-gray-700">Какво прави:</strong> Repeat Penalty наказва вече използвани думи и фрази, за да намали повтарянето.</p>
                    <p><strong class="text-gray-700">Около 1.0:</strong> почти без наказание, моделът може да повтаря фрази, ако задачата го подтикне.</p>
                    <p><strong class="text-gray-700">Над 1.0:</strong> помага при дълги отговори, но твърде висока стойност може да направи текста неестествен.</p>
                </div>
                @error('config.repeat_penalty') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3 md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Max Tokens (num_predict)
                    <span class="text-xs font-normal text-gray-400">(-1 = без лимит, default: -1)</span>
                </label>
                <input type="number" name="config[num_predict]" step="1" min="-1"
                       value="{{ old('config.num_predict', $cfg['num_predict'] ?? '') }}"
                       placeholder="-1"
                       class="w-64 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <div class="rounded-lg bg-white border border-gray-200 p-3 text-xs text-gray-600 space-y-1">
                    <p><strong class="text-gray-700">Какво прави:</strong> num_predict задава горна граница за дължината на отговора в токени.</p>
                    <p><strong class="text-gray-700">По-ниско:</strong> пази от прекалено дълги отговори и ускорява изпълнението, но може да отреже важен текст.</p>
                    <p><strong class="text-gray-700">-1:</strong> оставя модела без този лимит; използвай го само когато очакваш дълъг доклад или анализ.</p>
                </div>
                @error('config.num_predict') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Submit --}}
    <div class="flex items-center gap-3">
        <button type="submit"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">
            Запази промените
        </button>
        <a href="{{ route('flows.show', $flow) }}" class="text-gray-500 hover:text-gray-700 text-sm">Отказ</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
window.FLOW_DESCRIPTION = @json($flow->description ?? '');

async function generateAgentField(fieldName, btn) {
    const form = btn.closest('form');
    const nameInput = form.querySelector('[name="name"]');
    if (!nameInput || !nameInput.value.trim()) {
        alert('Въведи първо името на агента.');
        return;
    }
    const textarea = form.querySelector('[name="' + fieldName + '"]');
    if (!textarea) return;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Генерира...';
    try {
        const resp = await fetch('/ai/generate-agent-field', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                field:            fieldName,
                agent_name:       nameInput.value.trim(),
                agent_type:       (form.querySelector('[name="type"]') || {}).value || '',
                flow_description: window.FLOW_DESCRIPTION || '',
                role:             (form.querySelector('[name="role"]') || {}).value || '',
                system_prompt:    (form.querySelector('[name="system_prompt"]') || {}).value || '',
                prompt_template:  (form.querySelector('[name="prompt_template"]') || {}).value || '',
            }),
        });
        const data = await resp.json();
        if (!resp.ok) {
            alert(data.error || 'Грешка при AI генерация. Провери дали Ollama работи.');
        } else if (data.generated) {
            textarea.value = data.generated;
        }
    } catch (e) {
        console.error('generateAgentField error', e);
        alert('Мрежова грешка при AI генерация.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}
</script>
@endpush
