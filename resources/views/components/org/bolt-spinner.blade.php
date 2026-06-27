@props(['size' => 22])
{{-- Анимираното лого на FlowAI: болтът се изчертава + три искри обикалят в
     орбита около него (без мигане). Движението живее в resources/css/app.css
     (.fa-bolt-trace / .fa-bolt-orbit) и зачита prefers-reduced-motion. --}}
<svg viewBox="-4 -4 32 32" width="{{ $size }}" height="{{ $size }}" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => 'shrink-0']) }}>
    <g class="fa-bolt-orbit">
        <circle cx="12" cy="-2" r="1.3" fill="var(--color-accent)"></circle>
        <circle cx="24.1" cy="19" r="1.3" fill="var(--color-accent)"></circle>
        <circle cx="-0.1" cy="19" r="1.3" fill="var(--color-accent)"></circle>
    </g>
    <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"
          stroke="var(--color-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          pathLength="100" class="fa-bolt-trace"></path>
</svg>
