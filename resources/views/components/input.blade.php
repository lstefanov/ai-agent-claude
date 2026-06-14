@props([
    'name' => null,
    'type' => 'text',
])
@php
    $hasError = $name && $errors->has($name);
    $border = $hasError
        ? 'border-danger focus:border-danger focus:ring-danger/30'
        : 'border-line hover:border-line-strong focus:border-primary focus:ring-primary/30';
@endphp
<input
    type="{{ $type }}"
    @if($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif
    {{ $attributes->merge(['class' => "w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink placeholder:text-subtle transition focus:outline-none focus:ring-2 $border"]) }}
/>
