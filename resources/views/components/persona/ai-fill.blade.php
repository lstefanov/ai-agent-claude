{{-- ✨ Генериране с изкуствен интелект на едно поле. Изисква обграждащ Alpine компонент със
     споделения personaFormBase helper (aiFill/aiBusy). Prop: field (ключ). --}}
@props(['field'])
<button type="button" @click="aiFill('{{ $field }}')" :disabled="aiBusy('{{ $field }}')"
        class="inline-flex items-center justify-center text-subtle hover:text-primary transition disabled:opacity-50 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
        title="Генерирай с изкуствен интелект" aria-label="Генерирай с изкуствен интелект">
    <x-icon name="sparkles" size="4" x-show="! aiBusy('{{ $field }}')" />
    <x-icon name="arrow-path" size="4" class="animate-spin" x-show="aiBusy('{{ $field }}')" x-cloak />
</button>
