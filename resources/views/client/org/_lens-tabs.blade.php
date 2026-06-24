{{-- Лещи над един и същ граф (§6): Екип / Skill Tree / Текущ поток. --}}
@php
    $lenses = [
        'roster' => ['label' => 'Екип', 'route' => 'client.org.roster'],
        'skill-tree' => ['label' => 'Skill Tree', 'route' => 'client.org.skill-tree'],
        'live' => ['label' => 'Текущ поток', 'route' => 'client.org.live'],
    ];
@endphp
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <div class="inline-flex rounded-lg border border-line bg-surface p-0.5">
        @foreach ($lenses as $key => $lens)
            <a href="{{ route($lens['route']) }}"
               class="px-4 py-1.5 text-sm font-medium rounded-md transition
                      {{ $active === $key ? 'bg-primary text-primary-fg' : 'text-muted hover:text-ink' }}">
                {{ $lens['label'] }}
            </a>
        @endforeach
    </div>
    <div class="flex items-center gap-4">
        <a href="{{ route('client.org.quests') }}" class="text-sm text-muted hover:text-ink">Куестове</a>
        <a href="{{ route('client.org.decisions') }}" class="text-sm text-muted hover:text-ink">Решения</a>
        <a href="{{ route('client.org.integrations') }}" class="text-sm text-muted hover:text-ink">Интеграции</a>
        <a href="{{ route('client.org.billing') }}" class="text-sm text-muted hover:text-ink">Кредити</a>
        <a href="{{ route('client.org.chronicle') }}" class="text-sm text-muted hover:text-ink">Хроника</a>
        <a href="{{ route('client.org.design.review') }}" class="text-sm text-primary font-medium hover:text-primary-hover">Препроектирай →</a>
    </div>
</div>
