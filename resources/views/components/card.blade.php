@props([
    'header' => null,
    'footer' => null,
    'padding' => true,
])
<div {{ $attributes->merge(['class' => 'bg-surface border border-line rounded-xl shadow-card']) }}>
    @isset($header)
        <div class="px-5 py-4 border-b border-line">{{ $header }}</div>
    @endisset
    <div class="{{ $padding ? 'p-5' : '' }}">{{ $slot }}</div>
    @isset($footer)
        <div class="px-5 py-4 border-t border-line bg-surface-subtle rounded-b-xl">{{ $footer }}</div>
    @endisset
</div>
