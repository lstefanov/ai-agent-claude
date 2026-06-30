{{-- Асистент карта — огледало на асистент картата от design-review (Опит·Тон·био + тънки
     черти с hover tooltip), плюс roster live-статистики. $m = member card; $stats = натовареност. --}}
@php
    $c = $m['color'] ?? 'blue';
    $stars = (int) ($m['stars'] ?? 1);
@endphp
<a href="{{ route('client.org.member', $m['id']) }}"
   aria-label="{{ $m['name'] }}, {{ $m['role'] }} · ниво {{ $m['tier'] }} — отвори профил"
   class="flex flex-col rounded-xl border border-line bg-surface p-4 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
    <div class="flex items-start gap-3">
        @if (! empty($m['avatar_url']))
            <img src="{{ $m['avatar_url'] }}" alt="{{ $m['name'] }}" class="h-10 w-10 shrink-0 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
        @else
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-char-{{ $c }} text-sm font-semibold text-white">{{ $m['initial'] }}</span>
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-ink truncate">{{ $m['name'] }}</p>
            <p class="text-xs text-muted truncate">{{ $m['role'] }}</p>
        </div>
        <span class="shrink-0 text-[11px] tabular-nums" title="Ниво: {{ $m['tier'] }}" aria-hidden="true">
            @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= $stars ? 'text-star' : 'text-subtle' }}">★</span>@endfor
        </span>
    </div>

    <div class="mt-3 space-y-1.5">
        <p class="text-xs"><span class="text-subtle">Опит:</span> <span class="text-ink">{{ $m['background'] ?: '—' }}</span></p>
        <p class="text-xs"><span class="text-subtle">Тон:</span> <span class="text-ink">{{ $m['tone'] ?: '—' }}</span></p>
        @if (! empty($m['bio']))<p class="text-xs text-muted line-clamp-3">{{ $m['bio'] }}</p>@endif
    </div>

    @if (! empty($m['traits']))
        {{-- Черти: тънки барове по реда риск/креативност/прецизност/автономност/темпо; tooltip на hover. --}}
        <div class="mt-3 flex items-center gap-2">
            <span class="text-[10px] font-mono uppercase tracking-wider text-subtle shrink-0">Черти</span>
            <div class="flex-1 flex items-center gap-1.5">
                @foreach (config('persona.traits') as $key => $tmeta)
                    <div class="group/trait relative flex-1">
                        <div class="h-1.5 rounded-full bg-line overflow-hidden cursor-default">
                            <div class="h-full rounded-full transition-all bg-char-{{ $c }}" style="width: {{ (int) ($m['traits'][$key] ?? 0) }}%"></div>
                        </div>
                        <div class="pointer-events-none absolute bottom-full left-1/2 z-30 mb-2 -translate-x-1/2 translate-y-1 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] leading-none opacity-0 shadow-lg transition duration-150 group-hover/trait:opacity-100 group-hover/trait:translate-y-0"
                             style="background: var(--color-ink); color: #ffffff;">
                            <span style="color: rgba(255,255,255,.92);">{{ $tmeta['label'] }}</span>
                            <span class="ml-1.5 font-bold tabular-nums" style="color:#ffffff;">{{ (int) ($m['traits'][$key] ?? 0) }}</span>
                            <span class="absolute left-1/2 top-full h-2 w-2 -translate-x-1/2 -translate-y-1/2 rotate-45" style="background: var(--color-ink);"></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Натовареност: брой flows + активни + статус (roster-специфично) --}}
    @if (! empty($stats))
        <div class="mt-auto pt-3 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-line text-[11px] text-muted">
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
