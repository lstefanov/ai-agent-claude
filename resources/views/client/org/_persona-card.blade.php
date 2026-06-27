{{-- Персона карта (§2.5): портретен аватар + fallback цветни инициали, име/роля,
     звезди (= ModelLevel), мини стат-барове. $m = member card (от OrgGraphController). --}}
@php
    // Цвят = функция/домейн (§10.1), подаден от OrgGraphController — не member.id % 7.
    $c = $m['color'] ?? 'blue';
    $stars = (int) ($m['stars'] ?? 1);
@endphp
<a href="{{ route('client.org.member', $m['id']) }}"
   class="block rounded-xl border border-line bg-surface p-4 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
    <div class="flex items-start gap-3">
        @if (! empty($m['avatar_url']))
            <img src="{{ $m['avatar_url'] }}" alt="{{ $m['name'] }}" class="h-12 w-12 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
        @else
            {{-- Fallback цветни инициали докато аватарът е pending/failed --}}
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong font-semibold ring-2 ring-char-{{ $c }}-soft">
                {{ $m['initial'] }}</span>
        @endif
        <div class="min-w-0 flex-1">
            <div class="flex items-center justify-between gap-2">
                <p class="font-medium text-ink truncate">{{ $m['name'] }}</p>
                <span class="text-xs tabular-nums" title="Ниво: {{ $m['tier'] }}">
                    @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= $stars ? 'text-star' : 'text-subtle' }}">★</span>@endfor
                </span>
            </div>
            <p class="text-xs text-muted truncate">{{ $m['role'] }}@if (! empty($m['age'])) · {{ $m['age'] }}г.@endif</p>
            @if (! empty($m['tone']))
                <p class="text-xs text-subtle truncate mt-0.5"><x-prose :text="$m['tone']" inline /></p>
            @endif
        </div>
    </div>

    @if (! empty($m['traits']))
        <div class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1">
            @foreach (['risk' => 'Риск', 'creativity' => 'Креат.', 'precision' => 'Прец.', 'tempo' => 'Темпо'] as $k => $label)
                @if (isset($m['traits'][$k]))
                    <div>
                        <div class="flex justify-between text-[10px] text-muted"><span>{{ $label }}</span><span class="tabular-nums">{{ (int) $m['traits'][$k] }}</span></div>
                        <div class="h-1 rounded-full bg-surface-subtle overflow-hidden"><div class="h-full rounded-full bg-char-{{ $c }}" style="width: {{ (int) $m['traits'][$k] }}%"></div></div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- Натовареност (само за асистенти, когато се подаде $stats): брой flows + активни + статус --}}
    @if (! empty($stats))
        <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-line pt-2 text-[11px] text-muted">
            <span><span class="font-semibold text-ink tabular-nums">{{ $stats['flows_total'] }}</span> flows</span>
            @if ($stats['active'] > 0)
                <span class="inline-flex items-center gap-1 text-accent"><span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>{{ $stats['active'] }} активни</span>
            @endif
            @if ($stats['completed'] > 0)<span class="text-success-strong tabular-nums">{{ $stats['completed'] }} ✓</span>@endif
            @if ($stats['failed'] > 0)<span class="text-danger tabular-nums">{{ $stats['failed'] }} ✗</span>@endif
            @if (! empty($stats['last_run_at']))<span class="ml-auto text-subtle">{{ $stats['last_run_at']->diffForHumans() }}</span>@endif
        </div>
    @endif
</a>
