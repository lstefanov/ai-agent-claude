@props([
    'items' => [],
])
{{-- Each item: ['label' => , 'href' => ?]. Last item (no href) renders as current. --}}
<nav {{ $attributes->merge(['class' => 'flex items-center gap-1.5 text-sm text-muted']) }} aria-label="Breadcrumb">
    @foreach($items as $i => $item)
        @if(!$loop->first)
            <x-icon name="chevron-right" size="4" class="text-subtle" />
        @endif
        @if(!empty($item['href']) && !$loop->last)
            <a href="{{ $item['href'] }}" class="hover:text-ink transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">{{ $item['label'] }}</a>
        @else
            <span class="text-ink font-medium" aria-current="page">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
