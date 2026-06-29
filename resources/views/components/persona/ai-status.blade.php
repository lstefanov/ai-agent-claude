{{-- Inline „в момента генерира" индикатор за едно персона-поле. Изисква обграждащ
     Alpine компонент със споделения personaFormBase helper (aiBusy). Prop: field. --}}
@props(['field'])
<span x-show="aiBusy('{{ $field }}')" x-cloak
      class="ml-auto inline-flex items-center gap-1 text-[11px] font-medium text-primary">
    <x-icon name="arrow-path" size="3" class="animate-spin" />Генерирам…
</span>
