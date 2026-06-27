@props([
    'label' => 'Мисля…',   // fallback етикет, когато няма (или е празно) `stage`
    'expr' => 'stage',      // Alpine израз за динамичния етикет (фаза)
    'static' => false,      // true → показва $label без Alpine (за места без `stage`)
    'size' => 22,
])
{{-- Единният „мислещ" индикатор: анимирано лого + контекстен етикет
     (мисля… / генерирам съдържание…). Стои в асистентския балон. --}}
<div class="flex justify-start">
    <div class="bg-surface-subtle text-muted rounded-2xl rounded-bl-sm px-4 py-2 text-sm inline-flex items-center gap-2">
        <x-org.bolt-spinner :size="$size" />
        @if ($static)
            <span>{{ $label }}</span>
        @else
            <span x-text="({{ $expr }}) || @js($label)"></span>
        @endif
    </div>
</div>
