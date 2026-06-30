{{-- Голяма персона-карта (Управител/Директор) — огледало на hero картата от design-review:
     портретен аватар (fallback цветни инициали), име/роля, Опит·Тон·Кратко био и пълни черти.
     $m = member card (OrgGraphService::memberCard); $role = длъжност за показване (по избор). --}}
@php
    $c = $m['color'] ?? 'blue';
    $stars = (int) ($m['stars'] ?? 1);
    $role = $role ?? ($m['role'] ?? '');
@endphp
<a href="{{ route('client.org.member', $m['id']) }}"
   aria-label="{{ $m['name'] }}, {{ $role }} · ниво {{ $m['tier'] }} — отвори профил"
   class="block rounded-xl border border-line bg-surface p-5 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
    <div class="flex items-start gap-4">
        @if (! empty($m['avatar_url']))
            <img src="{{ $m['avatar_url'] }}" alt="{{ $m['name'] }}" class="h-14 w-14 shrink-0 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
        @else
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-char-{{ $c }} text-lg font-semibold text-white">{{ $m['initial'] }}</span>
        @endif
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <p class="text-lg font-semibold text-ink truncate">{{ $m['name'] }}</p>
                <span class="shrink-0 text-xs tabular-nums" title="Ниво: {{ $m['tier'] }}" aria-hidden="true">
                    @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= $stars ? 'text-star' : 'text-subtle' }}">★</span>@endfor
                </span>
            </div>
            <p class="text-sm text-muted">{{ $role }}@if (! empty($m['age'])) · {{ $m['age'] }} г.@endif</p>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4 mt-4">
        <div>
            <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1">Опит</p>
            <p class="text-sm text-ink">{{ $m['background'] ?: '—' }}</p>
        </div>
        <div>
            <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1">Тон</p>
            <p class="text-sm text-ink">{{ $m['tone'] ?: '—' }}</p>
        </div>
        <div>
            <p class="text-[11px] font-mono uppercase tracking-wider text-subtle mb-1">Кратко био</p>
            <p class="text-sm text-ink">{{ $m['bio'] ?: '—' }}</p>
        </div>
    </div>

    @if (! empty($m['traits']))
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mt-4 pt-4 border-t border-line">
            @foreach (config('persona.traits') as $key => $tmeta)
                <div>
                    <div class="flex items-center justify-between text-[11px] text-muted mb-1">
                        <span>{{ $tmeta['label'] }}</span>
                        <span class="tabular-nums">{{ (int) ($m['traits'][$key] ?? 0) }}</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-line overflow-hidden">
                        <div class="h-full rounded-full bg-char-{{ $c }}" style="width: {{ (int) ($m['traits'][$key] ?? 0) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</a>
