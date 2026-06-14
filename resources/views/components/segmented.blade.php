@props([
    'name',
    'options' => [],
    'value' => null,
])
{{-- Segmented control (e.g. model-cost level). Writes a hidden input; :class uses literal
     static strings so Tailwind sees them — no dynamically-built utility names. --}}
<div x-data="{ val: @js((string) $value) }"
     {{ $attributes->merge(['class' => 'inline-flex rounded-lg border border-line bg-surface-subtle p-0.5']) }}
     role="radiogroup">
    <input type="hidden" name="{{ $name }}" :value="val">
    @foreach($options as $val => $label)
        <button type="button"
                @click="val = @js((string) $val)"
                :aria-checked="val === @js((string) $val)"
                role="radio"
                :class="val === @js((string) $val)
                    ? 'bg-surface text-ink shadow-card'
                    : 'text-muted hover:text-ink'"
                class="px-3 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
            {{ $label }}
        </button>
    @endforeach
</div>
