@props([
    'icon' => 'inbox',
    'title' => null,
    'message' => null,
    'description' => null,   // alias на message (повечето call sites ползват description=)
])
@php($body = $message ?? $description)
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center py-12 px-6']) }}>
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-surface-subtle border border-line text-subtle mb-4">
        <x-icon :name="$icon" size="6" />
    </div>
    @if($title)
        <p class="text-sm font-medium text-ink">{{ $title }}</p>
    @endif
    @if($body)
        <p class="mt-1 text-sm text-muted max-w-sm">{{ $body }}</p>
    @endif
    @if(isset($slot) && trim($slot) !== '')
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
