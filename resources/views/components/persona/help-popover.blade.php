{{-- ⓘ помощ за персона-поле: насоки + примери от config('persona.fields').
     Самостоятелен x-data остров → работи и в x-for. Props: meta (масивът на полето). --}}
@props(['meta' => []])
<span class="relative inline-flex" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" :aria-expanded="open"
            class="inline-flex items-center justify-center text-subtle hover:text-primary transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
            aria-label="Помощ за полето">
        <x-icon name="question-mark-circle" size="4" />
    </button>
    <div x-show="open" x-cloak @click.outside="open = false"
         x-transition.origin.top.left
         class="absolute left-0 top-6 z-50 w-72 rounded-lg border border-line bg-surface p-3 shadow-popover text-left normal-case">
        @if (! empty($meta['label']))<p class="text-sm font-semibold text-ink mb-1 leading-relaxed">{{ $meta['label'] }}</p>@endif
        @if (! empty($meta['guidance']))<p class="text-sm text-muted leading-relaxed">{{ $meta['guidance'] }}</p>@endif
        @if (! empty($meta['examples']))
            <p class="text-[11px] font-medium text-subtle uppercase tracking-wide mt-2 mb-1">Примери</p>
            <ul class="space-y-0.5">
                @foreach ($meta['examples'] as $ex)
                    <li class="text-sm text-ink leading-relaxed">• {{ $ex }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</span>
