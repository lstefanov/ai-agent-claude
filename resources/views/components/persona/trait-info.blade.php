{{-- ⓘ описание на черта от config('persona.traits'). Server-rendered (чертите се
     рендират с @foreach), самостоятелен x-data остров. Prop: meta (масивът на чертата). --}}
@props(['meta' => []])
<span class="relative inline-flex" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" :aria-expanded="open"
            class="inline-flex items-center justify-center text-subtle hover:text-primary transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
            aria-label="Описание на чертата">
        <x-icon name="information-circle" size="3.5" />
    </button>
    <div x-show="open" x-cloak @click.outside="open = false"
         x-transition.origin.top.left
         class="absolute left-0 top-5 z-50 w-80 max-w-[80vw] rounded-lg border border-line bg-surface p-3 shadow-popover text-left normal-case space-y-1.5">
        <p class="text-sm font-semibold text-ink leading-relaxed">{{ $meta['label'] ?? '' }}</p>
        @if (! empty($meta['what']))<p class="text-sm text-muted leading-relaxed">{{ $meta['what'] }}</p>@endif
        @if (! empty($meta['controls']))<p class="text-sm text-muted leading-relaxed"><span class="text-ink font-medium">Контролира:</span> {{ $meta['controls'] }}</p>@endif
        <div class="space-y-1 pt-1.5 border-t border-line">
            @if (! empty($meta['low']))<p class="text-[13px] text-muted leading-relaxed"><span class="text-ink font-medium">Ниско:</span> {{ $meta['low'] }}</p>@endif
            @if (! empty($meta['high']))<p class="text-[13px] text-muted leading-relaxed"><span class="text-ink font-medium">Високо:</span> {{ $meta['high'] }}</p>@endif
            @if (! empty($meta['good']))<p class="text-[13px] text-success-strong leading-relaxed"><span class="font-medium">Добре:</span> {{ $meta['good'] }}</p>@endif
            @if (! empty($meta['bad']))<p class="text-[13px] text-danger leading-relaxed"><span class="font-medium">Внимавай:</span> {{ $meta['bad'] }}</p>@endif
            @if (! empty($meta['where']))<p class="text-[13px] text-subtle leading-relaxed"><span class="font-medium">Къде:</span> {{ $meta['where'] }}</p>@endif
        </div>
    </div>
</span>
