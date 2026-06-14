@props([
    'tabs' => [],
])
{{-- Link tabs. Each item: ['label' => , 'href' => , 'active' => bool, 'icon' => ?]. --}}
<div {{ $attributes->merge(['class' => 'border-b border-line']) }}>
    <nav class="-mb-px flex gap-1 overflow-x-auto" aria-label="Tabs">
        @foreach($tabs as $tab)
            <a href="{{ $tab['href'] }}"
               @if($tab['active'] ?? false) aria-current="page" @endif
               class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 transition
                      {{ ($tab['active'] ?? false)
                         ? 'border-primary text-primary'
                         : 'border-transparent text-muted hover:text-ink hover:border-line-strong' }}
                      focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 rounded-t">
                @if(!empty($tab['icon']))<x-icon :name="$tab['icon']" size="4" />@endif
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
