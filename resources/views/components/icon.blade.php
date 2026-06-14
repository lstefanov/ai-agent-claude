@props(['name', 'variant' => 'o', 'size' => 5])
{{-- Single icon set: Heroicons (outline by default) via blade-heroicons.
     Size is a prop (one static w-/h- pair) so Tailwind never sees a built-up class string.
     All other attributes (x-show, x-cloak, :class result, etc.) are forwarded to the <svg>. --}}
@php
    $classes = trim('w-'.$size.' h-'.$size.' '.(string) $attributes->get('class'));
    $rest = $attributes->except('class')->getAttributes();
    $rest['aria-hidden'] = $rest['aria-hidden'] ?? 'true';
@endphp
@svg('heroicon-'.$variant.'-'.$name, $classes, $rest)
