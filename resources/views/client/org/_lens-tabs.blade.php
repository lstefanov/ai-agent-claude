{{-- Лещи над един и същ граф (§6): Екип / Skill Tree. $active ∈ roster|skill-tree --}}
@php
    $lenses = [
        'roster' => ['label' => 'Екип', 'route' => 'client.org.roster'],
        'skill-tree' => ['label' => 'Skill Tree', 'route' => 'client.org.skill-tree'],
    ];
@endphp
<div class="flex items-center justify-between mb-6">
    <div class="inline-flex rounded-lg border border-line bg-surface p-0.5">
        @foreach ($lenses as $key => $lens)
            <a href="{{ route($lens['route']) }}"
               class="px-4 py-1.5 text-sm font-medium rounded-md transition
                      {{ $active === $key ? 'bg-primary text-primary-fg' : 'text-muted hover:text-ink' }}">
                {{ $lens['label'] }}
            </a>
        @endforeach
    </div>
    <a href="{{ route('client.org.design.review') }}" class="text-sm text-primary font-medium hover:text-primary-hover">Препроектирай →</a>
</div>
