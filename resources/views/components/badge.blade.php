@props([
    'color' => 'neutral',
    'icon' => null,
    'pulse' => false,
])
@php
    // Static class strings (PHP match-map) so Tailwind's scanner sees every utility.
    $map = [
        'success' => 'bg-success-soft text-success-strong',
        'warning' => 'bg-warning-soft text-warning-strong',
        'danger'  => 'bg-danger-soft text-danger-strong',
        'info'    => 'bg-info-soft text-info-strong',
        'accent'  => 'bg-accent-soft text-accent-strong',
        'neutral' => 'bg-neutral-soft text-neutral-strong',
    ];
    $tone = $map[$color] ?? $map['neutral'];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-md $tone"]) }}>
    @if($icon)
        <x-icon :name="$icon" size="3.5" :class="$pulse ? 'animate-pulse' : ''" />
    @endif
    {{ $slot }}
</span>
