@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'loading' => false,
])
@php
    $variants = [
        'primary'   => 'bg-primary text-primary-fg hover:bg-primary-hover',
        'secondary' => 'bg-surface text-ink border border-line-strong hover:bg-surface-subtle',
        'ghost'     => 'text-muted hover:text-ink hover:bg-surface-subtle',
        'danger'    => 'bg-danger text-white hover:opacity-90',
        'danger-outline' => 'bg-surface text-danger border border-danger/30 hover:bg-danger-soft hover:border-danger/50',
    ];
    $sizes = [
        'sm' => 'h-8 px-3 text-xs gap-1.5',
        'md' => 'h-10 px-4 text-sm gap-2',
    ];
    $base = 'inline-flex items-center justify-center font-medium rounded-md transition '
        .'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 '
        .'disabled:opacity-60 disabled:pointer-events-none';
    $classes = trim($base.' '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']));
    $tag = $href ? 'a' : 'button';
@endphp
<{{ $tag }}
    @if($href) href="{{ $href }}" @else type="{{ $type }}" @disabled($loading) @endif
    {{ $attributes->merge(['class' => $classes]) }}
    @if($loading) aria-busy="true" @endif
>
    @if($loading)
        <x-icon name="arrow-path" :size="$size === 'sm' ? '3.5' : '4'" class="animate-spin" />
    @elseif($icon)
        <x-icon :name="$icon" :size="$size === 'sm' ? '3.5' : '4'" />
    @endif
    {{ $slot }}
</{{ $tag }}>
