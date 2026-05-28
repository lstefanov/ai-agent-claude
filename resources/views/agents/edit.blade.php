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
            <label class="block text-sm font-medium text-gray-700 mb-1">Роля / System prompt</label>
            <textarea name="role" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      required>{{ old('role', $agent->role) }}</textarea>
            @error('role') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Промпт шаблон</label>
            <p class="text-xs text-gray-400 mb-1">Използвай <code class="bg-gray-100 px-1 rounded">@{{AgentName}}</code> за контекст от предишен агент</p>
            <textarea name="prompt_template" rows="8"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      required>{{ old('prompt_template', $agent->prompt_template) }}</textarea>
            @error('prompt_template') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Модел</label>
            <select name="model" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                @foreach($models->groupBy('category') as $category => $group)
                    <optgroup label="{{ strtoupper($category) }}">
                        @foreach($group as $m)
                            <option value="{{ $m->ollama_tag }}" {{ old('model', $agent->model) === $m->ollama_tag ? 'selected' : '' }}>
                                {{ $m->display_name }}{{ !$m->is_available ? ' (недостъпен)' : '' }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @error('model') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
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
        <p class="text-xs text-gray-500 bg-indigo-50 rounded-lg px-3 py-2">
            Оставените <strong>празни полета</strong> използват стойностите по подразбиране на модела.
            Подходящи стойности зависят от задачата — виж <a href="https://github.com/ollama/ollama/blob/main/docs/modelfile.md#valid-parameters-and-values" target="_blank" class="text-indigo-600 hover:underline">Ollama docs</a>.
        </p>

        <div class="grid grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Temperature
                    <span class="text-xs font-normal text-gray-400">(0 – 2, default: 0.7)</span>
                </label>
                <input type="number" name="config[temperature]" step="0.05" min="0" max="2"
                       value="{{ old('config.temperature', $cfg['temperature'] ?? '') }}"
                       placeholder="0.7"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <p class="text-xs text-gray-400 mt-1">Ниско = детерминиран. Високо = творчески.</p>
                @error('config.temperature') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Top P
                    <span class="text-xs font-normal text-gray-400">(0 – 1, default: 0.9)</span>
                </label>
                <input type="number" name="config[top_p]" step="0.05" min="0" max="1"
                       value="{{ old('config.top_p', $cfg['top_p'] ?? '') }}"
                       placeholder="0.9"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <p class="text-xs text-gray-400 mt-1">Nucleus sampling — ограничава токените.</p>
                @error('config.top_p') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Top K
                    <span class="text-xs font-normal text-gray-400">(1 – 200, default: 40)</span>
                </label>
                <input type="number" name="config[top_k]" step="1" min="1" max="200"
                       value="{{ old('config.top_k', $cfg['top_k'] ?? '') }}"
                       placeholder="40"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <p class="text-xs text-gray-400 mt-1">Брой токени за разглеждане при всяка стъпка.</p>
                @error('config.top_k') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Repeat Penalty
                    <span class="text-xs font-normal text-gray-400">(0 – 2, default: 1.1)</span>
                </label>
                <input type="number" name="config[repeat_penalty]" step="0.05" min="0" max="2"
                       value="{{ old('config.repeat_penalty', $cfg['repeat_penalty'] ?? '') }}"
                       placeholder="1.1"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <p class="text-xs text-gray-400 mt-1">> 1 намалява повторенията в текста.</p>
                @error('config.repeat_penalty') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Max Tokens (num_predict)
                    <span class="text-xs font-normal text-gray-400">(-1 = без лимит, default: -1)</span>
                </label>
                <input type="number" name="config[num_predict]" step="1" min="-1"
                       value="{{ old('config.num_predict', $cfg['num_predict'] ?? '') }}"
                       placeholder="-1"
                       class="w-64 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <p class="text-xs text-gray-400 mt-1">Ограничава дължината на отговора в токени.</p>
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
