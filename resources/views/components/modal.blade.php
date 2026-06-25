@props([
    'name',
    'title' => null,
    'maxWidth' => 'lg',
])
@php
    $widths = ['sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl'];
    $w = $widths[$maxWidth] ?? $widths['lg'];
@endphp
<div
    x-data="{ open: false }"
    x-show="open"
    x-cloak
    @open-modal.window="if ($event.detail === '{{ $name }}') open = true"
    @close-modal.window="if ($event.detail === '{{ $name }}') open = false"
    @keydown.escape.window="open = false"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog" aria-modal="true" @if($title) aria-label="{{ $title }}" @endif
>
    {{-- scrim --}}
    <div x-show="open" x-transition.opacity.duration.200ms
         class="absolute inset-0 bg-ink/50" @click="open = false"></div>

    {{-- panel --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         x-trap.noscroll="open"
         {{ $attributes->merge(['class' => "relative w-full $w bg-surface rounded-xl shadow-popover overflow-hidden"]) }}>
        @if($title)
            <div class="flex items-center justify-between px-5 py-4 border-b border-line">
                <h2 class="text-base font-medium text-ink">{{ $title }}</h2>
                <button type="button" @click="open = false" aria-label="Затвори"
                        class="text-subtle hover:text-ink rounded-md p-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                    <x-icon name="x-mark" size="5" />
                </button>
            </div>
        @endif
        <div class="px-5 py-4 max-h-[70vh] overflow-y-auto" style="overscroll-behavior: contain;">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="px-5 py-4 border-t border-line bg-surface-subtle flex justify-end gap-2">{{ $footer }}</div>
        @endisset
    </div>
</div>
