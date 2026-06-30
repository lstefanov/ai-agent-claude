@props([
    'busy' => 'busy',
    'loadingText' => null,
    'size' => 'md',
    'variant' => 'primary',
    'type' => 'button',
    'href' => null,
    'spinner' => true,
])
@php
    $spinnerSize = $size === 'sm' ? 14 : 16;
    $disabledAttr = $attributes->get('x-bind:disabled') ?? $attributes->get(':disabled');
    $disabledExpr = $disabledAttr
        ? "({$busy}) || ({$disabledAttr})"
        : $busy;
    $label = $loadingText ?? trim(strip_tags((string) $slot));
@endphp
<x-button
    :type="$type"
    :size="$size"
    :variant="$variant"
    :href="$href"
    {{ $attributes->except(['x-bind:disabled', ':disabled']) }}
    x-bind:disabled="{{ $disabledExpr }}"
    x-bind:aria-busy="{{ $busy }} ? 'true' : 'false'"
>
    <span x-show="!{{ $busy }}">{{ $slot }}</span>
    <span x-show="{{ $busy }}" x-cloak class="inline-flex items-center gap-2">
        @if ($spinner)
            <x-org.bolt-spinner :size="$spinnerSize" />
        @endif
        <span>{{ $label }}</span>
    </span>
</x-button>
