{{-- Лещи над един и същ граф (§6): Екип / Карта на уменията / Текущ поток. Само вътрешна
     сегментация на изгледите на графа — глобалната навигация е в хедъра (§3.2). --}}
@php
    $lenses = [
        'roster' => ['label' => 'Екип', 'route' => 'client.org.roster'],
        'skill-tree' => ['label' => 'Карта на уменията', 'route' => 'client.org.skill-tree'],
        'live' => ['label' => 'Текущ поток', 'route' => 'client.org.live'],
    ];
@endphp
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <div class="inline-flex rounded-lg border border-line bg-surface p-0.5" role="tablist">
        @foreach ($lenses as $key => $lens)
            <a href="{{ route($lens['route']) }}"
               @if ($active === $key) aria-current="page" @endif
               class="px-4 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40
                      {{ $active === $key ? 'bg-primary text-primary-fg' : 'text-muted hover:text-ink' }}">
                {{ $lens['label'] }}
            </a>
        @endforeach
    </div>
    <a href="{{ route('client.org.design.review') }}" class="text-sm text-primary font-medium hover:text-primary-hover">Препроектирай →</a>
</div>
