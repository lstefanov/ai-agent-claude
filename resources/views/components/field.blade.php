@props([
    'label' => null,
    'name' => null,
    'for' => null,
    'required' => false,
    'help' => null,
])
@php
    $for = $for ?? $name;
    $hasError = $name && $errors->has($name);
@endphp
<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    @if($label)
        <label @if($for) for="{{ $for }}" @endif class="block text-sm font-medium text-ink">
            {{ $label }}@if($required)<span class="text-danger ml-0.5" aria-hidden="true">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if($hasError)
        <p class="text-xs text-danger flex items-center gap-1">
            <x-icon name="exclamation-circle" size="3.5" />{{ $errors->first($name) }}
        </p>
    @elseif($help)
        <p class="text-xs text-subtle">{{ $help }}</p>
    @endif
</div>
