{{-- Споделени персона-полета (casting/member/design-review). Bind-ват се към обект
     с конфигурируем префикс: $modelPrefix (по подразбиране 'form'). Текстовите полета
     имат ⓘ помощ + ✨ генериране; всяка черта има ⓘ описание. Изисква обграждащ Alpine компонент,
     който spread-ва window.personaFormBase(cfg).
     Опции: $withNames=true → добавя name-атрибути за нативен form POST (casting); без него
     полетата се записват през JS. Възрастта подсказва черти само където има reseed() (casting). --}}
@php($m = $modelPrefix ?? 'form')
@php($names = $withNames ?? false)
{{-- Цвят на чертите = цветът на отдела (char-* токен). Подава се през $color; иначе
     обектът може да носи `.color` (design-review го сетва per домейн). Fallback: purple. --}}
@php($defaultColor = $color ?? 'purple')
@include('client.org._persona-form-js')

{{-- Име (2/3) + Пол (1/3) --}}
<div class="grid grid-cols-3 gap-3">
    <div class="col-span-2">
        <div class="flex items-center gap-1.5 mb-1">
            <label class="text-sm font-medium text-ink">Име <span class="text-danger" aria-hidden="true">*</span></label>
            <x-persona.help-popover :meta="config('persona.fields.name')" />
            <x-persona.ai-fill field="name" />
        </div>
        <x-input :name="$names ? 'name' : null" x-model="{{ $m }}.name" required maxlength="80" placeholder="напр. Алекс Иванов" />
    </div>
    <div>
        <label class="block text-sm font-medium text-ink mb-1">Пол</label>
        <x-select :name="$names ? 'gender' : null" x-model="{{ $m }}.gender">
            <option value="">—</option>
            <option value="мъж">Мъж</option>
            <option value="жена">Жена</option>
        </x-select>
    </div>
</div>

{{-- Възраст + Произход --}}
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="block text-sm font-medium text-ink mb-1">Възраст</label>
        <x-input type="number" :name="$names ? 'age' : null" x-model.number="{{ $m }}.age" min="18" max="90" placeholder="28"
                 x-on:input="typeof reseed === 'function' && reseed()" />
    </div>
    <div>
        <div class="flex items-center gap-1.5 mb-1">
            <label class="text-sm font-medium text-ink">{{ config('persona.fields.ethnicity.label') }}</label>
            <x-persona.help-popover :meta="config('persona.fields.ethnicity')" />
            <x-persona.ai-fill field="ethnicity" />
        </div>
        <x-input :name="$names ? 'ethnicity' : null" x-model="{{ $m }}.ethnicity" maxlength="40"
                 placeholder="напр. {{ config('persona.fields.ethnicity.examples.0') }}" />
    </div>
</div>

{{-- Професионален опит (ⓘ помощ + ✨ генериране) --}}
<div>
    <div class="flex items-center gap-1.5 mb-1">
        <label class="text-sm font-medium text-ink">{{ config('persona.fields.background.label') }}</label>
        <x-persona.help-popover :meta="config('persona.fields.background')" />
        <x-persona.ai-fill field="background" />
    </div>
    <x-textarea :name="$names ? 'background' : null" x-model="{{ $m }}.background" rows="3" maxlength="120"
                placeholder="напр. {{ config('persona.fields.background.examples.0') }}" />
</div>

{{-- Тон --}}
<div>
    <div class="flex items-center gap-1.5 mb-1">
        <label class="text-sm font-medium text-ink">Тон</label>
        <x-persona.help-popover :meta="config('persona.fields.tone')" />
        <x-persona.ai-fill field="tone" />
    </div>
    <x-textarea :name="$names ? 'tone' : null" x-model="{{ $m }}.tone" rows="2" maxlength="80"
                placeholder="напр. {{ config('persona.fields.tone.examples.0') }}" />
</div>

{{-- Кратко био --}}
<div>
    <div class="flex items-center gap-1.5 mb-1">
        <label class="text-sm font-medium text-ink">Кратко био</label>
        <x-persona.help-popover :meta="config('persona.fields.bio')" />
        <x-persona.ai-fill field="bio" />
    </div>
    <x-textarea :name="$names ? 'bio' : null" x-model="{{ $m }}.bio" rows="5" maxlength="600" placeholder="Един-два реда за характера." />
</div>

{{-- Черти — server-rendered от config; ⓘ за всяка; стойност от {{ $m }}.traits.<key> --}}
<div>
    <p class="text-xs font-medium text-muted mb-2">Черти <span class="text-subtle">(оформят подхода и тона — ⓘ обяснява всяка)</span></p>
    <div class="space-y-2.5">
        @foreach (config('persona.traits') as $key => $tmeta)
            <div>
                <div class="flex items-center gap-1.5 text-xs mb-0.5">
                    <span class="text-ink">{{ $tmeta['label'] }}</span>
                    <x-persona.trait-info :meta="$tmeta" />
                    <span class="ml-auto tabular-nums text-muted" x-text="{{ $m }}.traits.{{ $key }}"></span>
                </div>
                <input type="range" min="0" max="100" x-model.number="{{ $m }}.traits.{{ $key }}"
                       @if ($names) name="traits[{{ $key }}]" @endif
                       class="w-full"
                       :style="'accent-color: var(--color-char-' + (({{ $m }}.color) || '{{ $defaultColor }}') + ')'">
            </div>
        @endforeach
    </div>
</div>

{{-- Грешка от генерирането --}}
<p x-show="aiError" x-text="aiError" x-cloak class="text-xs text-danger"></p>
